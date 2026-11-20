<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\ErrorHandler;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function restore_error_handler;
use function set_error_handler;
use function str_contains;

use const DIRECTORY_SEPARATOR;
use const E_DEPRECATED;
use const E_USER_DEPRECATED;

/**
 * Runs right after Stratigility's ErrorHandler, which turns every reported error into an ErrorException.
 * Deprecations raised inside vendor packages (on PHP 8.4: implicitly nullable parameters in endroid/qr-code,
 * thecodingmachine/safe, league/uri, salesmessage/streaming and others) are not ours to fix here and must not
 * fail the request. Any other error, and every deprecation raised by our own code, goes to the previous handler.
 *
 * Like the ErrorHandler, it pushes and pops the process-wide error handler per request. That is safe while a worker
 * serves one request at a time: mezzio-swoole's enable_coroutine is false (config/autoload/swoole.global.php).
 */
class VendorDeprecationsMiddleware implements MiddlewareInterface
{
    private const VENDOR_DIR = DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Read the current handler without changing it: set_error_handler() returns it, restore_error_handler() puts
        // it back
        $previous = set_error_handler(null);
        restore_error_handler();

        set_error_handler(static function (int $errno, string $msg, string $file = '', int $line = 0) use ($previous) {
            if (($errno & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0 && str_contains($file, self::VENDOR_DIR)) {
                return true;
            }

            return $previous !== null && (bool) $previous($errno, $msg, $file, $line);
        });

        try {
            return $handler->handle($request);
        } finally {
            restore_error_handler();
        }
    }
}
