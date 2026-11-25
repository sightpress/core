<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use SightPress\Core\Sms\Command\DisableSmsCommand;
use SightPress\Core\Sms\Command\EnableSmsCommand;
use SightPress\Core\Sms\Command\RemoveSmsCommand;
use SightPress\Core\Sms\Command\SetSmsCommand;
use SightPress\Core\Sms\Command\TestSmsCommand;
use SightPress\Core\Sms\Http\SmsHttp;
use SightPress\Core\Sms\Http\SmsWebhookHandler;
use SightPress\Core\Sms\Provider\TwilioProvider;
use SightPress\Core\Sms\Provider\VonageProvider;
use SightPress\Core\Tenant\ModuleTurnOnCheck;
use SightPress\Module\Access\Permission;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * Text messages (N-2, ADR-0069, D28–D32): optional and off by default, because it sends phone numbers to a third
 * party. The operator sets up a provider for a site (`site:sms:set`, or the tenant's default in `sms`), then turns
 * text messages on (`site:sms:enable`, which refuses without a provider). Core's providers are Twilio and Vonage;
 * modules can add others through `sms()`. {@see SmsSender} sends and checks numbers within the limits and the budget;
 * the provider's signed webhook (`/_sms/webhook`) reports deliveries and STOP replies.
 */
final class SmsModule implements ModuleInterface
{
    public const string ID = 'sms';

    public const string MANAGE = 'sms.manage';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [new ModuleRequirement('site', '^0.1'), new ModuleRequirement('rbac', '^0.1'), new ModuleRequirement('admin', '^0.1'), new ModuleRequirement('user', '^0.1')],
            enabledByDefault: false,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new SmsMigrations());

        $services = $registrar->services();
        foreach ([SmsHttp::class, SmsSettings::class, SmsLog::class, SmsOptOuts::class, SmsReports::class, SmsNumberHash::class] as $class) {
            $services->autowire($class);
        }
        foreach ([SmsSetup::class, SmsSender::class] as $class) {
            $services->autowire($class, public: true);
        }
        $services->autowire(SmsSetup::class, ModuleTurnOnCheck::PREFIX . self::ID);

        $registrar->permissions()->add(new Permission(self::MANAGE, 'Manage text messages', 'See the text messages the site sent and its monthly budget, and send a test message.', ['admin']));

        $sms = $registrar->sms();
        foreach ([TwilioProvider::class, VonageProvider::class] as $provider) {
            $services->autowire($provider);
            $sms->addProvider($provider);
        }

        $services->autowire(SmsWebhookHandler::class);
        $registrar->routes()->add(new Route('sms.webhook', SmsWebhookHandler::PATH, SmsWebhookHandler::class, ['POST']));

        foreach ([SetSmsCommand::class, RemoveSmsCommand::class, EnableSmsCommand::class, DisableSmsCommand::class, TestSmsCommand::class] as $command) {
            $registrar->commands()->add($command);
        }
    }
}
