<?php

declare(strict_types=1);

namespace SightPress\Core\Seo\Command;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Seo\RobotsPolicy;
use SightPress\Core\Seo\SeoFiles;
use SightPress\Core\Seo\SeoMeta;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;

/**
 * `site:seo:check <slug>`: what search engines will find on a site. Two pages with the same title are a problem
 * (exit code 1); titles over 60 characters, descriptions that are missing (the page's first words are used) or over
 * 160 characters, pages left out of search and an install that isn't in production are reported.
 */
final readonly class CheckSeoCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    public function name(): string
    {
        return 'site:seo:check';
    }

    public function description(): string
    {
        return 'Checks what search engines will find on a site: titles, descriptions and pages left out.';
    }

    public function parameters(): array
    {
        return [new CommandParameter('slug', 'Slug of the site:', required: true)];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = trim((string) $input->argument('slug'));
        $site = $this->sites->find($slug);
        if ($site === null || $site->status === SiteStatus::Deleted) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }
        $services = $this->containers->forSite($site);
        $files = Services::get($services, SeoFiles::class);
        $problems = 0;
        if (!Services::get($services, RobotsPolicy::class)->isProduction()) {
            $output->line('Note: this install is not in production (APP_ENV), so every page asks search engines not to index it.');
        }

        $pages = $files->indexable();
        $titles = [];
        foreach ($pages as $page) {
            $title = SeoMeta::title($page->seoTitle, $page->title, $site->name);
            $titles[$page->locale][mb_strtolower($title)][] = $page->url;
            if (mb_strlen($title) > SeoMeta::TITLE_MAX) {
                $output->line(sprintf('Warning: %s: the title for search engines has %d characters; they show about %d.', $page->url, mb_strlen($title), SeoMeta::TITLE_MAX));
            }
            $description = $page->description ?? $page->summary;
            if ($description === null) {
                $output->line(sprintf('Warning: %s has no description or summary; search engines get its first words.', $page->url));
            } elseif (mb_strlen($description) > SeoMeta::DESCRIPTION_MAX) {
                $output->line(sprintf('Warning: %s: the description has %d characters and is cut at %d.', $page->url, mb_strlen($description), SeoMeta::DESCRIPTION_MAX));
            }
        }
        foreach ($titles as $byTitle) {
            foreach ($byTitle as $urls) {
                if (count($urls) > 1) {
                    ++$problems;
                    $output->error(sprintf('These pages have the same title, so search engines can\'t tell them apart: %s.', implode(', ', $urls)));
                }
            }
        }
        foreach ($files->published() as $page) {
            if (!$page->isIndexable()) {
                $output->line(sprintf('Left out of search: %s (%s).', $page->url, match (true) {
                    !$page->public => 'members only',
                    $page->noindex => 'noindex',
                    default => 'a copy of ' . $page->canonicalUrl,
                }));
            }
        }
        $output->line(sprintf('%d %s in the sitemap; %s.', count($pages), count($pages) === 1 ? 'page' : 'pages', $problems === 0 ? 'no problems found' : ($problems === 1 ? '1 problem to fix' : $problems . ' problems to fix')));

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
