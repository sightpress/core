<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Settings;

use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Module\Exception\InvalidValueException;
use SightPress\Module\Setting\SettingType;
use SightPress\Module\Setting\SettingValues;

/**
 * Core's side of module-interface `SettingValues` (ADR-0059): a registered setting's saved value, typed by its
 * `SettingType`, or its default.
 */
final readonly class SiteSettingValues implements SettingValues
{
    public function __construct(
        private SettingCatalogue $catalogue,
        private SiteSettings $settings,
    ) {
    }

    public function get(string $key): string|int|bool|null
    {
        $setting = $this->catalogue->get($key) ?? throw new InvalidValueException(sprintf('No module registered the setting "%s".', $key));
        $value = $this->settings->get($key);
        if ($value === null) {
            return $setting->default;
        }

        return match ($setting->type) {
            SettingType::Integer => (int) $value,
            SettingType::Boolean => $value === '1',
            default => $value,
        };
    }
}
