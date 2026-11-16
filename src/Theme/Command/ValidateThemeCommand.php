<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Command;

use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Theme\ThemeException;
use SightPress\Core\Theme\ThemeLoader;
use SightPress\Core\Theme\ThemeValidator;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;

/**
 * `theme:validate [<path>]`: checks a theme folder (by default the active theme) — its `theme.json`, the contrast
 * of every colour pair it declares in all four colour modes, and its base font size — and says what to fix.
 */
final readonly class ValidateThemeCommand implements Command
{
    public function __construct(
        private ThemeLoader $loader,
        private ThemeValidator $validator,
        private TemplatePaths $paths,
    ) {
    }

    public function name(): string
    {
        return 'theme:validate';
    }

    public function description(): string
    {
        return 'Checks a theme\'s colour contrast and base font size, and says what to fix.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('path', 'Folder of the theme to check; leave it out to check the active theme.'),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $path = trim($input->argument('path') ?? '');
        if ($path === '') {
            $path = $this->paths->themes[0] ?? TemplatePaths::defaultTheme();
        }

        try {
            $theme = $this->loader->load($path);
        } catch (ThemeException $exception) {
            $output->error('The theme can\'t be loaded. ' . $exception->getMessage());

            return self::FAILURE;
        }

        $result = $this->validator->validate($theme);
        if ($result->passes()) {
            $output->line(sprintf(
                'The theme "%s" passes: %d colour pairs checked in light and dark mode, with and without more contrast, '
                . 'and the base font size is at least 16 px.',
                $theme->label,
                $result->checks,
            ));

            return self::SUCCESS;
        }

        $count = count($result->issues);
        $output->error(sprintf('The theme "%s" fails with %d %s:', $theme->label, $count, $count === 1 ? 'problem' : 'problems'));
        foreach ($result->issues as $number => $issue) {
            $output->error(sprintf('%d. %s', $number + 1, $issue->message));
        }

        return self::FAILURE;
    }
}
