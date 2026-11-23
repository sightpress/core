<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Tenant\FileSiteRepository;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteRegistryException;
use SightPress\Core\Tenant\SiteRegistryFile;
use SightPress\Core\Tenant\SiteRegistryFormat;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\SiteStorage;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(FileSiteRepository::class)]
#[UsesClass(SiteRegistryFile::class)]
#[UsesClass(SiteRegistryFormat::class)]
#[UsesClass(Site::class)]
#[UsesClass(SiteStorage::class)]
#[UsesClass(SecretBox::class)]
#[UsesClass(LocaleCode::class)]
#[UsesClass(\SightPress\Core\Tenant\SiteMediaLimits::class)]
final class FileSiteRepositoryTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testFindsSitesBySlugAndDomain(): void
    {
        $repository = $this->repository([
            'demo' => SiteFixture::entry('demo', ['domains' => ['demo.example', 'www.demo.example']]),
            'blog' => SiteFixture::entry('blog', ['status' => 'disabled']),
        ]);

        self::assertSame(['demo', 'blog'], array_keys($repository->all()));
        self::assertSame('blog', $repository->find('blog')?->slug);
        self::assertNull($repository->find('shop'));
        self::assertSame('demo', $repository->findByDomain('WWW.Demo.Example.')?->slug);
        self::assertSame(SiteStatus::Disabled, $repository->findByDomain('blog.example')?->status);
        self::assertNull($repository->findByDomain('shop.example'));
    }

    public function testReadsTheFileOnce(): void
    {
        $repository = $this->repository(['demo' => SiteFixture::entry('demo')]);
        $repository->all();
        $this->directory->write('sites.php', '<?php return "broken";');

        self::assertNotNull($repository->find('demo'));
    }

    public function testAnInvalidFileFailsWhenFirstUsed(): void
    {
        $this->directory->write('sites.php', '<?php return ["version" => 2, "sites" => ["demo" => []]];');
        $repository = new FileSiteRepository(new SiteRegistryFile($this->directory->path . '/sites.php'));

        $this->expectException(SiteRegistryException::class);
        $this->expectExceptionMessage('/sites.php: site "demo": "id" is missing.');

        $repository->findByDomain('demo.example');
    }

    public function testSavesAndRemovesSites(): void
    {
        $repository = $this->repository(['demo' => SiteFixture::entry('demo')]);
        $repository->all();

        $repository->save(SiteFixture::site('blog', ['blog.example']));
        $repository->save(SiteFixture::site('demo', ['demo.example'], SiteStatus::Disabled));

        self::assertSame(['blog', 'demo'], array_keys($repository->all()));
        self::assertSame(SiteStatus::Disabled, $repository->find('demo')?->status);

        $repository->remove('demo');

        $fresh = new FileSiteRepository(new SiteRegistryFile($this->directory->path . '/sites.php'));
        self::assertSame(['blog'], array_keys($fresh->all()));
        self::assertNull($repository->findByDomain('demo.example'));
    }

    public function testRefusesADomainAnotherSiteUses(): void
    {
        $repository = $this->repository(['demo' => SiteFixture::entry('demo')]);

        try {
            $repository->save(SiteFixture::site('blog', ['demo.example']));
            self::fail('The domain was saved twice.');
        } catch (SiteRegistryException $exception) {
            self::assertStringContainsString('site "demo": "domains[0]" is already used by site "blog".', $exception->getMessage());
        }

        self::assertSame(['demo'], array_keys($repository->all()));
    }

    /**
     * @param array<string, array<string, mixed>> $sites
     */
    private function repository(array $sites): FileSiteRepository
    {
        $this->directory->write('sites.php', '<?php return ' . var_export(['version' => 2, 'sites' => $sites], true) . ';');

        return new FileSiteRepository(new SiteRegistryFile($this->directory->path . '/sites.php'));
    }
}
