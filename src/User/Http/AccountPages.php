<?php

declare(strict_types=1);

namespace SightPress\Core\User\Http;

use Locale;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\AvatarKind;
use SightPress\Core\User\Entity\User;
use SightPress\Core\Http\Form\TimezoneChoices;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The *My account* page (`/account`) of every signed-in person, members included: rendered by the site's theme, not
 * the admin. Builds its two forms (details, picture), finds the signed-in user and answers with pages and redirects.
 */
final readonly class AccountPages
{
    public const string PATH = '/account';

    public function __construct(
        private TemplateRenderer $templates,
        private ResponseFactoryInterface $responses,
        private UserRepository $users,
        private Site $site,
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
                hint: sprintf('Shown next to your work and in emails. Shown as “%s” if left empty.', $user->firstName()),
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
        if (count($this->site->locales) > 1) {
            $choices = ['default' => sprintf('Site default (%s)', self::languageName($this->site->defaultLocale))];
            foreach ($this->site->locales as $locale) {
                $choices[$locale] = self::languageName($locale);
            }
            $fields[] = new Field('locale', 'Language', FieldType::Select, hint: 'The language of your emails and of the admin panel, where it has been translated.', choices: $choices);
        }

        return Form::create('details', ...$fields)->withValues([
            'first_name' => $user->firstName(),
            'last_name' => $user->lastName(),
            'display_name' => $user->displayName() ?? '',
            'timezone' => $user->timezone(),
            'locale' => $user->locale() ?? 'default',
        ]);
    }

    public static function picture(): Form
    {
        return Form::create('picture', new Field(
            'picture',
            'New picture',
            FieldType::File,
            hint: sprintf(
                'A JPEG, PNG or WebP picture, up to %d MB. It is cropped to a square from the middle.',
                AvatarService::MAX_BYTES / 1024 / 1024,
            ),
            accept: 'image/jpeg,image/png,image/webp',
        ));
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
            'picture' => $picture ?? self::picture(),
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
        return new Length(max: User::NAME_MAX_LENGTH, maxMessage: sprintf('%s must be %d characters or fewer', $label, User::NAME_MAX_LENGTH));
    }

    private static function languageName(string $locale): string
    {
        $english = (string) Locale::getDisplayName($locale, 'en-GB');
        $own = (string) Locale::getDisplayName($locale, $locale);

        return $own === $english || $own === '' ? $english : $english . ' – ' . $own;
    }
}
