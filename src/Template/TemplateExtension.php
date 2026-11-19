<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use DateTimeInterface;
use SightPress\Core\Http\Csrf\CsrfTokens;
use SightPress\Core\Http\CurrentRequest;
use SightPress\Core\Http\Security\CspNonce;
use SightPress\Core\Http\Security\GrantedPermissions;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Tenant\SiteContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Core's template functions and filters:
 *
 * - `asset(path)`: URL of a theme file;
 * - `t(message, parameters)`: the message (a text or a {@see Message}) in the viewer's language;
 * - `can(permission)`: whether the viewer holds a permission;
 * - `csp_nonce()`: the nonce every inline `<script>` and `<style>` needs;
 * - `csrf_fields()`: the hidden CSRF fields every `POST` form needs;
 * - `current_locale()`: the viewer's language, for example `en-GB`;
 * - `current_path()`: the path and query of the page being shown;
 * - `text_direction(locale)`: `rtl` for right-to-left scripts, otherwise `ltr`;
 * - `module_enabled(id)`: whether a module is on for the site being shown (ADR-0034);
 * - `value|user_datetime(style)` and `value|user_date(style)`: a UTC date in the viewer's time zone and language.
 */
final class TemplateExtension extends AbstractExtension
{
    public function __construct(
        private readonly CurrentRequest $request,
        private readonly AssetUrls $assets,
        private readonly Translations $translations,
        private readonly DateFormatter $dates,
        private readonly Viewer $defaultViewer,
        private readonly ?ModuleRegistry $modules = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('asset', $this->assets->url(...)),
            new TwigFunction('t', $this->translate(...)),
            new TwigFunction('can', $this->can(...)),
            new TwigFunction('csp_nonce', $this->cspNonce(...)),
            new TwigFunction('csrf_fields', $this->csrfFields(...), ['is_safe' => ['html']]),
            new TwigFunction('current_locale', $this->currentLocale(...)),
            new TwigFunction('text_direction', TextDirection::of(...)),
            new TwigFunction('current_path', $this->currentPath(...)),
            new TwigFunction('module_enabled', $this->moduleEnabled(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('user_datetime', $this->userDateTime(...)),
            new TwigFilter('user_date', $this->userDate(...)),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function translate(Message|LanguageName|string|null $message, array $parameters = [], string $domain = 'messages'): string
    {
        if ($message === null || $message === '') {
            return '';
        }

        return $this->translations->translate($message, $parameters, $this->viewer()->locale, $domain);
    }

    public function can(string $permission): bool
    {
        return GrantedPermissions::fromRequest($this->request->get())->has($permission);
    }

    public function cspNonce(): string
    {
        return CspNonce::fromRequest($this->request->get())->value;
    }

    /**
     * Two hidden inputs; every value is escaped here because the function's output is marked safe.
     */
    public function csrfFields(): string
    {
        $token = CsrfTokens::fromRequest($this->request->get())->token();
        $input = static fn (string $name, string $value): string => sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
        );

        return $input($token->nameKey, $token->name) . $input($token->valueKey, $token->value);
    }

    /**
     * The path and query of the page being shown, for forms that bring people back to it.
     */
    public function currentPath(): string
    {
        $uri = $this->request->get()->getUri();

        return $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());
    }

    /**
     * Whether a module is on for the site of the request being handled; without a site, whether the tenant loaded it.
     */
    public function moduleEnabled(string $module): bool
    {
        $request = $this->request->find();
        $site = $request === null ? null : SiteContext::fromRequest($request);
        if ($site !== null) {
            return Services::get($site->container, ModuleRegistry::class)->has($module);
        }

        return $this->modules?->has($module) ?? false;
    }

    public function currentLocale(): string
    {
        return $this->viewer()->locale;
    }

    public function userDateTime(?DateTimeInterface $value, string $style = 'medium'): string
    {
        return $this->dates->dateTime($value, $this->viewer(), $style);
    }

    public function userDate(?DateTimeInterface $value, string $style = 'long'): string
    {
        return $this->dates->date($value, $this->viewer(), $style);
    }

    private function viewer(): Viewer
    {
        return Viewer::fromRequest($this->request->get(), $this->defaultViewer);
    }
}
