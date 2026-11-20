<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use Psr\Log\LoggerInterface;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Theme\Contract\ThemeStatus;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Theme\ThemeInterface;
use Throwable;

/**
 * The theme a site is shown in: the one its admins chose (site setting `theme`), or the tenant's default theme for new
 * sites. When that theme isn't installed, can't be used with this SightPress, or the tenant no longer allows it, the
 * site falls back to the default theme, the reason is logged as a warning, and admins see it ({@see problem()}).
 */
final class SiteTheme implements ThemeStatus
{
    /** The site setting that holds the chosen theme's id. */
    public const string SETTING = 'theme';

    private ?ThemeInterface $theme = null;

    private ?Message $problem = null;

    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly TenantConfig $config,
        private readonly SiteSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The id of the theme the site's admins chose, or the tenant's default for new sites.
     */
    public function chosen(): string
    {
        try {
            $id = trim($this->settings->get(self::SETTING) ?? '');
        } catch (Throwable) {
            // The site's database isn't ready yet (while the site is being created).
            $id = '';
        }

        return $id === '' ? $this->config->themes->default : $id;
    }

    /**
     * The theme the site is shown in.
     */
    public function theme(): ThemeInterface
    {
        if ($this->theme !== null) {
            return $this->theme;
        }
        $id = $this->chosen();
        $theme = $this->registry->find($id);
        $reason = match (true) {
            $theme === null => new Message('The theme {theme} is not installed on the server any more.', ['theme' => $id]),
            !$this->config->themes->allows($id) => new Message('The theme {theme} is not allowed on this server any more.', ['theme' => $id]),
            $this->registry->unavailable($theme) !== null => new Message('The theme {theme} doesn’t work with this version of SightPress.', ['theme' => $id]),
            default => null,
        };
        if ($reason !== null || $theme === null) {
            $this->problem = $reason;
            $this->logger->warning('The site falls back to the default theme: {reason}', [
                'reason' => (string) $reason,
                'detail' => $theme === null ? null : $this->registry->unavailable($theme),
                'theme' => $id,
            ]);
            $theme = $this->registry->default();
        }

        return $this->theme = $theme;
    }

    public function problem(): ?Message
    {
        $this->theme();

        return $this->problem;
    }

    /**
     * The version in the addresses of the site's static files (`asset()`): core's version and the theme's id and
     * version, so browsers fetch the files again after an update or when the site changes theme.
     *
     * @param string|null $core core's version (Composer's reference of `sightpress/core`)
     */
    public function assetVersion(?string $core): string
    {
        $manifest = $this->theme()->manifest();

        return substr(hash('sha256', ($core ?? '') . '|' . $manifest->id . '|' . $manifest->version), 0, 12);
    }

    /**
     * Where the site's templates are: its theme, then the default theme package (for templates the theme leaves out),
     * then core's views.
     */
    public function templatePaths(): TemplatePaths
    {
        return new TemplatePaths(
            array_values(array_unique([$this->theme()->paths()->root, TemplatePaths::defaultTheme()])),
            TemplatePaths::core(),
        );
    }
}
