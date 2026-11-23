<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Upload\ServerLimits;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\UploadAllowance;
use SightPress\Core\Http\Upload\IniSize;
use SightPress\Core\Tenant\SiteMediaLimits;
use SightPress\Core\Tenant\TenantConfigException;
use SightPress\Core\Tenant\TenantMediaLimits;
use SightPress\Core\Tests\Support\SiteFixture;

#[CoversClass(SiteMediaLimits::class)]
#[CoversClass(TenantMediaLimits::class)]
#[CoversClass(UploadAllowance::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesNamespace('SightPress\Core\Http\Upload')]
#[UsesNamespace('SightPress\Core\Media')]
#[UsesNamespace('SightPress\Core\Infrastructure\Crypto')]
final class TenantMediaLimitsTest extends TestCase
{
    private const int MB = 1024 * 1024;

    public function testReadsSizesAndKeepsOnlyWhatIsSet(): void
    {
        $limits = SiteMediaLimits::fromArray(['image' => '2M', 'video' => '1G', 'audio' => 1024, 'pixels' => '1000']);

        self::assertSame(['image' => 2 * self::MB, 'video' => 1024 * self::MB, 'audio' => 1024, 'pixels' => 1000], $limits->toArray());
        self::assertFalse($limits->isEmpty());
        self::assertTrue(new SiteMediaLimits()->isEmpty());
        self::assertSame(['image' => 5, 'video' => 1024 * self::MB, 'audio' => 1024, 'pixels' => 1000], $limits->merge(new SiteMediaLimits(image: 5))->toArray());
    }

    /**
     * @param array<mixed> $values
     */
    #[DataProvider('invalid')]
    public function testRefusesValuesThatAreNotLimits(array $values, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        SiteMediaLimits::fromArray($values);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'unknown key' => [['pictures' => '2M'], 'unknown key "pictures"'];
        yield 'zero' => [['image' => '0'], '"image" must be a size'];
        yield 'no limit' => [['video' => '-1'], '"video" must be a size'];
        yield 'words' => [['audio' => 'big'], '"audio" must be a size'];
        yield 'a list' => [['document' => []], '"document" must be a size'];
        yield 'zero pixels' => [['pixels' => 0], '"pixels" must be a whole number'];
        yield 'pixels in words' => [['pixels' => '25M'], '"pixels" must be a whole number'];
    }

    public function testASiteOverridesTheTenantWithinTheCeiling(): void
    {
        $tenant = TenantMediaLimits::fromArray(['limits' => ['audio' => '100M', 'max' => ['audio' => '500M']]]);
        $site = SiteFixture::site()->withMediaLimits(new SiteMediaLimits(audio: 300 * self::MB, image: 2 * self::MB));

        $limits = $tenant->forSite($site);

        self::assertSame(300 * self::MB, $limits->audioBytes);
        self::assertSame(2 * self::MB, $limits->imageBytes);
        self::assertSame(new MediaLimits()->videoBytes, $limits->videoBytes);
        self::assertNull($tenant->aboveCeiling(new SiteMediaLimits(audio: 500 * self::MB)));
        self::assertSame(['audio', 501 * self::MB, 500 * self::MB], $tenant->aboveCeiling(new SiteMediaLimits(audio: 501 * self::MB)));
    }

    public function testWithoutACeilingAValueCantRiseAboveTheDefault(): void
    {
        $tenant = new TenantMediaLimits();

        self::assertNull($tenant->aboveCeiling(new SiteMediaLimits(image: 20 * self::MB)));
        self::assertSame(['image', 20 * self::MB + 1, 20 * self::MB], $tenant->aboveCeiling(new SiteMediaLimits(image: 20 * self::MB + 1)));
    }

    /**
     * @param array<mixed> $media
     */
    #[DataProvider('invalidTenant')]
    public function testRefusesABadTenantSetting(array $media, string $message): void
    {
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage($message);

        TenantMediaLimits::fromArray($media);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidTenant(): iterable
    {
        yield 'limits not an array' => [['limits' => '2M'], '"media.limits" must be an array'];
        yield 'max not an array' => [['limits' => ['max' => '2M']], '"media.limits.max" must be an array'];
        yield 'a bad value' => [['limits' => ['image' => 'big']], '"media.limits": "image" must be a size'];
        yield 'default above ceiling' => [['limits' => ['audio' => '300M', 'max' => ['audio' => '200M']]], '"media.limits.audio" is above its ceiling'];
    }

    /**
     * The effective limit: the site's limit, lowered to what PHP and the web server let one request carry, with sizes
     * as php.ini writes them.
     */
    #[DataProvider('combinations')]
    public function testEffectiveLimit(string $upload, string $post, ?string $proxy, int $site, int $expected): void
    {
        self::assertGreaterThan(0, $site);
        $server = new ServerLimits(IniSize::bytes($upload), IniSize::bytes($post), null, 20, 5000, $proxy === null ? null : IniSize::bytes($proxy), '/tmp', true, null);
        $allowance = new UploadAllowance(new MediaLimits(imageBytes: $site), $server);

        self::assertSame($expected, $allowance->maxBytes(MediaKind::Image));
        self::assertSame(min($expected, 5 * self::MB), $allowance->pictureLimit(5 * self::MB));
        self::assertSame('5 MB', $allowance->describe(5 * self::MB));
    }

    /**
     * @return iterable<string, array{string, string, string|null, int, int}>
     */
    public static function combinations(): iterable
    {
        $mb = self::MB;
        yield 'the site decides' => ['64M', '65M', '65M', 20 * $mb, 20 * $mb];
        yield 'upload_max_filesize decides' => ['2M', '8M', '65M', 20 * $mb, 2 * $mb];
        yield 'post_max_size decides, less the form' => ['64M', '8M', '65M', 20 * $mb, 7 * $mb];
        yield 'the web server decides, less the form' => ['64M', '65M', '11M', 20 * $mb, 10 * $mb];
        yield 'in gigabytes' => ['1G', '2G', '2G', 1536 * $mb, 1024 * $mb];
        yield 'in kilobytes' => ['512K', '8M', null, 20 * $mb, 512 * 1024];
        yield '-1 is no limit' => ['-1', '-1', null, 20 * $mb, 20 * $mb];
        yield '0 is no limit' => ['0', '0', null, 20 * $mb, 20 * $mb];
        yield '-1 for one of them' => ['-1', '8M', null, 20 * $mb, 7 * $mb];
        yield '0 for one of them' => ['4M', '0', null, 20 * $mb, 4 * $mb];
    }
}
