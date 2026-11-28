<?php

declare(strict_types=1);

namespace SightPress\Core\Privacy\Command;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Privacy\PrivacyPurge;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;

/**
 * `site:privacy:purge <site>` (GD-2): deletes now what the site keeps longer than its retention, as `schedule:run`
 * does once a day. Prints counts only.
 */
final readonly class PurgeCommand extends SitePrivacyCommand
{
    public function name(): string
    {
        return 'site:privacy:purge';
    }

    public function description(): string
    {
        return 'Deletes the personal data a site keeps longer than its retention (schedule:run does it once a day).';
    }

    public function parameters(): array
    {
        return [new CommandParameter('slug', 'Slug of the site:', required: true)];
    }

    protected function work(Site $site, ContainerInterface $services, CommandInput $input, CommandOutput $output): int
    {
        $counts = Services::get($services, PrivacyPurge::class)->run(Services::get($services, ClockInterface::class)->now());
        foreach ($counts as $kind => $count) {
            $output->line(sprintf('%s: %d deleted.', ucfirst($kind), $count));
        }
        $output->line(sprintf('The personal data of "%s" is within its retention.', $site->slug));

        return self::SUCCESS;
    }
}
