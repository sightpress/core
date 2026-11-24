<?php

declare(strict_types=1);

namespace SightPress\Core\Admin;

use DateTimeZone;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Page\Contract\PublicScreenRendering;
use SightPress\Core\Template\Message as CoreMessage;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Template\ViewerPreferences;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Http\AdminScreen;
use SightPress\Module\Http\PublicScreen;
use SightPress\Module\Http\Screens;
use SightPress\Module\Seo\SeoEntry;
use SightPress\Module\Text\Message;

/**
 * Core's side of module-interface `Screens` (ADR-0059): a module's admin screen goes in the admin shell like core's
 * own, a module's public page goes in the site's theme with the menus and branding core's modules add, and its
 * `SeoEntry` becomes the page's description, canonical and alternate links and JSON-LD.
 */
final readonly class ModuleScreens implements Screens
{
    public function __construct(
        private AdminScreens $admin,
        private TemplateRenderer $templates,
        private Translations $translations,
        private EventDispatcherInterface $events,
        private ResponseFactoryInterface $responses,
        private TenantConfig $config,
        private Site $site,
    ) {
    }

    public function admin(ServerRequestInterface $request, AdminScreen $screen): ResponseInterface
    {
        $own = $request->getAttribute(ViewerPreferences::class);
        $locale = $own instanceof ViewerPreferences && $own->locale !== null && $own->locale !== '' ? $own->locale : $this->translations->defaultLocale();

        return $this->admin->render($request, '@admin/module-screen.html.twig', [
            'module_title' => $this->text($screen->title, $locale),
            'module_template' => $screen->template,
            'module_context' => $screen->context,
        ], $screen->status);
    }

    public function public(ServerRequestInterface $request, PublicScreen $screen): ResponseInterface
    {
        $seo = $screen->seo;
        $locale = $seo !== null && in_array($seo->locale, $this->site->locales, true) ? $seo->locale : $this->site->defaultLocale;
        $own = $request->getAttribute(ViewerPreferences::class);
        $timezone = $own instanceof ViewerPreferences && $own->timezone !== null && $own->timezone !== '' ? $own->timezone : $this->site->defaultTimezone;
        $request = $request->withAttribute(Viewer::class, new Viewer($locale, $timezone));
        $uri = $request->getUri();
        $base = $uri->getScheme() . '://' . $uri->getAuthority();
        $title = $this->text($screen->title, $locale);

        $rendering = new PublicScreenRendering($request, $locale, $uri->getPath(), $base, $title);
        // As for core's pages: nothing is indexed outside production; errors and pages that ask are not indexed.
        $robots = match (true) {
            !$this->config->environment->isProduction() => 'noindex, nofollow',
            $screen->noindex || ($seo === null && $screen->status >= 400) => 'noindex',
            default => null,
        };
        $rendering->addHead($this->head($seo, $base, $robots));
        $this->events->dispatch($rendering);

        $body = $this->templates->render($request, '@page/module-screen.html.twig', [
            ...$rendering->variables(),
            'head' => $rendering->head(),
            'title' => $title,
            'document_title' => $title . ' - ' . $this->site->name,
            'locale' => $locale,
            'site_name' => $this->site->name,
            'home_url' => $locale === $this->site->defaultLocale ? '/' : '/' . strtolower($locale),
            'module_template' => $screen->template,
            'module_context' => $screen->context,
        ]);

        $response = $this->responses->createResponse($screen->status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Language', $locale);
        if ($robots !== null) {
            $response = $response->withHeader('X-Robots-Tag', $robots);
        }
        $response->getBody()->write($body);

        return $response;
    }

    public function redirect(ServerRequestInterface $request, string $path, string|Message|null $notice = null): ResponseInterface
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new \InvalidArgumentException(sprintf('A module can only redirect to a path on the site, starting with a single "/" ("%s").', $path));
        }

        return $this->admin->redirect($request, $path, $notice instanceof Message ? self::message($notice) : $notice);
    }

    private function text(string|Message $text, string $locale): string
    {
        return $text instanceof Message ? $this->translations->translate(self::message($text), [], $locale, $text->domain) : $text;
    }

    private static function message(Message $message): CoreMessage
    {
        return new CoreMessage($message->text, $message->parameters);
    }

    /**
     * The head tags of a module page: robots, and with an SeoEntry its description, canonical address, other
     * languages, Markdown version and JSON-LD.
     */
    private function head(?SeoEntry $seo, string $base, ?string $robots): string
    {
        $tags = [];
        if ($robots !== null) {
            $tags[] = self::tag('meta', ['name' => 'robots', 'content' => $robots]);
        }
        if ($seo === null) {
            return implode("\n", $tags);
        }
        $canonical = $base . $seo->path;
        if ($seo->description !== null) {
            $tags[] = self::tag('meta', ['name' => 'description', 'content' => $seo->description]);
        }
        $tags[] = self::tag('link', ['rel' => 'canonical', 'href' => $canonical]);
        $alternates = [$seo->locale => $seo->path] + array_intersect_key($seo->alternates, array_flip($this->site->locales));
        if (count($alternates) > 1) {
            foreach ($alternates as $locale => $path) {
                $tags[] = self::tag('link', ['rel' => 'alternate', 'hreflang' => $locale, 'href' => $base . $path]);
            }
            if (isset($alternates[$this->site->defaultLocale])) {
                $tags[] = self::tag('link', ['rel' => 'alternate', 'hreflang' => 'x-default', 'href' => $base . $alternates[$this->site->defaultLocale]]);
            }
        }
        if ($seo->markdownPath !== null) {
            $tags[] = self::tag('link', ['rel' => 'alternate', 'type' => 'text/markdown', 'href' => $base . $seo->markdownPath]);
        }
        $page = array_filter([
            '@type' => 'WebPage',
            '@id' => $canonical . '#webpage',
            'url' => $canonical,
            'name' => $seo->title,
            'description' => $seo->description,
            'inLanguage' => $seo->locale,
            'isPartOf' => ['@id' => $base . '/#website'],
            'dateModified' => $seo->updatedAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ], static fn (mixed $value): bool => $value !== null);
        $tags[] = '<script type="application/ld+json">' . json_encode(
            ['@context' => 'https://schema.org', '@graph' => [$page, ...$seo->structuredData]],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
        ) . '</script>';

        return implode("\n", $tags);
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function tag(string $name, array $attributes): string
    {
        $html = '<' . $name;
        foreach ($attributes as $attribute => $value) {
            $html .= sprintf(' %s="%s"', $attribute, htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
        }

        return $html . '>';
    }
}
