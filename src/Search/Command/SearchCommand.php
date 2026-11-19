<?php

declare(strict_types=1);

namespace SightPress\Core\Search\Command;

use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Search\SiteSearch;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:search <slug> <text> [--locale=] [--members]`: searches a site like a visitor would, to check the index.
 * Not counted as a visitor's search.
 */
final readonly class SearchCommand extends SiteSearchCommand
{
    public function name(): string
    {
        return 'site:search';
    }

    public function description(): string
    {
        return 'Searches a site the way a visitor would, and lists the first results.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('text', 'What to search for:', required: true, label: 'Search for'),
            new CommandParameter('locale', 'Search in this language of the site, for example pt-PT; the main language by default.', CommandParameterType::Option),
            new CommandParameter('members', 'Search as someone signed in, who also finds members-only pages.', CommandParameterType::Flag),
        ];
    }

    protected function work(Site $site, ContainerInterface $services, ?string $locale, CommandInput $input, CommandOutput $output): int
    {
        $query = SiteSearch::query((string) $input->argument('text'), $locale ?? $site->defaultLocale, $input->flag('members'));
        if ($query === null) {
            $output->error('Type something to search for.');

            return self::FAILURE;
        }
        $results = Services::get($services, SiteSearch::class)->search($query, count: false);
        if ($results->isEmpty()) {
            $output->line(sprintf('No results for "%s" in %s.', $query->text, $query->locale));

            return self::SUCCESS;
        }
        $output->line(sprintf(
            '%d %s for "%s" in %s%s:',
            $results->total,
            $results->total === 1 ? 'result' : 'results',
            $query->text,
            $query->locale,
            $results->matchedAllTerms ? '' : ' (no page has every word, so these have some of them)',
        ));
        foreach ($results->hits as $number => $hit) {
            $output->line(sprintf('%d. %s — %s (score %.2f)', $number + 1, $hit->document->title, $hit->document->path, $hit->score));
        }

        return self::SUCCESS;
    }
}
