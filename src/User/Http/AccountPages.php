<?php

declare(strict_types=1);

namespace SightPress\Core\User\Http;

use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\Message;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Infrastructure\Sms\TextMessages;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\AvatarKind;
use SightPress\Core\User\Entity\User;
use SightPress\Core\Http\Form\TimezoneChoices;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The *My account* page (`/account`) of every signed-in person, members included: rendered by the site's theme, not
 * the admin. Builds its forms (details, picture and, for people who use the admin panel, admin preferences), links
 * to changing the password and the email address, finds the signed-in user and answers with pages and redirects.
 */
final readonly class AccountPages
{
    public const string PATH = '/account';

    /** The permission that opens the admin panel, and so offers the admin preferences. */
    public const string ADMIN_ACCESS = AdminAccessMiddleware::PERMISSION;

    public function __construct(
        private TemplateRenderer $templates,
        private ResponseFactoryInterface $responses,
        private UserRepository $users,
        private Site $site,
        private Translations $translations,
        private AvatarService $avatars,
        private TextMessages $texts,
    ) {
    }

    /**
     * The signed-in user, or null for a visitor (who is sent to sign in).
     */
    public function user(ServerRequestInterface $request): ?User
    {
        $id = Actor::fromRequest($request)->userId;

        return $id === null ? null : $this->users->find($id);
    }

    public function details(User $user): Form
    {
        $fields = [
            new Field('first_name', 'First name', autocomplete: 'given-name', constraints: [self::length('First name')]),
            new Field('last_name', 'Last name', autocomplete: 'family-name', constraints: [self::length('Last name')]),
            new Field(
                'display_name',
                'Display name',
                hint: new Message('Shown next to your work and in emails. Shown as “{first_name}” if left empty.', ['first_name' => $user->firstName()]),
                required: false,
                constraints: [self::length('Display name')],
                autocomplete: 'nickname',
            ),
            new Field(
                'timezone',
                'Time zone',
                FieldType::Select,
                hint: 'Dates and times are shown in this time zone.',
                choices: TimezoneChoices::grouped(),
            ),
        ];
        $locales = array_values(array_unique([...$this->site->locales, ...$this->translations->locales()]));
        if (count($locales) > 1) {
            $choices = ['default' => new Message('Site default ({language})', ['language' => LocaleCode::ownName($this->site->defaultLocale)])];
            foreach ($locales as $locale) {
                $choices[$locale] = LocaleCode::ownName($locale);
            }
            $fields[] = new Field('locale', 'Language', FieldType::Select, hint: 'The language of your emails and of the admin panel, where it has been translated.', required: false, choices: $choices, localeChoices: true);
        }

        return Form::create('details', ...$fields)->withValues([
            'first_name' => $user->firstName(),
            'last_name' => $user->lastName(),
            'display_name' => $user->displayName() ?? '',
            'timezone' => $user->timezone(),
            'locale' => $user->locale() ?? 'default',
        ]);
    }

    public function picture(): Form
    {
        return Form::create('picture', new Field(
            'picture',
            'New picture',
            FieldType::File,
            hint: new Message('A JPEG, PNG or WebP picture, up to {max}. It is cropped to a square from the middle.', ['max' => $this->avatars->maxSize()]),
            accept: 'image/jpeg,image/png,image/webp',
            maxBytes: $this->avatars->maxBytes(),
        ));
    }

    /**
     * Less motion and larger text in the admin panel, ticked as the person last saved them.
     */
    public static function adminPreferences(User $user): Form
    {
        return Form::create(
            'admin-preferences',
            new Field('reduced_motion', 'Reduce motion', FieldType::Checkbox, hint: 'No animations, sliding or smooth scrolling in the admin panel, even if your device doesn’t ask for less motion.', required: false),
            new Field('larger_text', 'Larger text', FieldType::Checkbox, hint: 'Text in the admin panel is a quarter larger. Your browser’s zoom still works on top of it.', required: false),
        )->withValues([
            'reduced_motion' => $user->adminReducedMotion() ? '1' : '',
            'larger_text' => $user->adminLargerText() ? '1' : '',
        ]);
    }

    /**
     * @param ?Form $details the details form as sent, when it has errors
     * @param ?Form $picture the picture form as sent, when it has errors
     */
    public function page(ServerRequestInterface $request, User $user, ?Form $details = null, ?Form $picture = null): ResponseInterface
    {
        $failed = $details ?? $picture;
        $context = [
            'title' => 'My account',
            'site_name' => $this->site->name,
            'messages' => Flash::take(Session::fromRequest($request)),
            'user' => $user,
            'avatar_url' => AvatarService::url($user, 256),
            'avatar_uploaded' => $user->avatarKind() === AvatarKind::Uploaded,
            'details' => $details ?? $this->details($user),
            'picture' => $picture ?? $this->picture(),
            'admin_preferences' => Actor::fromRequest($request)->has(self::ADMIN_ACCESS) ? self::adminPreferences($user) : null,
            'phone' => $this->texts->isOn() ? [
                'number' => $user->phone() === null ? null : $this->texts->national(new PhoneNumber($user->phone())),
                'texts' => $user->wantsTexts(),
            ] : null,
            'failed' => $failed,
        ];
        if ($failed !== null) {
            $context['form'] = $failed;
        }

        return $this->templates
            ->response($request, '@user/account.html.twig', $context, $failed === null ? 200 : 422)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function redirect(ServerRequestInterface $request, ?string $message = null): ResponseInterface
    {
        if ($message !== null) {
            Flash::add(Session::fromRequest($request), $message);
        }

        return $this->responses->createResponse(303)
            ->withHeader('Location', self::PATH)
            ->withHeader('Cache-Control', 'no-store');
    }

    public function signIn(): ResponseInterface
    {
        return $this->responses->createResponse(303)
            ->withHeader('Location', '/login?' . http_build_query(['return' => self::PATH]))
            ->withHeader('Cache-Control', 'no-store');
    }

    private static function length(string $label): Length
    {
        return new Length(max: User::NAME_MAX_LENGTH, maxMessage: '{field} must be {{ limit }} characters or fewer');
    }

}
