<?php

declare(strict_types=1);

namespace SightPress\Core\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
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
            $labels[$code] = sprintf('%s (%s)', $name, $code);
        }

        return Form::create('add-language', new Field(
            'locale',
            'Language to add',
            FieldType::Select,
            hint: 'Type the first letters of its name to move through the list. Pages are then translated into it one by one; nothing is published in it until you publish each translation.',
            choices: ['' => 'Choose a language', ...$labels],
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
            $form = $this->validator->submit($this->form(false), $body);
            if ($form->isValid()) {
                try {
                    $added = $this->languages->add($form->text('locale'), $actor->userId);

                    return $this->screens->redirect($request, self::PATH, sprintf('Added %s. Translate pages into it from each page’s editor.', LocaleCode::describe($added)));
                } catch (SiteLanguageException $exception) {
                    $form = $form->withErrors(['locale' => $exception->getMessage()]);
                }
            }
        }

        return $this->screens->render($request, '@admin/languages.html.twig', [
            'languages' => array_map(fn (string $locale): array => [
                'code' => $locale,
                'name' => LocaleCode::describe($locale),
                'main' => $locale === $this->languages->defaultLocale(),
                'prefix' => $locale === $this->languages->defaultLocale() ? '/' : '/' . strtolower($locale) . '/',
            ], $this->languages->locales()),
            'form' => $form,
            'action' => self::PATH,
            'remove_path' => RemoveLanguageHandler::PATH,
            'settings_path' => SettingsHandler::PATH,
        ], $form->submitted && !$form->isValid() ? 422 : 200);
    }

    private function makeMain(ServerRequestInterface $request, string $locale, Actor $actor): ResponseInterface
    {
        try {
            $changed = $this->languages->makeDefault($locale, $actor->userId);
        } catch (SiteLanguageException $exception) {
            return $this->screens->redirect($request, self::PATH, $exception->getMessage());
        }

        return $this->screens->redirect($request, self::PATH, $changed
            ? sprintf('%s is now the main language. Its pages have addresses without a language part.', LocaleCode::describe($locale))
            : 'Nothing was changed.');
    }
}
