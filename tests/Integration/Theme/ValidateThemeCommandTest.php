<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Theme;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Kernel;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\TestProject;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * `bin/sightpress theme:validate` through the real console: the default theme passes, and a theme with a
 * low-contrast colour fails with a sentence naming the pair, the mode and the ratio (only in the
 * modes where "more contrast" doesn't replace the colour).
 */
#[CoversNothing]
final class ValidateThemeCommandTest extends TestCase
{
    private TestProject $project;

    protected function setUp(): void
    {
        $this->project = new TestProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testTheDefaultThemePasses(): void
    {
        $tester = $this->console(['command' => 'theme:validate']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertMatchesRegularExpression(
            '/^The theme "SightPress Default" passes: \d+ colour pairs checked in light and dark mode, with and without more contrast, and the base font size is at least 16 px\.$/',
            trim($tester->getDisplay()),
        );
    }

    public function testACopyWithAPaleTextColourFails(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/resources/themes/default/theme.json');
        $this->project->directory->write('themes/pale/theme.json', str_replace('"text-muted": "#4b4b57"', '"text-muted": "#9a9aa5"', $json));

        $tester = $this->console(['command' => 'theme:validate', 'path' => $this->project->path() . '/themes/pale'], ['capture_stderr_separately' => true]);

        self::assertSame(1, $tester->getStatusCode());
        $errors = $tester->getErrorOutput();
        self::assertStringContainsString('The theme "SightPress Default" fails with 2 problems:', $errors);
        self::assertStringContainsString('1. In light mode, text "text-muted" (#9a9aa5) on "background" (#ffffff) has a contrast of 2.', $errors);
        self::assertStringContainsString('it needs at least 4.50:1.', $errors);
    }

    /**
     * @param array<string, string> $input
     * @param array<string, bool>   $options
     */
    private function console(array $input, array $options = []): ApplicationTester
    {
        $kernel = Kernel::fromConfig(new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => 'test']), CoreModules::candidates());
        $application = $kernel->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run($input, $options);

        return $tester;
    }
}
