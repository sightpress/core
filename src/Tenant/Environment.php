<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * Where the install runs, from the `APP_ENV` variable. Anything but production asks search engines not to index
 * pages.
 */
enum Environment: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Dev = 'dev';
    case Test = 'test';

    /**
     * An empty or missing value counts as production, so a forgotten variable never exposes debug output.
     */
    public static function fromValue(?string $value): self
    {
        $value = strtolower(trim($value ?? ''));

        if ($value === '' || $value === 'prod') {
            return self::Production;
        }

        return self::tryFrom($value) ?? throw new TenantConfigException(sprintf(
            'APP_ENV is not valid ("%s"): use production, staging, dev or test.',
            $value,
        ));
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
