<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use SightPress\Core\Site\Command\UpgradeRegistryCommand;
use SightPress\Core\Tenant\SiteRegistryFile;
use SightPress\Core\Tenant\SiteRegistryUpgrader;
use SightPress\Module\Console\Command;

#[CoversClass(UpgradeRegistryCommand::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesNamespace('SightPress\Core\Infrastructure\Crypto')]
final class UpgradeRegistryCommandTest extends CommandTestCase
{
    public function testDescribesItself(): void
    {
        $command = $this->command('<?php return ["version" => 1];');

        self::assertSame('site:registry:upgrade', $command->name());
        self::assertStringStartsWith('Upgrades the site registry', $command->description());
        self::assertSame([], $command->parameters());
    }

    public function testACurrentRegistryNeedsNothing(): void
    {
        self::assertSame(Command::SUCCESS, $this->execute($this->command('<?php return ["version" => 2];')));
        self::assertSame(['The site registry already uses format version 2. Nothing to do.'], $this->lines);
    }

    public function testUpgradesAndRemindsToReloadPhpFpm(): void
    {
        $command = $this->command('<?php return ["version" => 1, "sites" => []];', [
            1 => static fn (array $registry): array => $registry,
        ], 2);

        self::assertSame(Command::SUCCESS, $this->execute($command));
        self::assertStringStartsWith('Upgraded the site registry from format version 1 to 2. The previous file is kept as ', $this->lines[0]);
        self::assertStringEndsWith('/sites.php.bak.', $this->lines[0]);
        self::assertStringStartsWith('If PHP-FPM runs with opcache.validate_timestamps=0', $this->lines[1]);
    }

    public function testReportsAFailedUpgrade(): void
    {
        self::assertSame(Command::FAILURE, $this->execute($this->command('<?php return ["version" => 9];')));
        self::assertStringContainsString('made by a newer SightPress', $this->errors[0]);
    }

    /**
     * @param array<int, \Closure(array<mixed>): array<mixed>> $steps
     * @param positive-int                                    $target
     */
    private function command(string $contents, array $steps = [], int $target = 2): UpgradeRegistryCommand
    {
        $file = new SiteRegistryFile($this->directory->write('sites.php', $contents));

        return new UpgradeRegistryCommand(new SiteRegistryUpgrader($file, $steps, $target, static fn (): null => null), $file);
    }
}
