<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Event\ThemeApplied;

/**
 * Applies a theme to the site: it must be installed, usable with this SightPress, allowed by the tenant and pass
 * every check of the {@see ThemeChecker} (contrast is validated again on every apply). Then the site's setting changes
 * and {@see ThemeApplied} is dispatched for the audit log.
 */
final readonly class ThemeApplier
{
    public function __construct(
        private ThemeRegistry $registry,
        private TenantConfig $config,
        private SiteSettings $settings,
        private ThemeChecker $checker,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param int|null $actorId null when it comes from the command line
     *
     * @throws ThemeException naming what stops the theme from being applied
     */
    public function apply(string $id, ?int $actorId = null): ThemeCheck
    {
        $theme = $this->registry->find($id) ?? throw new ThemeException(sprintf(
            'The theme "%s" is not installed. Installed themes: %s.',
            $id,
            implode(', ', array_map(static fn ($theme): string => $theme->manifest()->id, $this->registry->all())),
        ));
        if (!$this->config->themes->allows($id)) {
            throw new ThemeException(sprintf('The theme "%s" is not allowed on this server (tenant setting "themes.allowed").', $id));
        }
        $unavailable = $this->registry->unavailable($theme);
        if ($unavailable !== null) {
            throw new ThemeException(sprintf('The theme "%s" can\'t be used: %s', $id, $unavailable));
        }
        $check = $this->checker->check($theme);
        if (!$check->passes()) {
            throw new ThemeException(sprintf('The theme "%s" can\'t be applied: %s', $id, implode(' ', $check->problems)));
        }

        $previous = $this->settings->get(SiteTheme::SETTING);
        $this->settings->set(SiteTheme::SETTING, $id);
        $this->events->dispatch(new ThemeApplied($theme->manifest()->id, $theme->manifest()->name, $previous === '' ? null : $previous, $actorId));

        return $check;
    }
}
