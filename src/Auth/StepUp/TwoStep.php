<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\StepUp;

use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Infrastructure\Sms\TextMessages;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tenant\TwoStepPolicy;
use SightPress\Module\Event\SettingsChanged;

/**
 * Whether the site asks for a code by text before important changes (N-6, ADR-0073, D33): only while text messages
 * are on, and then as the tenant's `two_step` says: always (`required`), never (`off`), or as the site's admins chose
 * on *Site settings → Security* (`optional`, off until they turn it on).
 */
final readonly class TwoStep
{
    public const string SETTING = 'security.two_step';

    public function __construct(
        private TenantConfig $config,
        private SiteSettings $settings,
        private TextMessages $texts,
        private EventDispatcherInterface $events,
    ) {
    }

    public function isOn(): bool
    {
        return $this->texts->isOn() && match ($this->config->twoStep) {
            TwoStepPolicy::Required => true,
            TwoStepPolicy::Off => false,
            TwoStepPolicy::Optional => $this->siteChoice(),
        };
    }

    public function policy(): TwoStepPolicy
    {
        return $this->config->twoStep;
    }

    /**
     * Whether text messages are on, so the setting means something.
     */
    public function textsOn(): bool
    {
        return $this->texts->isOn();
    }

    /**
     * What the site's admins chose (only used while the tenant leaves it to them).
     */
    public function siteChoice(): bool
    {
        return $this->settings->get(self::SETTING) === 'on';
    }

    /**
     * @return bool whether it changed
     */
    public function choose(bool $on, ?int $actorId): bool
    {
        if ($this->siteChoice() === $on) {
            return false;
        }
        $this->settings->set(self::SETTING, $on ? 'on' : 'off');
        $this->events->dispatch(new SettingsChanged([self::SETTING], $actorId));

        return true;
    }
}
