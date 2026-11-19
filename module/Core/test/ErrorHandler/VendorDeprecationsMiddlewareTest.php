<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\ErrorHandler;

use ErrorException;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Shlinkio\Shlink\Core\ErrorHandler\VendorDeprecationsMiddleware;

use function restore_error_handler;
use function set_error_handler;

use const E_DEPRECATED;
use const E_USER_DEPRECATED;
use const E_WARNING;

class VendorDeprecationsMiddlewareTest extends TestCase
{
    private const VENDOR_FILE = '/etc/shlink/vendor/endroid/qr-code/src/QrCode.php';
    private const OWN_FILE = '/etc/shlink/module/Core/src/ShortUrl/Entity/ShortUrl.php';

    private VendorDeprecationsMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new VendorDeprecationsMiddleware();
        // Same behaviour as Stratigility's ErrorHandler, which runs before this middleware
        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    /**
     * @test
     * @dataProvider provideVendorDeprecations
     */
    public function vendorDeprecationsAreSilenced(int $errno): void
    {
        $response = $this->middleware->process(
            ServerRequestFactory::fromGlobals(),
            $this->handlerRaising($errno, self::VENDOR_FILE),
        );

        self::assertEquals(200, $response->getStatusCode());
    }

    public static function provideVendorDeprecations(): iterable
    {
        yield 'E_DEPRECATED' => [E_DEPRECATED];
        yield 'E_USER_DEPRECATED' => [E_USER_DEPRECATED];
    }

    /**
     * @test
     * @dataProvider provideErrorsForPreviousHandler
     */
    public function otherErrorsReachThePreviousHandler(int $errno, string $file): void
    {
        $this->expectException(ErrorException::class);
        $this->middleware->process(ServerRequestFactory::fromGlobals(), $this->handlerRaising($errno, $file));
    }

    public static function provideErrorsForPreviousHandler(): iterable
    {
        yield 'deprecation in own code' => [E_DEPRECATED, self::OWN_FILE];
        yield 'warning in vendor' => [E_WARNING, self::VENDOR_FILE];
    }

    /** @test */
    public function previousHandlerIsRestoredAfterTheRequest(): void
    {
        $this->middleware->process(ServerRequestFactory::fromGlobals(), $this->handlerRaising(null, ''));

        $this->expectException(ErrorException::class);
        $this->invokeCurrentErrorHandler(E_DEPRECATED, self::VENDOR_FILE);
    }

    /** @test */
    public function previousHandlerIsRestoredWhenTheRequestFails(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new RuntimeException('boom'));

        try {
            $this->middleware->process(ServerRequestFactory::fromGlobals(), $handler);
            self::fail('The exception of the request was expected');
        } catch (RuntimeException) {
        }

        $this->expectException(ErrorException::class);
        $this->invokeCurrentErrorHandler(E_DEPRECATED, self::VENDOR_FILE);
    }

    private function handlerRaising(?int $errno, string $file): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $_) use ($errno, $file): ResponseInterface {
                if ($errno !== null) {
                    $this->invokeCurrentErrorHandler($errno, $file);
                }

                return new Response();
            },
        );

        return $handler;
    }

    /**
     * trigger_error() always reports the file it is called from, so the handler is called directly with the file
     * an error raised inside a vendor package would have.
     */
    private function invokeCurrentErrorHandler(int $errno, string $file): void
    {
        $current = set_error_handler(null);
        restore_error_handler();

        self::assertNotNull($current);
        $current($errno, 'Deprecated', $file, 1);
    }
}
