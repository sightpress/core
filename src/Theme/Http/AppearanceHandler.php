<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Page\Contract\PageLayouts;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Theme\PublishedPreviews;
use SightPress\Core\Theme\SiteTheme;
use SightPress\Core\Theme\ThemeApplier;
use SightPress\Core\Theme\ThemeException;
use SightPress\Core\Theme\ThemeRegistry;
use SightPress\Theme\ThemeInterface;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/appearance`: the themes installed on the server, each under its own heading with its description, version,
 * whether it is the site's theme or why it can't be used (in words), the screenshot of its preview page with the
 * theme's own description as alt text (or the description as text when no screenshot was made), a link to the preview
 * (a new tab) and a "Use …" button. Applying re-checks the theme ({@see ThemeApplier}). When pages use a layout the
 * theme doesn't have, a page lists them first and asks to confirm: they are shown in the standard layout until the
 * site switches back (T-3). Needs `theme.apply`.
 */
final readonly class AppearanceHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/appearance';

    public const string PERMISSION = 'theme.apply';

    public function __construct(
        private AdminScreens $screens,
        private ThemeRegistry $registry,
        private SiteTheme $site,
        private ThemeApplier $applier,
        private TenantConfig $config,
        private PublishedPreviews $previews,
        private PageLayouts $layouts,
    ) {
    }

    public static function anchor(string $id): string
    {
        return 'theme-' . $id;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(self::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }

        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $id = is_string($body['theme'] ?? null) ? $body['theme'] : '';
            $theme = $this->registry->find($id);
            $affected = $theme === null ? [] : $this->layouts->pagesNotIn(array_map(static fn ($layout): string => $layout->id, $theme->manifest()->layouts));
            if ($affected !== [] && !is_string($body['confirm'] ?? null) && $theme !== null) {
                return $this->screens->render($request, '@theme/switch.html.twig', [
                    'theme' => $theme->manifest(),
                    'pages' => $affected,
                    'action' => self::PATH,
                    'back' => self::PATH . '#' . self::anchor($id),
                ]);
            }
            try {
                $check = $this->applier->apply($id, $actor->userId);
            } catch (ThemeException $exception) {
                return $this->screens->redirect($request, self::PATH, new Message('The theme can’t be used: {reason}', ['reason' => $exception->getMessage()]));
            }

            return $this->screens->redirect($request, self::PATH . '#' . self::anchor($id), new Message('{name} is now the site’s theme.', ['name' => $check->name]));
        }

        $current = $this->site->theme()->manifest()->id;

        return $this->screens->render($request, '@theme/appearance.html.twig', [
            'themes' => array_map(fn (ThemeInterface $theme): array => $this->item($theme, $current), $this->registry->all()),
            'problem' => $this->site->problem(),
            'action' => self::PATH,
            'colours' => ThemeColoursHandler::PATH,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(ThemeInterface $theme, string $current): array
    {
        $manifest = $theme->manifest();
        $unavailable = $this->registry->unavailable($theme);

        return [
            'manifest' => $manifest,
            'anchor' => self::anchor($manifest->id),
            'current' => $manifest->id === $current,
            'unavailable' => $unavailable,
            'allowed' => $this->config->themes->allows($manifest->id),
            'screenshot' => $this->previews->url($manifest, 'screenshot.png'),
            'preview' => $this->previews->url($manifest, 'index.html'),
        ];
    }
}
