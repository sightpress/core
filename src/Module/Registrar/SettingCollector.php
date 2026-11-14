<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Registrar\SettingRegistrar;
use SightPress\Module\Setting\Setting;

/**
 * Records a module's site settings.
 */
final readonly class SettingCollector implements SettingRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function add(Setting $setting): void
    {
        $this->registry->addSetting($this->module, $setting);
    }
}
