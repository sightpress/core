<?php

declare(strict_types=1);

namespace SightPress\Core\Admin\Http;

use SightPress\Core\Template\LanguageName;
use SightPress\Core\Template\Message;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Auth\Contract\Passwords;
use SightPress\Core\Auth\Contract\SignUp;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Form\TimezoneChoices;
use SightPress\Core\Infrastructure\Settings\SettingCatalogue;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Seo\Contract\SiteVerification;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Setting\Setting;
use SightPress\Module\Setting\SettingType;
use Slim\Exception\HttpForbiddenException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * `/admin/settings`: the site's name and time zone (its languages are on {@see LanguagesHandler}'s screen), then a field for every setting the modules
 * registered, grouped by what they are about (who runs the site, search & AI, then the other modules), and what
 * can't be changed yet (visibility, theme). Needs `settings.manage`. Saving says so in the live region and tells
 * the modules which settings changed ({@see SettingsChanged}).
 */
final readonly class SettingsHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/settings';

    public const string PERMISSION = 'settings.manage';

    /** Modules whose settings get a section of their own, with its heading and introduction. */
    private const array SECTIONS = [
        'site' => ['Who runs the site', 'Search engines and AI search engines show these details with the site. Leave a field empty to keep it private.'],
        'seo' => ['Search & AI', 'How search engines and AI services may use the site.'],
        'auth' => ['Passwords', 'What every new password on this site must have. People keep their current password until they next change it.'],
    ];

    public function __construct(
        private AdminScreens $screens,
        private FormValidator $validator,
        private SettingCatalogue $catalogue,
        private SiteSettings $settings,
        private Site $site,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * The form field of a setting: its key with the dots doubled into underscores (`site__contact_email`), because
     * PHP turns dots in form names into underscores.
     */
    public static function fieldName(string $key): string
    {
        return 'setting__' . str_replace('.', '__', $key);
    }

    public function form(): Form
    {
        $fields = [new Field('name', 'Site name', hint: 'Shown at the top of every page, in the browser tab and in search results.', constraints: [new Length(max: 120, maxMessage: 'The site name must be 120 characters or fewer')])];
        $fields[] = new Field('timezone', 'Time zone', FieldType::Select, hint: 'Dates and times are shown in it to visitors, and to people who haven’t chosen their own.', choices: TimezoneChoices::grouped());
        foreach ($this->catalogue->all() as ['setting' => $setting]) {
            $fields[] = $this->field($setting);
        }

        return Form::create('site-settings', ...$fields);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(self::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $form = $this->form()->withValues($this->current());
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $form = $this->validator->submit($this->form(), is_array($body) ? $body : []);
            if ($form->isValid()) {
                $changed = $this->save($form);
                if ($changed !== []) {
                    $this->events->dispatch(new SettingsChanged($changed, $actor->userId));
                }

                return $this->screens->redirect($request, self::PATH, $changed === [] ? 'Nothing was changed.' : 'The settings have been saved.');
            }
        }

        return $this->screens->render($request, '@admin/settings.html.twig', [
            'form' => $form,
            'action' => self::PATH,
            'sections' => $this->sections(),
            'owner' => $this->site->ownerType === OwnerType::Person ? 'a person' : 'an organisation',
            'languages' => array_map(
                fn (string $locale): array => ['name' => new LanguageName($locale), 'main' => $locale === $this->site->defaultLocale],
                array_values(array_unique([$this->site->defaultLocale, ...$this->site->locales])),
            ),
            'languages_path' => LanguagesHandler::PATH,
            'sign_up_open' => SignUp::isOpen($this->settings->get(SignUp::SETTING)),
        ], $form->submitted && !$form->isValid() ? 422 : 200);
    }

    /**
     * @return list<array{title: string|Message, intro: string, fields: list<string>}>
     */
    private function sections(): array
    {
        $sections = [];
        foreach ($this->catalogue->all() as ['module' => $module, 'setting' => $setting]) {
            [$title, $intro] = self::SECTIONS[$module] ?? [new Message('More settings: {module}', ['module' => ucfirst(str_replace('_', ' ', $module))]), ''];
            $sections[$module] ??= ['title' => $title, 'intro' => $intro, 'fields' => []];
            $sections[$module]['fields'][] = self::fieldName($setting->key);
        }

        return array_values($sections);
    }

    /**
     * @return array<string, string>
     */
    private function current(): array
    {
        $values = ['name' => $this->site->name, 'timezone' => $this->site->defaultTimezone];
        foreach ($this->catalogue->all() as ['setting' => $setting]) {
            $value = SettingCatalogue::stored($setting, $this->settings->get($setting->key));
            $values[self::fieldName($setting->key)] = $setting->type === SettingType::Boolean ? ($value === '1' ? '1' : '') : $value;
        }

        return $values;
    }

    /**
     * Stores what changed.
     *
     * @return list<string> keys of the settings that changed
     */
    private function save(Form $form): array
    {
        $wanted = [
            SiteProfile::NAME => [$this->site->name, $form->text('name')],
            SiteProfile::DEFAULT_TIMEZONE => [$this->site->defaultTimezone, $form->text('timezone')],
        ];
        foreach ($this->catalogue->all() as ['setting' => $setting]) {
            $value = $form->text(self::fieldName($setting->key));
            $wanted[$setting->key] = [
                SettingCatalogue::stored($setting, $this->settings->get($setting->key)),
                $setting->type === SettingType::Boolean ? ($value === '1' ? '1' : '0') : $value,
            ];
        }
        $changed = [];
        foreach ($wanted as $key => [$old, $new]) {
            if ($old !== $new) {
                $this->settings->set($key, $new);
                $changed[] = $key;
            }
        }

        return $changed;
    }

    private function field(Setting $setting): Field
    {
        $name = self::fieldName($setting->key);
        $hint = $setting->help === '' ? null : $setting->help;

        return match ($setting->type) {
            SettingType::Boolean => new Field($name, $setting->label, FieldType::Checkbox, hint: $hint, required: false),
            SettingType::Choice => new Field($name, $setting->label, FieldType::Radios, hint: $hint, choices: $setting->choices),
            SettingType::Integer => new Field($name, $setting->label, hint: $hint, required: $setting->key === Passwords::MIN_LENGTH_SETTING, constraints: [new Regex('/^-?\d{1,18}$/', message: 'Enter a whole number, such as 10'), ...self::rules($setting->key)], inputmode: 'numeric'),
            SettingType::LongText => new Field($name, $setting->label, FieldType::Textarea, hint: $hint, required: false, constraints: [new Length(max: 5000, maxMessage: '{field} must be {{ limit }} characters or fewer'), ...self::rules($setting->key)]),
            SettingType::Text => new Field($name, $setting->label, $setting->key === SiteProfile::EMAIL ? FieldType::Email : FieldType::Text, hint: $hint, required: false, constraints: [new Length(max: 500, maxMessage: '{field} must be {{ limit }} characters or fewer'), ...self::rules($setting->key)]),
        };
    }

    /**
     * Checks of the settings whose values have a format core knows.
     *
     * @return list<Constraint>
     */
    private static function rules(string $key): array
    {
        return match (true) {
            $key === SiteProfile::SAME_AS => [new Callback(static function (mixed $value, ExecutionContextInterface $context): void {
                foreach (SiteProfile::lines(is_string($value) ? $value : '') as $line) {
                    $line = trim($line);
                    if ($line !== '' && !SiteProfile::isLink($line)) {
                        $context->buildViolation('“{{ line }}” is not a full web address starting with https://. Put one address on each line.')->setParameter('{{ line }}', mb_strimwidth($line, 0, 80, '…'))->addViolation();

                        return;
                    }
                }
            })],
            $key === Passwords::MIN_LENGTH_SETTING => [new Callback(static function (mixed $value, ExecutionContextInterface $context): void {
                if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 && ((int) $value < Passwords::SHORTEST_MIN_LENGTH || (int) $value > Passwords::MAX_LENGTH)) {
                    $context->buildViolation('The shortest password allowed must be a number from {{ min }} to {{ max }}')->setParameter('{{ min }}', (string) Passwords::SHORTEST_MIN_LENGTH)->setParameter('{{ max }}', (string) Passwords::MAX_LENGTH)->addViolation();
                }
            })],
            isset(SiteVerification::CODES[$key]) => [new Regex(SiteVerification::CODE_PATTERN, message: 'Paste only the code: the letters and digits between the quotes after content=')],
            default => [],
        };
    }
}
