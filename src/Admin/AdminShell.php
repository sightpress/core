<?php

declare(strict_types=1);

namespace SightPress\Core\Admin;

use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Template\ViewerPreferences;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Template\Translations;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Template\Message;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Navigation\AdminNavigation;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\Accounts;

final readonly class AdminShell implements AdminScreens
{
    public function __construct(
        private TemplateRenderer $templates,
        private ResponseFactoryInterface $responses,
        private AdminNavigation $navigation,
        private Accounts $accounts,
        private Site $site,
        private Translations $translations,
    ) {
    }

    /**
     * The admin panel is in the person's own language (the closest one it is translated into), otherwise in the
     * tenant's admin language, en-GB by default (ADR-0007): not in the site's.
     */
    public function viewer(ServerRequestInterface $request): Viewer
    {
        $own = $request->getAttribute(ViewerPreferences::class);
        $locale = $own instanceof ViewerPreferences ? ($own->locale ?? '') : '';
        $timezone = $own instanceof ViewerPreferences ? ($own->timezone ?? '') : '';

        $default = $this->translations->defaultLocale();

        return new Viewer($locale !== '' ? $locale : ($default !== '' ? $default : Translations::SOURCE_LOCALE), $timezone === '' ? $this->site->defaultTimezone : $timezone);
    }

    public function render(ServerRequestInterface $request, string $template, array $context = [], int $status = 200): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        $form = $context['form'] ?? null;
        $account = $actor->userId === null ? null : $this->accounts->findAccount($actor->userId);
        $viewer = $this->viewer($request);
        $request = $request->withAttribute(Viewer::class, $viewer);

        return $this->templates
            ->response($request, $template, $context + [
                'site_name' => $this->site->name,
                'messages' => Flash::take(Session::fromRequest($request)),
                'admin_nav' => $this->navigation->for($actor->permissions(), $request->getUri()->getPath()),
                'admin_user_name' => $account?->effectiveName(),
                'error_count' => $form instanceof Form ? count($form->errors()) : 0,
                'admin_languages' => $this->languages($viewer->locale),
                'admin_language' => $this->translations->catalogueLocale($viewer->locale),
            ], $status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function redirect(ServerRequestInterface $request, string $path, string|Message|null $message = null): ResponseInterface
    {
        if ($message !== null) {
            Flash::add(Session::fromRequest($request), $message);
        }

        return $this->responses->createResponse(303)
            ->withHeader('Location', $path)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The languages the admin panel is translated into, each named in its own language, for the switcher.
     *
     * @return list<array{code: string, name: string}>
     */
    private function languages(string $current): array
    {
        return array_map(static fn (string $locale): array => ['code' => $locale, 'name' => LocaleCode::ownName($locale)], $this->translations->locales());
    }
}
