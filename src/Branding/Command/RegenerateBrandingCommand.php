<?php

declare(strict_types=1);

namespace SightPress\Core\Branding\Command;

use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;

/**
 * `site:branding:regenerate <slug>`: makes the generated pictures again (after the site was renamed, for example);
 * uploaded ones stay.
 */
final readonly class RegenerateBrandingCommand implements Command
{
    public function __construct(private SiteBrandings $brandings)
    {
    }

    public function name(): string
    {
        return 'site:branding:regenerate';
    }

    public function description(): string
    {
        return 'Makes the generated logo, icon and share image again from the site\'s name; uploaded pictures stay.';
    }

    public function parameters(): array
    {
        return [new CommandParameter('slug', 'Slug of the site:', required: true)];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $branding = $this->brandings->forSlug($input->argument('slug'));
        if (is_string($branding)) {
            $output->error($branding);

            return self::FAILURE;
        }
        $branding->regenerate();
        $output->line('The generated pictures have been made again.');

        return self::SUCCESS;
    }
}
