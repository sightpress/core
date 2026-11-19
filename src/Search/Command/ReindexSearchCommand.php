<?php

declare(strict_types=1);

namespace SightPress\Core\Search\Command;

use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Search\SearchIndex;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:search:reindex <slug> [--locale=]`: builds a site's search index again from its published content.
 */
final readonly class ReindexSearchCommand extends SiteSearchCommand
{
    public function name(): string
    {
        return 'site:search:reindex';
    }

    public function description(): string
    {
        return 'Builds the search index of a site again from its published pages (and what other modules add to search).';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('locale', 'Only this language of the site, for example pt-PT.', CommandParameterType::Option),
        ];
    }

    protected function work(Site $site, ContainerInterface $services, ?string $locale, CommandInput $input, CommandOutput $output): int
    {
        $counts = Services::get($services, SearchIndex::class)->reindex($locale);
        foreach ($counts as $in => $count) {
            $output->line(sprintf('%s: %d %s indexed.', $in, $count, $count === 1 ? 'document' : 'documents'));
        }

        return self::SUCCESS;
    }
}
