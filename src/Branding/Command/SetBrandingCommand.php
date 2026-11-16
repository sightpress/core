<?php

declare(strict_types=1);

namespace SightPress\Core\Branding\Command;

use SightPress\Core\Branding\BrandAsset;
use SightPress\Core\Branding\BrandingException;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:branding:set <slug> --logo= --icon= --share-image=`: uses picture files for a site's logo, icon or share image.
 * Each file is checked as it would be in the admin; one that can't be used is explained and nothing else changes.
 */
final readonly class SetBrandingCommand implements Command
{
    private const array OPTIONS = ['logo' => BrandAsset::Logo, 'icon' => BrandAsset::Icon, 'share-image' => BrandAsset::ShareImage];

    public function __construct(private SiteBrandings $brandings)
    {
    }

    public function name(): string
    {
        return 'site:branding:set';
    }

    public function description(): string
    {
        return 'Uses picture files for a site\'s logo, icon or share image.';
    }

    public function parameters(): array
    {
        $option = CommandParameterType::Option;

        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('logo', 'Logo: an SVG, PNG, JPEG or WebP file, from 1:1 to 8:1 wide.', $option),
            new CommandParameter('logo-alt', 'What the logo says or shows, for screen readers (default: the site name).', $option),
            new CommandParameter('icon', 'Icon: a square PNG, JPEG or WebP file of at least 512 × 512 pixels.', $option),
            new CommandParameter('share-image', 'Share image: a PNG, JPEG or WebP file of 1200 × 630 pixels (or larger, same proportions).', $option),
            new CommandParameter('share-image-alt', 'What the share image shows, for screen readers (default: the site name).', $option),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $branding = $this->brandings->forSlug($input->argument('slug'));
        if (is_string($branding)) {
            $output->error($branding);

            return self::FAILURE;
        }
        $files = [];
        foreach (self::OPTIONS as $option => $asset) {
            $path = trim($input->option($option) ?? '');
            if ($path === '') {
                continue;
            }
            $bytes = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            if ($bytes === false) {
                $output->error(sprintf('The file %s can\'t be read.', $path));

                return self::INVALID;
            }
            $files[$option] = [$asset, $bytes];
        }
        if ($files === []) {
            $output->error('Give at least one file: --logo, --icon or --share-image.');

            return self::INVALID;
        }

        foreach ($files as $option => [$asset, $bytes]) {
            try {
                $branding->set($asset, $bytes, $input->option($option . '-alt'));
            } catch (BrandingException $exception) {
                $output->error($exception->getMessage());

                return self::FAILURE;
            }
            $output->line(sprintf('The %s has been changed.', $asset->label()));
        }

        return self::SUCCESS;
    }
}
