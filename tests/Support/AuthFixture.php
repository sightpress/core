<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Auth\Http\AuthPages;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Auth\PasswordRules;
use SightPress\Core\Auth\EvenTiming;
use SightPress\Core\Auth\VerificationCodeStore;
use SightPress\Core\Auth\RememberTokens;
use SightPress\Core\Auth\Registration\RegistrationMailer;
use SightPress\Core\Auth\Registration\SelfRegistration;
use SightPress\Core\Rbac\RoleGrantPolicy;
use SightPress\Core\User\AccountRegistrar;
use SightPress\Core\Auth\SessionAuthenticator;
use SightPress\Core\Auth\SignIn;
use SightPress\Core\Http\Csrf\CsrfTokens;
use SightPress\Core\Http\CurrentRequest;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Http\Security\CspNonce;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Template\AssetUrls;
use SightPress\Core\Template\DateFormatter;
use SightPress\Core\Template\TemplateExtension;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\TwigFactory;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Avatar\AvatarGenerator;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Entity\User;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Validator\Validation;

/**
 * Everything the sign-in code needs, for unit tests: a migrated and seeded site database in memory, real templates,
 * a fixed clock, in-memory rate limits and a mailer that keeps what it is given.
 */
final class AuthFixture implements MailSender
{
    public const string PASSWORD = 'Correct horse battery staple 7!';

    public readonly CoreSiteDatabase $database;

    public readonly UserRepository $users;

    public readonly FixedClock $clock;

    public readonly RateLimiters $limiters;

    public readonly Site $site;

    public readonly Psr17Factory $http;

    public readonly PasswordHasher $hasher;

    public readonly TemplateRenderer $templates;

    public readonly \Twig\Environment $twig;

    public readonly Translations $translations;

    /** @var list<Email> */
    public array $sent = [];

    /** The site's storage (avatars …), in a temporary folder made when first used. */
    private ?Filesystem $storage = null;

    private ?TemporaryDirectory $directory = null;

    public function __construct()
    {
        $this->database = new CoreSiteDatabase();
        $this->database->seeder()->seed();
        $this->users = new UserRepository($this->database->entityManager);
        $this->clock = new FixedClock();
        $this->limiters = new RateLimiters(new InMemoryStorage());
        $this->site = SiteFixture::site();
        $this->http = new Psr17Factory();
        // The cheapest argon2id settings, so tests stay fast.
        $this->hasher = new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1]);

        $current = new CurrentRequest();
        $this->translations = Translations::fromDirectories([dirname(__DIR__, 2) . '/resources/translations']);
        $this->twig = $twig = new TwigFactory()->create(
            new TemplatePaths([TemplatePaths::defaultTheme()], TemplatePaths::core()),
            $this->database->registry,
            new TemplateExtension($current, new AssetUrls(), $this->translations, new DateFormatter(), new Viewer('en-GB', 'UTC')),
            sys_get_temp_dir(),
            true,
        );
        $this->templates = new TemplateRenderer($twig, $current, $this->http, $this->http);
    }

    public function send(Email $email): void
    {
        $this->sent[] = $email;
    }

    /**
     * Saves a user; with a role, active with {@see PASSWORD}; with a null role, active without any role; unverified,
     * signed up with {@see PASSWORD} and waiting for the emailed code.
     */
    public function user(
        string $email = 'ada@example.org',
        ?string $role = 'editor',
        bool $pending = false,
        bool $deactivated = false,
        bool $unverified = false,
    ): User {
        $user = new User($email, 'Ada', 'Lovelace', 'UTC', new DateTimeImmutable('2026-11-01 09:00:00 UTC'));
        if (!$pending) {
            $user->setPasswordHash($this->hasher->hash(self::PASSWORD), $this->clock->now());
        }
        if ($unverified) {
            $user->awaitEmailVerification($this->clock->now());
        }
        if ($deactivated) {
            $user->deactivate($this->clock->now());
        }
        $this->users->save($user);
        $roleEntity = $role === null ? null : $this->database->roles()->findRole($role);
        if ($roleEntity !== null) {
            $this->database->roles()->assign((int) $user->id(), $roleEntity);
        }

        return $user;
    }

    /**
     * A request as the middleware would hand it to a handler: with a session, CSRF tokens, a CSP nonce and a client
     * address.
     *
     * @param array<string, string> $body
     * @param array<string, string> $cookies
     */
    public function request(
        string $method = 'GET',
        string $uri = 'https://demo.example/login',
        array $body = [],
        array $cookies = [],
        ?Session $session = null,
    ): ServerRequestInterface {
        $session ??= new Session();
        $request = new ServerRequest($method, $uri, [], null, '1.1', ['REMOTE_ADDR' => '192.0.2.10'])
            ->withParsedBody($body === [] ? null : $body)
            ->withCookieParams($cookies)
            ->withAttribute(Session::class, $session)
            ->withAttribute(CspNonce::class, new CspNonce('nonce'));

        return $request->withAttribute(CsrfTokens::class, new CsrfTokens($session, $this->http));
    }

    public function signIn(): SignIn
    {
        return new SignIn($this->users, $this->hasher, $this->limiters, $this->clock, $this->site);
    }

    public function rememberTokens(): RememberTokens
    {
        return new RememberTokens($this->database->connection, $this->clock);
    }

    public function codes(): VerificationCodeStore
    {
        return new VerificationCodeStore($this->database->connection, $this->clock, $this->limiters, SiteFixture::SECRET);
    }

    /**
     * Even timing that waits only a millisecond, so tests stay fast.
     */
    public function timing(): EvenTiming
    {
        return new EvenTiming(1000);
    }

    /**
     * The site's password policy (from its settings) and the hasher.
     */
    public function passwords(): PasswordRules
    {
        return new PasswordRules($this->hasher, $this->database->settings());
    }

    public function passwordReset(): PasswordReset
    {
        return new PasswordReset(
            $this->users,
            $this->codes(),
            $this->hasher,
            $this->rememberTokens(),
            $this,
            $this->templates,
            $this->clock,
            $this->site,
            $this->users,
            $this->translations,
        );
    }

    /**
     * The six digits of the code in an email this fixture sent.
     */
    public static function codeIn(Email $email): string
    {
        if (preg_match('/^(\d{3}) (\d{3})$/m', (string) $email->getTextBody(), $code) !== 1) {
            throw new \LogicException('The email has no code.');
        }

        return $code[1] . $code[2];
    }

    /**
     * Self-registration against this fixture's database, mail and clock; events go to the given dispatcher.
     */
    public function selfRegistration(RecordingEvents $events = new RecordingEvents()): SelfRegistration
    {
        return new SelfRegistration(
            $this->database->settings(),
            new AccountRegistrar($this->users, $this->avatars(), $this->clock),
            $this->users,
            $this->codes(),
            $this->passwords(),
            new RegistrationMailer($this, $this->templates, $this->site, $this->translations, $this->users),
            $this->database->registrationRole(),
            new RoleGrantPolicy($this->database->roles(), new RecordingEvents()),
            $events,
        );
    }

    public function authenticator(): SessionAuthenticator
    {
        return new SessionAuthenticator($this->users, $this->database->roles(), $this->rememberTokens(), $this->signIn(), $this->users);
    }

    public function pages(): AuthPages
    {
        return new AuthPages($this->templates, $this->http, $this->site);
    }

    public function validator(): FormValidator
    {
        return new FormValidator(Validation::createValidator());
    }

    public function storage(): Filesystem
    {
        $this->directory ??= new TemporaryDirectory();

        return $this->storage ??= new Filesystem(new LocalFilesystemAdapter($this->directory->path . '/site', lazyRootCreation: true));
    }

    public function avatars(): AvatarService
    {
        return new AvatarService(
            new AvatarGenerator(),
            new ImageManager(Driver::class, autoOrientation: true, decodeAnimation: false, strip: true),
            $this->storage(),
            $this->clock,
            ServerLimitsFixture::allowance(),
        );
    }

    /**
     * Paths of everything in the site's storage, sorted.
     *
     * @return list<string>
     */
    public function stored(): array
    {
        $paths = [];
        foreach ($this->storage()->listContents('', true) as $item) {
            if ($item->isFile()) {
                $paths[] = $item->path();
            }
        }
        sort($paths);

        return $paths;
    }

    public function __destruct()
    {
        $this->directory?->remove();
    }
}
