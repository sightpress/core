<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Settings;

use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Setting\Setting;

/**
 * Every setting the modules that are on registered, with the module that owns it, in the order they registered.
 * The admin's site settings screen shows a field for each one.
 */
final readonly class SettingCatalogue
{
    /**
     * @param list<array{module: string, setting: Setting}> $settings
     */
    public function __construct(private array $settings = [])
    {
    }

    public static function fromRegistry(ModuleRegistry $registry): self
    {
        return new self(array_map(
            static fn ($registered): array => ['module' => $registered->module, 'setting' => $registered->item],
            $registry->settings(),
        ));
    }

    /**
     * @return list<array{module: string, setting: Setting}>
     */
    public function all(): array
    {
        return $this->settings;
    }

    public function get(string $key): ?Setting
    {
        foreach ($this->settings as $entry) {
            if ($entry['setting']->key === $key) {
                return $entry['setting'];
            }
        }

        return null;
    }

    /**
     * The value a site has for a setting: what it stored, or the setting's default, as text (`1` and `0` for
     * on and off).
     */
    public static function stored(Setting $setting, ?string $value): string
    {
        if ($value !== null) {
            return $value;
        }

        return match (true) {
            is_bool($setting->default) => $setting->default ? '1' : '0',
            $setting->default === null => '',
            default => (string) $setting->default,
        };
    }
}
