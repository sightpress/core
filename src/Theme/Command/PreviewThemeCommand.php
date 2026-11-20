<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Command;

use SightPress\Core\Cli\ThemePreview\PreviewSite;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Theme\Preview\PreviewFolder;
use SightPress\Core\Theme\Preview\PreviewTools;
use SightPress\Core\Theme\Preview\PreviewTheme;
use SightPress\Core\Theme\ThemeException;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `theme:preview <theme|path> [--out=] [--locale=] [--screenshot]`: renders `theme-interface`'s sample page with the
 * theme applied to a static folder (`index.html`, `assets/`, `media/` and the site's icons, all with relative
 * addresses) that opens from `file://` or any static host, with no PHP, no JavaScript and no request leaving the
 * folder. The same theme always gives the same bytes. `--screenshot` also writes `screenshot.png`,
 * `screenshot-dark.png` (1280×800, the top of the page) and `page.png` (the whole page).
 */
final readonly class PreviewThemeCommand implements Command
{
    /**
     * @param non-empty-string|null $workspace the throwaway tenant's folder (default: a new one in the temporary folder)
     */
    public function __construct(
        private PreviewTools $tools = new PreviewTools(),
        private ?string $workspace = null,
    ) {
    }

    public function name(): string
    {
        return 'theme:preview';
    }

    public function description(): string
    {
        return 'Writes a theme\'s preview page as a static folder, and optionally its screenshots.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('theme', 'The theme: "default", or the folder of a theme (the one with theme.json).', required: true),
            new CommandParameter('out', 'Folder to write the preview to (default: build/theme-preview/<theme id>).', CommandParameterType::Option),
            new CommandParameter('locale', 'Language of the preview page (default: en-GB).', CommandParameterType::Option, default: 'en-GB'),
            new CommandParameter('screenshot', 'Also take the screenshots (needs Node.js and Playwright\'s Chromium).', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $argument = trim($input->argument('theme') ?? '');
        $directory = $argument === 'default' ? TemplatePaths::defaultTheme() : $argument;
        $locale = trim($input->option('locale') ?? '');
        $locale = $locale === '' ? 'en-GB' : $locale;
        $workspace = $this->workspace ?? sys_get_temp_dir() . '/sightpress-preview-' . bin2hex(random_bytes(6));

        try {
            $theme = PreviewTheme::fromDirectory($directory);
            $out = trim($input->option('out') ?? '');
            $out = $out === '' ? 'build/theme-preview/' . $theme->id : $out;
            @mkdir($workspace, 0o777, true);
            $files = new PreviewSite($workspace)->render($theme, $locale);
            new PreviewFolder($out)->write($files);
        } catch (ThemeException $exception) {
            $output->error('The preview could not be made. ' . $exception->getMessage());

            return self::FAILURE;
        } finally {
            PreviewFolder::remove($workspace);
        }

        $output->line(sprintf('The preview of "%s" %s is in %s: open index.html in any browser; it works offline.', $theme->name, $theme->version, $out));
        if ($theme->previewDescription === null) {
            $output->line('The theme has no preview description: add previewDescription to its manifest, the text people get instead of the screenshot.');
        }
        if (!$input->flag('screenshot')) {
            return self::SUCCESS;
        }
        $problem = $this->tools->screenshots($out);
        if ($problem !== null) {
            $output->error($problem);

            return self::FAILURE;
        }
        $output->line(sprintf('Screenshots: %s.', implode(', ', PreviewTools::SCREENSHOTS)));

        return self::SUCCESS;
    }
}
