<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\ShortUrl\Model;

use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\Core\ShortUrl\Model\ShortUrlIdentifier;

class ShortUrlIdentifierTest extends TestCase
{
    /**
     * micro-shortener-proxy passes the domain the visitor opened as smg_domain
     */
    #[Test]
    #[TestWith([['smg_domain' => 'go.example.com'], 'go.example.com'])]
    #[TestWith([[], 's.salesmessage.com'])]
    #[TestWith([['domain' => 'go.example.com'], 's.salesmessage.com'])]
    #[TestWith([['smg_domain' => ['go.example.com']], 's.salesmessage.com'])]
    public function redirectRequestIsResolvedOnTheDomainOfSmgDomain(array $query, string $expectedDomain): void
    {
        $request = ServerRequestFactory::fromGlobals()
            ->withUri(new Uri('https://s.salesmessage.com/abc123'))
            ->withQueryParams($query)
            ->withAttribute('shortCode', 'abc123');

        $identifier = ShortUrlIdentifier::fromRedirectRequest($request);

        self::assertSame('abc123', $identifier->shortCode);
        self::assertSame($expectedDomain, $identifier->domain);
    }

    #[Test]
    public function apiRequestDoesNotReadSmgDomain(): void
    {
        $request = ServerRequestFactory::fromGlobals()
            ->withQueryParams(['domain' => 'example.com', 'smg_domain' => 'go.example.com'])
            ->withAttribute('shortCode', 'abc123');

        self::assertSame('example.com', ShortUrlIdentifier::fromApiRequest($request)->domain);
    }
}
