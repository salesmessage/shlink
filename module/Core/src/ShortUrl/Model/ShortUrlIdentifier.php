<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\ShortUrl\Model;

use Psr\Http\Message\ServerRequestInterface;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;

use function is_string;
use function sprintf;

final readonly class ShortUrlIdentifier
{
    private function __construct(public string $shortCode, public string|null $domain = null) {}

    public static function fromApiRequest(ServerRequestInterface $request): self
    {
        $shortCode = $request->getAttribute('shortCode', '');
        $domain = $request->getQueryParams()['domain'] ?? null;

        return new self($shortCode, $domain);
    }

    public static function fromRedirectRequest(ServerRequestInterface $request): self
    {
        $shortCode = $request->getAttribute('shortCode', '');
        // micro-shortener-proxy serves custom domains through the default one, passing the original as smg_domain
        $query = $request->getQueryParams();
        $domain = isset($query['smg_domain']) && is_string($query['smg_domain'])
            ? $query['smg_domain']
            : $request->getUri()->getAuthority();

        return new self($shortCode, $domain);
    }

    public static function fromShortUrl(ShortUrl $shortUrl): self
    {
        $domain = $shortUrl->domain?->authority;
        return new self($shortUrl->shortCode, $domain);
    }

    public static function fromShortCodeAndDomain(string $shortCode, string|null $domain = null): self
    {
        return new self($shortCode, $domain);
    }

    public function __toString(): string
    {
        if ($this->domain === null) {
            return $this->shortCode;
        }

        return sprintf('%s/%s', $this->domain, $this->shortCode);
    }
}
