<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Branding\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Branding\BrandAsset;
use SightPress\Core\Branding\BrandingService;
use SightPress\Core\Branding\Command\RegenerateBrandingCommand;
use SightPress\Core\Branding\Command\ResetBrandingCommand;
use SightPress\Core\Branding\Command\SetBrandingCommand;
use SightPress\Core\Branding\Command\SiteBrandings;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;

#[CoversClass(SiteBrandings::class)]
#[CoversClass(SetBrandingCommand::class)]
#[CoversClass(ResetBrandingCommand::class)]
#[CoversClass(RegenerateBrandingCommand::class)]
#[UsesNamespace('SightPress\Core')]
final class BrandingCommandsTest extends TestCase
{
    private KernelSite $site;

    private SiteBrandings $brandings;

    protected function setUp(): void
    {
        $this->site = new KernelSite('branding_commands');
        $tenant = $this->site->kernel->container();
        $this->brandings = new SiteBrandings(Services::get($tenant, SiteRepository::class), Services::get($tenant, SiteContainerFactory::class));
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testSetUsesTheFilesAndExplainsWhatIsWrong(): void
    {
        $command = new SetBrandingCommand($this->brandings);
        self::assertSame('site:branding:set', $command->name());
        self::assertNotSame('', $command->description());
        self::assertSame(['slug', 'logo', 'logo-alt', 'icon', 'share-image', 'share-image-alt'], array_map(static fn ($p) => $p->name, $command->parameters()));
        $directory = $this->site->project->directory;
        $logo = $directory->write('upload/logo.png', MediaFixture::png(400, 100));
        $share = $directory->write('upload/share.jpg', MediaFixture::jpeg(1200, 630));
        $small = $directory->write('upload/small.png', MediaFixture::png(100, 100));

        [$status, $output] = $this->execute($command, ['slug' => 'demo'], ['logo' => $logo, 'logo-alt' => 'Riverside', 'share-image' => $share]);
        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(['The logo has been changed.', 'The share image has been changed.'], $output->lines);
        $state = $this->branding()->state();
        self::assertSame(['Riverside', true, true], [$state->alt(BrandAsset::Logo), $state->isUploaded(BrandAsset::Logo), $state->isUploaded(BrandAsset::ShareImage)]);

        self::assertSame([Command::FAILURE, ['The icon must be a square picture of at least 512 × 512 pixels. This one is 100 × 100 pixels.']], $this->errors($command, ['slug' => 'demo'], ['icon' => $small]));
        self::assertSame([Command::INVALID, ['Give at least one file: --logo, --icon or --share-image.']], $this->errors($command, ['slug' => 'demo'], ['icon' => '  ']));
        self::assertSame([Command::INVALID, ['The file /nowhere.png can\'t be read.']], $this->errors($command, ['slug' => 'demo'], ['icon' => '/nowhere.png']));
        self::assertSame([Command::FAILURE, ['There is no site "shop". See the sites with site:list.']], $this->errors($command, ['slug' => 'shop'], []));
    }

    public function testResetAndRegenerate(): void
    {
        $this->branding()->set(BrandAsset::Logo, MediaFixture::png(400, 100));
        $this->branding()->set(BrandAsset::Icon, MediaFixture::png(512, 512));
        $reset = new ResetBrandingCommand($this->brandings);
        self::assertSame(['site:branding:reset', 4], [$reset->name(), count($reset->parameters())]);
        self::assertNotSame('', $reset->description());

        [, $output] = $this->execute($reset, ['slug' => 'demo'], [], ['icon']);
        self::assertSame(['The icon is the generated one again.'], $output->lines);
        self::assertTrue($this->branding()->state()->isUploaded(BrandAsset::Logo));
        [$status, $output] = $this->execute($reset, ['slug' => 'demo']);
        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(3, $output->lines);
        self::assertFalse($this->branding()->state()->isUploaded(BrandAsset::Logo));
        self::assertSame(Command::FAILURE, $this->errors($reset, ['slug' => 'shop'], [])[0]);

        $regenerate = new RegenerateBrandingCommand($this->brandings);
        self::assertSame(['site:branding:regenerate', 1], [$regenerate->name(), count($regenerate->parameters())]);
        self::assertNotSame('', $regenerate->description());
        $before = $this->branding()->state()->version;
        [$status, $output] = $this->execute($regenerate, ['slug' => 'demo']);
        self::assertSame([Command::SUCCESS, ['The generated pictures have been made again.']], [$status, $output->lines]);
        self::assertNotSame($before, $this->branding()->state()->version);
        self::assertSame(Command::FAILURE, $this->errors($regenerate, ['slug' => 'shop'], [])[0]);
    }

    private function branding(): BrandingService
    {
        $branding = $this->brandings->forSlug('demo');
        self::assertInstanceOf(BrandingService::class, $branding);

        return $branding;
    }

    /**
     * @param array<string, string> $arguments
     * @param array<string, string> $options
     * @param list<string>          $flags
     *
     * @return array{int, RecordingOutput}
     */
    private function execute(Command $command, array $arguments, array $options = [], array $flags = []): array
    {
        $input = new class ($arguments, $options, $flags) implements CommandInput {
            /**
             * @param array<string, string> $arguments
             * @param array<string, string> $options
             * @param list<string>          $flags
             */
            public function __construct(private array $arguments, private array $options, private array $flags)
            {
            }

            public function argument(string $name): ?string
            {
                return $this->arguments[$name] ?? null;
            }

            public function option(string $name): ?string
            {
                return $this->options[$name] ?? null;
            }

            public function flag(string $name): bool
            {
                return in_array($name, $this->flags, true);
            }
        };
        $output = new RecordingOutput();

        return [$command->run($input, $output), $output];
    }

    /**
     * @param array<string, string> $arguments
     * @param array<string, string> $options
     *
     * @return array{int, list<string>}
     */
    private function errors(Command $command, array $arguments, array $options): array
    {
        [$status, $output] = $this->execute($command, $arguments, $options);

        return [$status, $output->errors];
    }
}
