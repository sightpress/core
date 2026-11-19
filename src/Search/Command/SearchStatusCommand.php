<?php

declare(strict_types=1);

namespace SightPress\Core\Search\Command;

use DateTimeZone;
use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Search\SearchIndex;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;

/**
 * `site:search:status <slug>`: how many documents the index holds in each language and when it last changed.
 */
final readonly class SearchStatusCommand extends SiteSearchCommand
{
    public function name(): string
    {
        return 'site:search:status';
    }

    public function description(): string
    {
        return 'Shows how many documents the search index of a site holds in each language, and whether it needs to be built again.';
    }

    public function parameters(): array
    {
        return [new CommandParameter('slug', 'Slug of the site:', required: true)];
    }

    protected function work(Site $site, ContainerInterface $services, ?string $locale, CommandInput $input, CommandOutput $output): int
    {
        $index = Services::get($services, SearchIndex::class);
        $status = [];
        foreach ($index->backend()->status() as $entry) {
            $status[$entry->locale] = $entry;
        }
        foreach ($site->locales as $in) {
            $entry = $status[$in] ?? null;
            $count = $entry->documents ?? 0;
            $output->line(sprintf(
                '%s: %d %s%s.',
                $in,
                $count,
                $count === 1 ? 'document' : 'documents',
                $entry?->lastIndexedAt === null ? '' : ', last indexed ' . $entry->lastIndexedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC',
            ));
        }
        if (!$index->isReady()) {
            $output->line(sprintf('The index is out of date: schedule:run builds it again within a minute, or run site:search:reindex %s now.', $site->slug));
        }

        return self::SUCCESS;
    }
}
