<?php

declare(strict_types=1);

namespace SightPress\Core\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Site\Contract\SiteLanguageException;
use SightPress\Core\Site\Contract\SiteLanguages;
use SightPress\Core\Tenant\LocaleCode;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * `/admin/settings/languages/remove?locale=pt-PT`: asks whether to remove a language, saying what happens (its
 * translations are kept but nobody can reach them, adding it again brings them back, people who chose it get the
 * main language), and removes it when confirmed. The main language can't be removed. Needs `settings.manage`.
 */
final readonly class RemoveLanguageHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/settings/languages/remove';

    public function __construct(
        private AdminScreens $screens,
        private SiteLanguages $languages,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(SettingsHandler::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $body = $request->getParsedBody();
        $locale = $request->getMethod() === 'POST' && is_array($body) ? ($body['locale'] ?? null) : ($request->getQueryParams()['locale'] ?? null);
        if (!is_string($locale) || !$this->languages->has($locale)) {
            throw new HttpNotFoundException($request, 'The site isn’t written in that language.');
        }
        try {
            $this->languages->checkRemovable($locale);
        } catch (SiteLanguageException $exception) {
            return $this->screens->redirect($request, LanguagesHandler::PATH, $exception->getMessage());
        }
        if ($request->getMethod() === 'POST') {
            $this->languages->remove($locale, $actor->userId);

            return $this->screens->redirect($request, LanguagesHandler::PATH, sprintf('Removed %s. Its translations are kept: add the language again to bring them back.', LocaleCode::describe($locale)));
        }

        return $this->screens->render($request, '@admin/remove-language.html.twig', [
            'locale' => $locale,
            'name' => LocaleCode::describe($locale),
            'main' => LocaleCode::describe($this->languages->defaultLocale()),
            'prefix' => '/' . strtolower($locale) . '/',
            'action' => self::PATH,
            'cancel' => LanguagesHandler::PATH,
        ]);
    }
}
