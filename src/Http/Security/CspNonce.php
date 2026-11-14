<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Security;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Content Security Policy nonce of one response. Every inline `<script>` and `<style>` a page needs carries it
 * (`nonce="…"`, from the `csp_nonce()` template function); anything without it is blocked.
 */
final readonly class CspNonce
{
    public function __construct(public string $value)
    {
    }

    public static function generate(): self
    {
        return new self(base64_encode(random_bytes(18)));
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $nonce = $request->getAttribute(self::class);
        if (!$nonce instanceof self) {
            throw new LogicException('There is no CSP nonce: the security headers middleware must run before this code.');
        }

        return $nonce;
    }
}
