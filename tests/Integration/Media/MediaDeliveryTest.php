<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Media;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Media\Contract\MediaUrlGenerator;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * Files of the media library served by the site through the whole kernel: published files to everyone with long
 * caching, unpublished ones only to signed-in people who can manage media, ranges for players.
 */
#[CoversNothing]
final class MediaDeliveryTest extends TestCase
{
    private KernelSite $site;

    private Media $photo;

    private Media $notes;

    protected function setUp(): void
    {
        $this->site = new KernelSite('media_delivery');
        $container = $this->site->container();
        $uploader = Services::get($container, MediaUploader::class);
        $this->photo = $uploader->upload($this->site->project->directory->write('upload/photo.jpg', MediaFixture::jpeg(700, 400)), 'Lighthouse.jpg', null)->media;
        $this->notes = $uploader->upload($this->site->project->directory->write('upload/notes.txt', 'Minutes of the first meeting.'), 'Minutes.txt', null)->media;
        $this->photo->publish(new DateTimeImmutable());
        Services::get($container, MediaRepository::class)->save($this->photo);

        $user = new User('ada@example.org', 'Ada', 'Lovelace', 'UTC', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher()->hash(AuthFixture::PASSWORD), new DateTimeImmutable());
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole('editor') ?? throw new \LogicException('editor'));
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAPublishedPictureAndItsCopiesAreServedToEveryone(): void
    {
        $urls = Services::get($this->site->container(), MediaUrlGenerator::class);
        $browser = new Browser($this->site->kernel, 'https://example.org');

        $original = $browser->request('GET', $urls->url($this->photo));
        self::assertSame(200, $original->getStatusCode());
        self::assertSame('image/jpeg', $original->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=31536000, immutable', $original->getHeaderLine('Cache-Control'));
        self::assertSame("default-src 'none'; sandbox", $original->getHeaderLine('Content-Security-Policy'));
        self::assertFalse($original->hasHeader('Set-Cookie'), 'no session for visitors, so shared caches can keep the file');
        self::assertSame(700, getimagesizefromstring((string) $original->getBody())[0] ?? null);

        $copy = $browser->request('GET', $urls->url($this->photo, 320));
        self::assertSame(320, getimagesizefromstring((string) $copy->getBody())[0] ?? null);
        self::assertStringContainsString('/320w/lighthouse.jpg 320w', $urls->srcset($this->photo));

        $again = $browser->request('GET', $urls->url($this->photo), headers: ['If-None-Match' => $original->getHeaderLine('ETag')]);
        self::assertSame(304, $again->getStatusCode());

        self::assertSame($urls->url($this->photo), $browser->request('GET', '/media/' . $this->photo->key() . '/old.jpg')->getHeaderLine('Location'));
        self::assertSame(404, $browser->request('GET', '/media/' . $this->photo->key() . '/500w/lighthouse.jpg')->getStatusCode());
        self::assertSame(404, $browser->request('GET', '/media/not-a-key/lighthouse.jpg')->getStatusCode());
    }

    public function testAnUnpublishedFileIsOnlyForPeopleWhoManageMedia(): void
    {
        $url = Services::get($this->site->container(), MediaUrlGenerator::class)->url($this->notes);
        $browser = new Browser($this->site->kernel, 'https://example.org');

        self::assertSame(404, $browser->request('GET', $url)->getStatusCode());

        $browser->submit('/login', ['email' => 'ada@example.org', 'password' => AuthFixture::PASSWORD]);
        $response = $browser->request('GET', $url);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('attachment; filename="minutes.txt"; filename*=UTF-8\'\'Minutes.txt', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('Minutes of the first meeting.', (string) $response->getBody());

        $part = $browser->request('GET', $url, headers: ['Range' => 'bytes=0-6']);
        self::assertSame(206, $part->getStatusCode());
        self::assertSame('Minutes', (string) $part->getBody());
    }
}
