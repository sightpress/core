<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Command;

use SightPress\Core\Cli\ThemePreview\PreviewSite;
use SightPress\Core\Theme\Preview\PreviewFolder;
use SightPress\Core\Theme\Preview\PreviewTheme;
use SightPress\Core\Theme\Preview\PreviewTools;
use SightPress\Core\Theme\PublishedAssets;
use SightPress\Core\Theme\PublishedPreviews;
use SightPress\Core\Theme\ThemeException;
use SightPress\Core\Theme\ThemeRegistry;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `theme:publish-assets [--no-screenshot]`: writes every installed theme's static files under a checksum folder
 * ({@see PublishedAssets}, used with a CDN) and its preview page to the public folder
 * (`public/themes/<id>/<version>/preview/`, gap 8), for the theme picker: the static page and, where Node.js and
 * Playwright's Chromium are installed, its screenshots. Without them the picker shows each theme's description in
 * words instead of a picture. Run after installing or updating themes (the skeleton runs it after `composer install`
 * and `composer update`).
 */
final readonly class PublishThemeAssetsCommand implements Command
{
    /**
     * @param string|null $workspace folder for the throwaway sites (default: a new temporary one)
     */
    public function __construct(
        private ThemeRegistry $registry,
        private PublishedPreviews $previews,
        private PreviewTools $tools = new PreviewTools(),
        private ?string $workspace = null,
    ) {
    }

    public function name(): string
    {
        return 'theme:publish-assets';
    }

    public function description(): string
    {
        return 'Writes the preview page and screenshots of every installed theme to the public folder, for the theme picker.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('no-screenshot', 'Write only the preview pages, without screenshots.', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $status = self::SUCCESS;
        foreach ($this->registry->all() as $theme) {
            $manifest = $theme->manifest();
            $folder = $this->previews->folder($manifest);
            $workspace = $this->workspace ?? sys_get_temp_dir() . '/sightpress-publish-' . bin2hex(random_bytes(6));
            try {
                @mkdir($workspace, 0o777, true);
                new PublishedAssets($this->previews->config())->publish($manifest, $theme->paths()->root . '/assets');
                new PreviewFolder($folder)->write(new PreviewSite($workspace)->render(PreviewTheme::fromDirectory($theme->paths()->root), 'en-GB'));
            } catch (ThemeException $exception) {
                $output->error(sprintf('The preview of "%s" could not be published. %s', $manifest->name, $exception->getMessage()));
                $status = self::FAILURE;

                continue;
            } finally {
                PreviewFolder::remove($workspace);
            }
            $problem = $input->flag('no-screenshot') ? null : $this->tools->screenshots($folder);
            $output->line(sprintf('Published the preview of "%s" %s in %s%s', $manifest->name, $manifest->version, $folder, match (true) {
                $input->flag('no-screenshot') => ', without screenshots.',
                $problem === null => ', with screenshots.',
                default => '. No screenshots: ' . $problem . ' The theme picker shows the theme\'s description instead.',
            }));
        }

        return $status;
    }
}
