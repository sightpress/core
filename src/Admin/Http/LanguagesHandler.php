<?php

declare(strict_types=1);

namespace SightPress\Core\Admin\Http;

use SightPress\Core\Template\LanguageName;
use SightPress\Core\Template\Message;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Page\Contract\StarterContent;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Site\Contract\SiteLanguageException;
use SightPress\Core\Site\Contract\SiteLanguages;
use SightPress\Core\Tenant\LocaleCode;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/settings/languages`: the languages the site is written in, with the address each one's pages start with;
 * a button that makes a language the main one (whose pages have addresses without a language part), a link to
 * remove one (after a confirmation, {@see RemoveLanguageHandler}) and a form to add one, chosen from every language
 * the server knows. Needs `settings.manage`. Saving says what changed in the live region.
 */
final readonly class LanguagesHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/settings/languages';

    public function __construct(
        private AdminScreens $screens,
        private FormValidator $validator,
        private SiteLanguages $languages,
        private StarterContent $starter,
    ) {
    }

    /**
     * The form that adds a language: it offers the languages the site isn't written in yet. Checking what was sent
     * accepts any language (`$offered` false), so one added meanwhile gets a clear message.
     */
    public function form(bool $offered = true): Form
    {
        $labels = [];
        $known = LocaleCode::available();
        foreach ($offered ? array_diff_key($known, array_flip($this->languages->locales())) : $known as $code => $name) {
            $labels[$code] = new Message('{name} ({code})', ['name' => $name, 'code' => $code]);
        }

        return Form::create('add-language', new Field(
            'locale',
            'Language to add',
            FieldType::Select,
            hint: 'Type the first letters of its name to move through the list. Pages are then translated into it one by one; nothing is published in it until you publish each translation.',
            choices: $labels,
            requiredMessage: 'Choose the language to add',
        ));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(SettingsHandler::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $form = $this->form();
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $main = $body['main'] ?? null;
            if (is_string($main)) {
                return $this->makeMain($request, $main, $actor);
            }
            $starter = $body['starter'] ?? null;
            if (is_string($starter)) {
                return $this->starterPages($request, $starter, $actor);
            }
            $form = $this->validator->submit($this->form(false), $body);
            if ($form->isValid()) {
                try {
                    $added = $this->languages->add($form->text('locale'), $actor->userId);

                    return $this->screens->redirect($request, self::PATH, new Message('Added {added}. Translate pages into it from each page’s editor.', ['added' => new LanguageName($added)]));
                } catch (SiteLanguageException $exception) {
                    $form = $form->withErrors(['locale' => $exception->text()]);
                }
            }
        }

        return $this->screens->render($request, '@admin/languages.html.twig', [
            'languages' => array_map(fn (string $locale): array => [
                'code' => $locale,
                'name' => new LanguageName($locale),
                'main' => $locale === $this->languages->defaultLocale(),
                'prefix' => $locale === $this->languages->defaultLocale() ? '/' : '/' . strtolower($locale) . '/',
                'starter' => $locale !== $this->languages->defaultLocale() && $this->starter->missingIn($locale),
            ], $this->languages->locales()),
            'form' => $form,
            'action' => self::PATH,
            'remove_path' => RemoveLanguageHandler::PATH,
            'settings_path' => SettingsHandler::PATH,
        ], $form->submitted && !$form->isValid() ? 422 : 200);
    }

    /**
     * Writes the starter pages missing in a language, as drafts of their own: nothing is published.
     */
    private function starterPages(ServerRequestInterface $request, string $locale, Actor $actor): ResponseInterface
    {
        if (!$this->languages->has($locale)) {
            return $this->screens->redirect($request, self::PATH, new Message('The site isn’t written in “{locale}”. Its languages are: {locales}.', ['locale' => $locale, 'locales' => implode(', ', $this->languages->locales())]));
        }
        $report = $this->starter->writeIn($locale, $actor->userId);
        $name = new LanguageName($locale);
        Flash::add(Session::fromRequest($request), $report->titles === []
            ? new Message('The starter pages are already written in {language}.', ['language' => $name])
            : new Message('Created the starter pages in {language} as drafts: {pages}. Publish each translation from its page’s editor when it is ready.', ['language' => $name, 'pages' => implode(', ', $report->titles)]));

        return $this->screens->redirect($request, self::PATH, $report->fallbacks === [] ? null : new Message(
            'There are no starter pages in {language} yet, so they are in {other}: translate them before you publish them.',
            ['language' => $name, 'other' => new LanguageName(StarterContent::LOCALES[0])],
        ));
    }

    private function makeMain(ServerRequestInterface $request, string $locale, Actor $actor): ResponseInterface
    {
        try {
            $changed = $this->languages->makeDefault($locale, $actor->userId);
        } catch (SiteLanguageException $exception) {
            return $this->screens->redirect($request, self::PATH, $exception->text());
        }

        return $this->screens->redirect($request, self::PATH, $changed
            ? new Message('{locale} is now the main language. Its pages have addresses without a language part.', ['locale' => new LanguageName($locale)])
            : 'Nothing was changed.');
    }
}
