<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Command;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use SightPress\Core\Analytics\AnalyticsRetention;
use SightPress\Core\Analytics\AnalyticsRollup;
use SightPress\Core\Analytics\AnalyticsStore;
use SightPress\Core\Analytics\Report\DateRange;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:analytics:purge <slug> [--before=YYYY-MM-DD]`: deletes a site's analytics from before a day; without
 * `--before`, what is older than the site's retention (as the hourly roll-up does).
 */
final readonly class AnalyticsPurgeCommand extends SiteAnalyticsCommand
{
    public function name(): string
    {
        return 'site:analytics:purge';
    }

    public function description(): string
    {
        return 'Deletes a site\'s analytics from before a day (by default, what is older than the site keeps them).';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('before', 'Delete everything before this day, as YYYY-MM-DD in the site\'s time zone (default: older than the retention).', CommandParameterType::Option),
        ];
    }

    protected function work(ContainerInterface $services, CommandInput $input, CommandOutput $output): int
    {
        $before = trim((string) $input->option('before'));
        if ($before !== '' && !DateRange::isDay($before)) {
            $output->error(sprintf('"%s" isn\'t a date written as YYYY-MM-DD.', $before));

            return self::FAILURE;
        }
        if ($before === '') {
            $today = Services::get($services, AnalyticsRollup::class)->today(Services::get($services, ClockInterface::class)->now());
            $retention = Services::get($services, AnalyticsRetention::class);
            $before = $retention->firstKeptDay($today);
            $output->line(sprintf('The site keeps analytics for %d %s.', $retention->months(), $retention->months() === 1 ? 'month' : 'months'));
        }
        $deleted = Services::get($services, AnalyticsStore::class)->deleteBefore($before);
        $output->line(sprintf('Deleted %d daily %s from before %s.', $deleted, $deleted === 1 ? 'figure' : 'figures', $before));

        return self::SUCCESS;
    }
}
