<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Branding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Branding\BrandAsset;
use SightPress\Core\Branding\BrandingService;
use SightPress\Core\Branding\BrandingState;
use SightPress\Core\Branding\RenameListener;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tests\Support\BrandingFixture;

#[CoversClass(BrandingState::class)]
#[CoversClass(BrandingService::class)]
#[CoversClass(RenameListener::class)]
#[UsesNamespace('SightPress\Core')]
final class BrandingAltTest extends TestCase
{
    private BrandingFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new BrandingFixture('Riverside', OwnerType::Organization, ['en-GB', 'pt-PT']);
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testEachLanguageHasItsOwnTextAndFallsBackToTheMainOne(): void
    {
        $branding = $this->fixture->service();
        $branding->set(BrandAsset::ShareImage, \SightPress\Core\Tests\Support\MediaFixture::jpeg(1200, 630), 'The river');

        $state = $branding->describe(BrandAsset::ShareImage, ['en-GB' => ' The  river at dawn ', 'pt-PT' => 'O rio', 'fr-FR' => 'ignored']);
        self::assertSame(['The river at dawn', 'O rio', 'The river at dawn'], [$state->alt(BrandAsset::ShareImage), $state->alt(BrandAsset::ShareImage, 'pt-PT'), $state->alt(BrandAsset::ShareImage, 'de-DE')]);
        self::assertSame($state->toJson(), BrandingState::fromJson($state->toJson())->toJson());

        $state = $branding->describe(BrandAsset::ShareImage, ['pt-PT' => '']);
        self::assertSame('The river at dawn', $state->alt(BrandAsset::ShareImage, 'pt-PT'), 'an empty text falls back');

        $branding->describe(BrandAsset::ShareImage, ['pt-PT' => 'O rio']);
        $reset = $branding->reset(BrandAsset::ShareImage);
        self::assertNull($reset->alt(BrandAsset::ShareImage, 'pt-PT'), 'going back to the generated picture forgets its texts');
        self::assertNull($reset->withAlt(BrandAsset::Logo, null, 'x')->withAlt(BrandAsset::Logo, null, null)->alt(BrandAsset::Logo));
    }

    public function testANewNameMakesTheGeneratedPicturesAgain(): void
    {
        $branding = $this->fixture->service();
        $before = $branding->regenerate();
        $this->fixture->database->settings()->set(SiteProfile::NAME, 'Riverside Reading Room');

        new RenameListener($branding, $this->fixture->database->settings())(new SettingsChanged([SiteProfile::EMAIL], 1));
        self::assertSame($before->version, $branding->state()->version, 'other settings change nothing');

        new RenameListener($branding, $this->fixture->database->settings())(new SettingsChanged([SiteProfile::NAME], 1));
        self::assertNotSame($before->version, $branding->state()->version);
        self::assertStringContainsString('Riverside Reading Room', (string) $branding->read('wordmark.svg'));
    }
}
