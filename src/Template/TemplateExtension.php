<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use DateTimeInterface;
use SightPress\Core\Http\CurrentRequest;
use SightPress\Core\Http\Security\CspNonce;
use SightPress\Core\Http\Security\GrantedPermissions;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Core's template functions and filters:
 *
 * - `asset(path)`: URL of a theme file;
 * - `t(message, parameters)`: the message in the viewer's language;
 * - `can(permission)`: whether the viewer holds a permission;
 * - `csp_nonce()`: the nonce every inline `<script>` and `<style>` needs;
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
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('asset', $this->assets->url(...)),
            new TwigFunction('t', $this->translate(...)),
            new TwigFunction('can', $this->can(...)),
            new TwigFunction('csp_nonce', $this->cspNonce(...)),
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
    public function translate(string $message, array $parameters = [], string $domain = 'messages'): string
    {
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
