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
use SightPress\Core\Auth\PasswordResetTokens;
use SightPress\Core\Auth\RememberTokens;
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
use SightPress\Core\User\Entity\User;
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
    public const string PASSWORD = 'correct horse battery staple';

    public readonly CoreSiteDatabase $database;

    public readonly UserRepository $users;

    public readonly FixedClock $clock;

    public readonly RateLimiters $limiters;

    public readonly Site $site;

    public readonly Psr17Factory $http;

    public readonly PasswordHasher $hasher;

    public readonly TemplateRenderer $templates;

    /** @var list<Email> */
    public array $sent = [];

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
        $twig = new TwigFactory()->create(
            new TemplatePaths([TemplatePaths::defaultTheme()], TemplatePaths::core()),
            $this->database->registry,
            new TemplateExtension($current, new AssetUrls(), Translations::fromDirectories([]), new DateFormatter(), new Viewer('en-GB', 'UTC')),
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
     * Saves a user; with a role, active with {@see PASSWORD}; with a null role, active without any role.
     */
    public function user(
        string $email = 'ada@example.org',
        ?string $role = 'editor',
        bool $pending = false,
        bool $deactivated = false,
    ): User {
        $user = new User($email, 'Ada', 'Lovelace', 'UTC', new DateTimeImmutable('2026-11-01 09:00:00 UTC'));
        if (!$pending) {
            $user->setPasswordHash($this->hasher->hash(self::PASSWORD), $this->clock->now());
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

    public function resetTokens(): PasswordResetTokens
    {
        return new PasswordResetTokens($this->users, $this->site, $this->clock, SiteFixture::SECRET);
    }

    public function passwordReset(): PasswordReset
    {
        return new PasswordReset(
            $this->users,
            $this->resetTokens(),
            $this->hasher,
            $this->rememberTokens(),
            $this->limiters,
            $this,
            $this->templates,
            $this->clock,
            $this->site,
        );
    }

    public function authenticator(): SessionAuthenticator
    {
        return new SessionAuthenticator($this->users, $this->database->roles(), $this->rememberTokens(), $this->signIn());
    }

    public function pages(): AuthPages
    {
        return new AuthPages($this->templates, $this->http, $this->site);
    }

    public function validator(): FormValidator
    {
        return new FormValidator(Validation::createValidator());
    }
}
