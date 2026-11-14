<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Site\Command\CheckRegistryCommand;
use SightPress\Core\Tenant\FileSiteRepository;
use SightPress\Core\Tenant\SiteRegistryFile;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Module\Console\Command;

#[CoversClass(CheckRegistryCommand::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesNamespace('SightPress\Core\Infrastructure\Crypto')]
final class CheckRegistryCommandTest extends CommandTestCase
{
    public function testDescribesItself(): void
    {
        $command = $this->command([], null);

        self::assertSame('site:registry:check', $command->name());
        self::assertStringStartsWith('Checks the site registry', $command->description());
        self::assertSame([], $command->parameters());
    }

    public function testAnEmptyRegistryIsValid(): void
    {
        self::assertSame(Command::SUCCESS, $this->execute($this->command([], null)));
        self::assertSame(['The site registry is valid: no sites yet.'], $this->lines);
    }

    public function testChecksEveryDsnCanBeDecrypted(): void
    {
        $command = $this->command(['a' => SiteFixture::entry('a'), 'b' => SiteFixture::entry('b')], SiteFixture::SECRET);

        self::assertSame(Command::SUCCESS, $this->execute($command));
        self::assertSame(['The site registry is valid: 2 sites.'], $this->lines);
    }

    public function testWithoutASecretTheDsnsAreNotChecked(): void
    {
        self::assertSame(Command::SUCCESS, $this->execute($this->command(['a' => SiteFixture::entry('a')], null)));
        self::assertSame([
            'The site registry is valid: 1 site.',
            'APP_SECRET is not set, so the database addresses were not checked.',
        ], $this->lines);
    }

    public function testNamesTheSitesWhoseDsnCannotBeDecrypted(): void
    {
        $other = new SecretBox('another-secret-0123456789abcdef0123456789');
        $command = $this->command([
            'a' => SiteFixture::entry('a', ['db_dsn' => $other->encrypt('sqlite:///a')]),
            'b' => SiteFixture::entry('b'),
            'c' => SiteFixture::entry('c', ['db_dsn' => $other->encrypt('sqlite:///c')]),
        ], SiteFixture::SECRET);

        self::assertSame(Command::FAILURE, $this->execute($command));
        self::assertStringStartsWith('The database address of sites "a", "c" can\'t be decrypted', $this->errors[0]);

        $this->execute($this->command(['a' => SiteFixture::entry('a', ['db_dsn' => $other->encrypt('x')])], SiteFixture::SECRET));
        self::assertStringStartsWith('The database address of site "a" can\'t', $this->errors[0]);
    }

    public function testExplainsWhatIsWrong(): void
    {
        $command = $this->command(['a' => ['name' => 'A']], null);

        self::assertSame(Command::FAILURE, $this->execute($command));
        self::assertStringStartsWith('The site registry is not valid. ', $this->errors[0]);
        self::assertStringEndsWith('site "a": "id" is missing.', $this->errors[0]);
    }

    /**
     * @param array<string, mixed> $sites
     */
    private function command(array $sites, ?string $secret): CheckRegistryCommand
    {
        $file = $this->directory->write('sites.php', '<?php return ' . var_export(['version' => 1, 'sites' => $sites], true) . ';');

        return new CheckRegistryCommand(new FileSiteRepository(new SiteRegistryFile($file)), new SecretBox($secret));
    }
}
