<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * The tenant's `two_step` setting (N-6, D33): whether sites' admins may turn two-step confirmation on (`optional`, the
 * default), every site has it while text messages are on (`required`), or no site may (`off`, the setting is hidden).
 */
enum TwoStepPolicy: string
{
    case Optional = 'optional';
    case Required = 'required';
    case Off = 'off';

    /**
     * @throws TenantConfigException
     */
    public static function fromValue(mixed $value): self
    {
        $policy = is_string($value) ? self::tryFrom($value) : ($value === null ? self::Optional : null);

        return $policy ?? throw new TenantConfigException('Tenant setting "two_step" must be \'optional\' (sites choose), \'required\' (every site with text messages) or \'off\' (no site).');
    }
}
