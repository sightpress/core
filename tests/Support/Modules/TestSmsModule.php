<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;

/**
 * A package that adds a pretend text message service ({@see TestSmsProvider}), so the accessibility test site can
 * use phone numbers, text notifications and phone sign-in without a provider account or a network.
 */
final class TestSmsModule implements ModuleInterface
{
    public const string ID = 'acme.test_sms';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::ID, '1.0.0', enabledByDefault: true);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->services()->autowire(TestSmsProvider::class);
        $registrar->sms()->addProvider(TestSmsProvider::class);
    }
}
