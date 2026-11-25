<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\User\Http;

use SightPress\Core\Tests\Support\Text;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\User\AvatarKind;
use SightPress\Module\Event\AccountChanged;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\Http\AccountHandler;
use SightPress\Core\User\Http\AccountPages;
use SightPress\Core\User\Http\AccountPictureHandler;

#[CoversClass(AccountPages::class)]
#[CoversClass(AccountHandler::class)]
#[CoversClass(\SightPress\Core\User\Http\AccountLanguageHandler::class)]
#[CoversClass(AccountPictureHandler::class)]
#[CoversClass(\SightPress\Core\User\Http\AccountPreferencesHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class AccountHandlersTest extends TestCase implements EventDispatcherInterface
{
    private AuthFixture $auth;

    private Session $session;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->session = new Session();
    }

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    public function testVisitorsAreSentToSignInFirst(): void
    {
        foreach ([$this->details(null), $this->picture(null), $this->picture(null, remove: true)] as $response) {
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/login?return=%2Faccount', Text::of($response->getHeaderLine('Location')));
        }
    }

    public function testShowsTheDetailsAndPictureInTheSiteTheme(): void
    {
        $max = $this->member();

        $response = $this->details($max);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', Text::of($response->getHeaderLine('Cache-Control')));
        self::assertSame('noindex, nofollow', Text::of($response->getHeaderLine('X-Robots-Tag')));
        self::assertStringContainsString('<title>My account - Demo</title>', $html);
        self::assertStringContainsString('<h1>My account</h1>', $html);
        self::assertStringContainsString('Email address: max@example.org', $html);
        self::assertStringContainsString('value="Zoë" name="first_name" autocomplete="given-name"', $html);
        self::assertStringContainsString('Shown as “Zoë” if left empty.', $html);
        self::assertStringContainsString('<optgroup label="Asia">', $html);
        self::assertStringContainsString('<option value="Asia/Tokyo" selected>Tokyo</option>', $html);
        self::assertStringContainsString('<option value="es" lang="es">Español</option>', $html, 'the admin languages are offered too');
        self::assertMatchesRegularExpression('#<img class="avatar avatar--large" src="/avatars/[0-9a-f]{32}\.svg" alt="Zoë"#', $html);
        self::assertStringContainsString('Until you upload a picture, your initials are shown.', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('<input type="file" id="picture-picture" accept="image/jpeg,image/png,image/webp"', $html);
        self::assertStringNotContainsString('Remove picture', $html);
        self::assertStringNotContainsString('Go to the admin panel', $html, 'members never see a link to the admin');
        self::assertStringContainsString('<a href="/account/email">Change your email address</a>', $html);
        self::assertStringContainsString('<a href="/account/password">Change your password</a>', $html);
        self::assertStringNotContainsString('Admin preferences', $html, 'only for people who use the admin panel');
    }

    public function testPeopleWhoUseTheAdminChooseLessMotionAndLargerText(): void
    {
        $ada = $this->auth->user('ada@example.org', 'editor');
        $handler = new \SightPress\Core\User\Http\AccountPreferencesHandler($this->pages(), $this->auth->validator(), $this->auth->users, $this->auth->clock, $this);

        $html = (string) $this->details($ada)->getBody();
        self::assertStringContainsString('<h2 id="account-preferences-title">Admin preferences</h2>', $html);
        self::assertStringContainsString('<form id="admin-preferences" action="/account/preferences" method="post"', $html);
        self::assertStringContainsString('Reduce motion', $html);
        self::assertStringContainsString('Larger text', $html);

        $response = $handler->handle($this->request($ada, 'POST', ['reduced_motion' => '1']));
        self::assertSame([303, '/account'], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
        self::assertSame(['Your admin preferences have been saved.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        self::assertSame([true, false], [$ada->adminReducedMotion(), $ada->adminLargerText()]);
        self::assertSame([['admin_preferences']], Text::of($this->changedFields()));
        self::assertMatchesRegularExpression('#id="admin-preferences-reduced-motion" value="1" checked#', (string) $this->details($ada)->getBody());

        $handler->handle($this->request($ada, 'POST', ['reduced_motion' => '1']));
        self::assertSame(['You didn’t change anything.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        self::assertCount(1, $this->events);

        $handler->handle($this->request($ada, 'POST', ['larger_text' => '1']));
        self::assertSame([false, true], [$ada->adminReducedMotion(), $ada->adminLargerText()]);
        self::assertSame(303, $handler->handle($this->request(null, 'POST'))->getStatusCode(), 'visitors are sent to sign in');

        $this->expectException(\Slim\Exception\HttpForbiddenException::class);
        $handler->handle($this->request($this->member(), 'POST', ['larger_text' => '1']));
    }

    public function testSavesNewNamesMakesTheAvatarAgainAndSaysSo(): void
    {
        $max = $this->member();
        $before = $max->avatarPath();

        $response = $this->details($max, 'POST', ['first_name' => ' Anna ', 'last_name' => 'Lind', 'display_name' => 'Annie', 'timezone' => 'Europe/Lisbon']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/account', Text::of($response->getHeaderLine('Location')));
        self::assertSame(['Your details have been saved.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        $this->auth->database->entityManager->clear();
        $saved = $this->auth->users->find((int) $max->id());
        self::assertNotNull($saved);
        self::assertSame(['Anna', 'Lind', 'Annie', 'Europe/Lisbon'], Text::of([$saved->firstName(), $saved->lastName(), $saved->displayName(), $saved->timezone()]));
        self::assertNotSame($before, $saved->avatarPath());
        self::assertStringContainsString('>AL</text>', $this->auth->storage()->read((string) $saved->avatarPath()));
        self::assertEquals([new AccountChanged((int) $max->id(), $max->id(), ['first_name', 'last_name', 'display_name', 'timezone', 'avatar'])], Text::of($this->events));
    }

    public function testATimeZoneChangeAloneKeepsTheAvatarAndNothingChangedIsSaidToo(): void
    {
        $max = $this->member();
        $avatar = $max->avatarPath();

        $this->details($max, 'POST', ['first_name' => 'Zoë', 'last_name' => 'Ødegaard', 'timezone' => 'America/Sao_Paulo']);
        self::assertSame($avatar, $max->avatarPath());
        self::assertEquals([new AccountChanged((int) $max->id(), $max->id(), ['timezone'])], Text::of($this->events));
        array_map(strval(...), Flash::take($this->session));

        $this->details($max, 'POST', ['first_name' => 'Zoë', 'last_name' => 'Ødegaard', 'timezone' => 'America/Sao_Paulo']);
        self::assertSame(['You didn’t change anything.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        self::assertCount(1, $this->events);
    }

    public function testExplainsEveryProblemAndCountsThemInTheTitle(): void
    {
        $max = $this->member();

        $response = $this->details($max, 'POST', ['first_name' => '', 'last_name' => str_repeat('x', 101), 'timezone' => 'Mars/Olympus']);
        $html = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<title>Error: My account - Demo</title>', $html);
        self::assertStringContainsString('<section class="error-summary" id="details-errors"', $html);
        self::assertStringContainsString('<a href="#details-first-name">Enter first name</a>', $html);
        self::assertStringContainsString('Last name must be 100 characters or fewer', $html);
        self::assertStringContainsString('Select one of the options for “Time zone”', $html);
        self::assertSame([], Text::of($this->events));
    }

    public function testOnASiteWithSeveralLanguagesPeopleChooseTheirsOrTheSiteDefault(): void
    {
        $max = $this->member();
        $pages = $this->pages(PageFixture::site(['en-GB', 'pt-PT']));

        $html = (string) $this->handle(new AccountHandler($pages, $this->auth->validator(), $this->auth->users, $this->auth->avatars(), $this->auth->clock, $this), $this->request($max))->getBody();
        self::assertStringContainsString('<option value="default" selected>Site default (British English)</option>', $html);
        self::assertStringContainsString('<option value="pt-PT" lang="pt-PT">Português europeu</option>', $html);

        $handler = new AccountHandler($pages, $this->auth->validator(), $this->auth->users, $this->auth->avatars(), $this->auth->clock, $this);
        $this->handle($handler, $this->request($max, 'POST', ['first_name' => 'Zoë', 'last_name' => 'Ødegaard', 'timezone' => 'Asia/Tokyo', 'locale' => 'pt-PT']));
        self::assertSame('pt-PT', Text::of($max->locale()));
        $this->handle($handler, $this->request($max, 'POST', ['first_name' => 'Zoë', 'last_name' => 'Ødegaard', 'timezone' => 'Asia/Tokyo', 'locale' => 'default']));
        self::assertNull($max->locale());
        self::assertSame([['locale'], ['locale']], Text::of($this->changedFields()));
    }

    public function testUploadsAndRemovesAPictureAndAnnouncesIt(): void
    {
        $max = $this->member();
        $staff = $this->auth->user('ada@example.org', 'editor');
        $this->auth->avatars()->generate($staff);

        $response = $this->picture($max, files: ['picture' => self::upload(MediaFixture::jpeg(300, 300))]);
        self::assertSame('/account', Text::of($response->getHeaderLine('Location')));
        self::assertSame(['Your new picture has been saved.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        self::assertSame(AvatarKind::Uploaded, $max->avatarKind());
        $html = (string) $this->details($max)->getBody();
        self::assertStringContainsString('This is the picture you uploaded.', $html);
        self::assertMatchesRegularExpression('#src="/avatars/[0-9a-f]{32}-256\.jpg"#', $html);
        self::assertStringContainsString('<form action="/account/picture/remove" method="post">', $html);

        $this->picture($max, remove: true);
        self::assertSame(['Your picture has been removed. Your initials are shown instead.'], Text::of(array_map(strval(...), Flash::take($this->session))));
        self::assertSame(AvatarKind::Generated, $max->avatarKind());
        self::assertSame([['avatar'], ['avatar']], Text::of($this->changedFields()));
        self::assertStringContainsString('<a href="/admin">Go to the admin panel</a>', (string) $this->details($staff)->getBody());
    }

    public function testARefusedPictureIsExplainedInTheErrorSummary(): void
    {
        $max = $this->member();

        foreach ([
            'SVG pictures can' => ['picture' => self::upload('<svg xmlns="http://www.w3.org/2000/svg"/>')],
            'Choose a picture to upload.' => [],
        ] as $message => $files) {
            $response = $this->picture($max, files: $files);
            $html = (string) $response->getBody();

            self::assertSame(422, $response->getStatusCode());
            self::assertStringContainsString('<title>Error: My account - Demo</title>', $html);
            self::assertStringContainsString('<a href="#picture-picture">' . htmlspecialchars($message, ENT_QUOTES), $html);
            self::assertStringContainsString('aria-invalid="true"', $html);
        }
        self::assertSame([], Text::of($this->events));
    }

    /**
     * @return list<list<string>>
     */
    private function changedFields(): array
    {
        $fields = [];
        foreach ($this->events as $event) {
            self::assertInstanceOf(AccountChanged::class, $event);
            $fields[] = $event->fields;
        }

        return $fields;
    }

    private function member(): User
    {
        $max = new User('max@example.org', 'Zoë', 'Ødegaard', 'Asia/Tokyo', $this->auth->clock->now());
        $max->setPasswordHash('hash', $this->auth->clock->now());
        $this->auth->users->save($max);
        $this->auth->avatars()->generate($max);
        $this->auth->users->save($max);

        return $max;
    }

    private function pages(?Site $site = null): AccountPages
    {
        return new AccountPages($this->auth->templates, $this->auth->http, $this->auth->users, $site ?? $this->auth->site, $this->auth->translations, $this->auth->avatars(), $this->auth->texts());
    }

    /**
     * @param array<string, string> $body
     */
    private function details(?User $user, string $method = 'GET', array $body = []): ResponseInterface
    {
        $handler = new AccountHandler($this->pages(), $this->auth->validator(), $this->auth->users, $this->auth->avatars(), $this->auth->clock, $this);

        return $this->handle($handler, $this->request($user, $method, $body));
    }

    /**
     * @param array<string, UploadedFile> $files
     */
    private function picture(?User $user, array $files = [], bool $remove = false): ResponseInterface
    {
        $handler = new AccountPictureHandler($this->pages(), $this->auth->users, $this->auth->avatars(), $this);
        $request = $this->auth->request('POST', 'https://demo.example' . ($remove ? AccountPictureHandler::REMOVE_PATH : '/account/picture'), session: $this->session)
            ->withUploadedFiles($files);
        if ($user !== null) {
            $request = $request->withAttribute(Actor::class, new Actor($user->id(), $this->auth->database->roles()->permissionNamesOf((int) $user->id())));
        }

        return $this->handle($handler, $request);
    }

    public function testTheLanguageSwitcherChangesThePersonsLanguageAndGoesBack(): void
    {
        $max = $this->member();
        $handler = new \SightPress\Core\User\Http\AccountLanguageHandler($this->pages(), $this->auth->users, $this->auth->translations, $this->auth->site, $this->auth->clock, $this, $this->auth->http);

        $response = $handler->handle($this->request($max, 'POST', ['locale' => 'es', 'return' => '/admin/pages?state=draft']));

        self::assertSame([303, '/admin/pages?state=draft'], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
        self::assertSame('es', $this->auth->users->find((int) $max->id())?->locale());
        self::assertSame(['The admin panel is now in Español.'], Text::of(\SightPress\Core\Http\Session\Flash::take($this->session)));
        self::assertCount(1, $this->events);

        $again = $handler->handle($this->request($max, 'POST', ['locale' => 'es', 'return' => '//elsewhere.example/']));
        self::assertSame('/account', $again->getHeaderLine('Location'), 'only addresses on this site');
        self::assertCount(1, $this->events, 'nothing changed');
        self::assertSame(303, $handler->handle($this->request(null, 'POST', ['locale' => 'es']))->getStatusCode(), 'visitors are sent to sign in');

        $this->expectException(\Slim\Exception\HttpBadRequestException::class);
        $handler->handle($this->request($max, 'POST', ['locale' => 'klingon']));
    }

    /**
     * @param array<string, string> $body
     */
    private function request(?User $user, string $method = 'GET', array $body = []): ServerRequestInterface
    {
        $request = $this->auth->request($method, 'https://demo.example/account', $body, session: $this->session);
        if ($user === null) {
            return $request;
        }
        $permissions = $this->auth->database->roles()->permissionNamesOf((int) $user->id());

        return $request
            ->withAttribute(Actor::class, new Actor($user->id(), $permissions))
            ->withAttribute(\SightPress\Core\Http\Security\GrantedPermissions::class, new \SightPress\Core\Http\Security\GrantedPermissions($permissions));
    }

    private function handle(AccountHandler|AccountPictureHandler $handler, ServerRequestInterface $request): ResponseInterface
    {
        return $handler->handle($request);
    }

    private static function upload(string $contents): UploadedFile
    {
        return new UploadedFile(Stream::create($contents), strlen($contents), UPLOAD_ERR_OK, 'me.jpg');
    }
}
