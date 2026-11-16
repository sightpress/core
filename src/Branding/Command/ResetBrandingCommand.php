<?php

declare(strict_types=1);

namespace SightPress\Core\Branding\Command;

use SightPress\Core\Branding\BrandAsset;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:branding:reset <slug> [--logo] [--icon] [--share-image]`: goes back to the generated pictures, for the
 * ones named or for all three.
 */
final readonly class ResetBrandingCommand implements Command
{
    private const array FLAGS = ['logo' => BrandAsset::Logo, 'icon' => BrandAsset::Icon, 'share-image' => BrandAsset::ShareImage];

    public function __construct(private SiteBrandings $brandings)
    {
    }

    public function name(): string
    {
        return 'site:branding:reset';
    }

    public function description(): string
    {
        return 'Goes back to the generated logo, icon or share image (all three unless some are named).';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('logo', 'Reset the logo.', CommandParameterType::Flag),
            new CommandParameter('icon', 'Reset the icon.', CommandParameterType::Flag),
            new CommandParameter('share-image', 'Reset the share image.', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $branding = $this->brandings->forSlug($input->argument('slug'));
        if (is_string($branding)) {
            $output->error($branding);

            return self::FAILURE;
        }
        $assets = array_values(array_filter(self::FLAGS, static fn (string $flag): bool => $input->flag($flag), ARRAY_FILTER_USE_KEY));
        foreach ($assets === [] ? array_values(self::FLAGS) : $assets as $asset) {
            $branding->reset($asset);
            $output->line(sprintf('The %s is the generated one again.', $asset->label()));
        }

        return self::SUCCESS;
    }
}
