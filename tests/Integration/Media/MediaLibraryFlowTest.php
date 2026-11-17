<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Media;

use DateTimeImmutable;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * The admin's media library through the whole kernel (C-5.3): a contributor uploads a picture and describes it,
 * an editor publishes it so everyone can open it, and deletes it so nobody can.
 */
#[CoversNothing]
final class MediaLibraryFlowTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('media-library');
        $this->user('bea@example.org', 'Bea', 'editor');
        $this->user('cleo@example.org', 'Cleo', 'contributor');
        $this->user('max@example.org', 'Max', 'member');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testUploadDescribePublishAndDelete(): void
    {
        $cleo = $this->signedIn('cleo@example.org');
        self::assertStringContainsString('There are no files yet.', (string) $cleo->request('GET', '/admin/media')->getBody());

        $png = MediaFixture::png();
        $uploaded = $cleo->submit('/admin/media', [], '/admin/media/upload', ['file' => new UploadedFile(Stream::create($png), strlen($png), UPLOAD_ERR_OK, 'Dusk.png')]);
        self::assertSame(303, $uploaded->getStatusCode());
        $item = $uploaded->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^/admin/media/\d+$#', $item);
        $page = (string) $cleo->request('GET', $item)->getBody();
        self::assertStringContainsString('Dusk.png has been uploaded.', $page);
        self::assertSame(1, preg_match('#<dt>Address</dt><dd><a href="(/media/[0-9a-f]{32}/dusk\.png)">#', $page, $address));
        self::assertStringNotContainsString('name="published"', $page);

        $guest = new Browser($this->site->kernel, 'http://example.org');
        self::assertSame(404, $guest->request('GET', $address[1])->getStatusCode(), 'unpublished');
        self::assertSame(200, $cleo->request('GET', $address[1])->getStatusCode(), 'uploaders preview it');

        $cleo->submit($item, ['alt_0' => 'The sea at dusk', 'caption_0' => '']);
        self::assertStringContainsString('<td>The sea at dusk</td>', (string) $cleo->request('GET', '/admin/media?view=list')->getBody());

        $bea = $this->signedIn('bea@example.org');
        $bea->submit($item, ['alt_0' => 'The sea at dusk', 'caption_0' => '', 'published' => '1']);
        self::assertSame(200, $guest->request('GET', $address[1])->getStatusCode(), 'published');
        self::assertSame(403, $cleo->request('GET', $item . '/delete')->getStatusCode(), 'contributors can’t delete published files');

        $deleted = $bea->submit($item . '/delete', []);
        self::assertSame('/admin/media', $deleted->getHeaderLine('Location'));
        self::assertSame(404, $guest->request('GET', $address[1])->getStatusCode(), 'deleted');
        self::assertSame(404, $bea->request('GET', $item)->getStatusCode());
    }

    public function testTheProgressScriptGetsJsonAndRefusalsAreExplained(): void
    {
        $cleo = $this->signedIn('cleo@example.org');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"/>';

        $response = $cleo->submit('/admin/media', [], '/admin/media/upload', ['file' => new UploadedFile(Stream::create($svg), strlen($svg), UPLOAD_ERR_OK, 'logo.svg')], ['X-Requested-With' => 'XMLHttpRequest']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"location":"/admin/media"}', (string) $response->getBody());
        $library = $cleo->request('GET', '/admin/media');
        self::assertSame(422, $library->getStatusCode());
        self::assertStringContainsString('This type of file can&#039;t be uploaded.', (string) $library->getBody());
    }

    public function testMembersHaveNoLibrary(): void
    {
        self::assertSame(403, $this->signedIn('max@example.org')->request('GET', '/admin/media')->getStatusCode());
    }

    private function signedIn(string $email): Browser
    {
        $browser = new Browser($this->site->kernel, 'http://example.org');
        $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD]);

        return $browser;
    }

    private function user(string $email, string $first, string $role): void
    {
        $container = $this->site->container();
        $user = new User($email, $first, 'Tester', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
    }
}
