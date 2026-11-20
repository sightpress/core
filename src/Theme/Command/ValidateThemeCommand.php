<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Command;

use SightPress\Core\Cli\ThemePreview\PreviewSite;
use SightPress\Core\Theme\Preview\PreviewFolder;
use SightPress\Core\Theme\Preview\PreviewTheme;
use SightPress\Core\Theme\Preview\PreviewTools;
use SightPress\Core\Theme\ThemeChecker;
use SightPress\Core\Theme\ThemeException;
use SightPress\Core\Theme\ThemeRegistry;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;
use SightPress\Theme\ThemeInterface;

/**
 * `theme:validate [<theme>|<path>] [--axe]`: checks a theme the way it is checked before it is applied
 * ({@see ThemeChecker}): theme.json, contrast in every colour mode (charts included), the base font size, the
 * required templates and core's head partial, and that the package ships no screenshot or preview output. Without an
 * argument it checks every installed theme. `--axe` also renders the theme's preview page and scans it with axe in
 * light, dark and forced-colors mode (needs Node.js and Playwright's Chromium).
 */
final readonly class ValidateThemeCommand implements Command
{
    /**
     * @param string|null $workspace folder for the preview the axe scan needs (default: a new temporary one)
     */
    public function __construct(
        private ThemeRegistry $registry,
        private ThemeChecker $checker = new ThemeChecker(),
        private PreviewTools $tools = new PreviewTools(),
        private ?string $workspace = null,
    ) {
    }

    public function name(): string
    {
        return 'theme:validate';
    }

    public function description(): string
    {
        return 'Checks a theme as it is checked before it is applied, and says what to fix.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('theme', 'Id of an installed theme, or the folder of a theme; leave it out to check every installed theme.'),
            new CommandParameter('axe', 'Also scan the theme\'s preview page with axe (needs Node.js and Playwright\'s Chromium).', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $argument = trim($input->argument('theme') ?? '');
        $themes = match (true) {
            $argument === '' => $this->registry->all(),
            $this->registry->find($argument) !== null => [$this->registry->find($argument)],
            default => [$argument],
        };

        $passes = true;
        foreach ($themes as $theme) {
            $passes = $this->validate($theme, $input->flag('axe'), $output) && $passes;
        }

        return $passes ? self::SUCCESS : self::FAILURE;
    }

    private function validate(ThemeInterface|string $theme, bool $axe, CommandOutput $output): bool
    {
        $check = $this->checker->check($theme);
        foreach ($check->warnings as $warning) {
            $output->line(sprintf('Warning for "%s": %s', $check->name, $warning));
        }
        $unavailable = $theme instanceof ThemeInterface ? $this->registry->unavailable($theme) : null;
        $problems = $unavailable === null ? $check->problems : [$unavailable, ...$check->problems];
        if ($problems !== []) {
            $count = count($problems);
            $output->error(sprintf('The theme "%s" fails with %d %s:', $check->name, $count, $count === 1 ? 'problem' : 'problems'));
            foreach ($problems as $number => $problem) {
                $output->error(sprintf('%d. %s', $number + 1, $problem));
            }

            return false;
        }
        $output->line(sprintf(
            'The theme "%s" passes: %d colour pairs checked in light and dark mode, with and without more contrast; the base font size is at least 16 px; the required templates are there; it ships no screenshot.',
            $check->name,
            $check->checks,
        ));

        return !$axe || $this->scan($theme instanceof ThemeInterface ? $theme->paths()->root : $theme, $check->name, $output);
    }

    private function scan(string $directory, string $name, CommandOutput $output): bool
    {
        $workspace = $this->workspace ?? sys_get_temp_dir() . '/sightpress-validate-' . bin2hex(random_bytes(6));
        try {
            @mkdir($workspace . '/site', 0o777, true);
            new PreviewFolder($workspace . '/preview')->write(new PreviewSite($workspace . '/site')->render(PreviewTheme::fromDirectory($directory), 'en-GB'));
            [$passes, $report] = $this->tools->axe($workspace . '/preview');
        } catch (ThemeException $exception) {
            [$passes, $report] = [false, 'The preview page could not be made. ' . $exception->getMessage()];
        } finally {
            PreviewFolder::remove($workspace);
        }
        $passes
            ? $output->line(sprintf('The preview page of "%s" passes axe: %s', $name, str_replace("\n", '; ', $report)))
            : $output->error(sprintf('The preview page of "%s" fails axe: %s', $name, $report));

        return $passes;
    }
}
