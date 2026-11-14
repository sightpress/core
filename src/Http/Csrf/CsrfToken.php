<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Csrf;

/**
 * The two hidden fields a form sends to prove it came from this site: `<nameKey>=<name>` and `<valueKey>=<value>`.
 */
final readonly class CsrfToken
{
    public function __construct(
        public string $nameKey,
        public string $name,
        public string $valueKey,
        public string $value,
    ) {
    }
}
