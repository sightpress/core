<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;

/**
 * Moves a site to another status. Every status can be reached from every other one: deleting is soft, so
 * `site:enable` brings a deleted site back; only `site:purge` removes a site for good. Each change is written to the
 * `sites` log.
 */
abstract readonly class SiteStatusCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
        private LoggerFactory $loggers,
    ) {
    }

    abstract protected function status(): SiteStatus;

    /**
     * What visitors get now, after "The site "x" is <status>:".
     */
    abstract protected function consequence(Site $site): string;

    public function name(): string
    {
        return match ($this->status()) {
            SiteStatus::Enabled => 'site:enable',
            SiteStatus::Disabled => 'site:disable',
            SiteStatus::Archived => 'site:archive',
            SiteStatus::Deleted => 'site:delete',
        };
    }

    public function parameters(): array
    {
        return [new CommandParameter('slug', 'Slug of the site:', required: true)];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = $input->argument('slug') ?? '';
        $site = $this->sites->find($slug);
        if ($site === null) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }

        $status = $this->status();
        if ($site->status === $status) {
            $output->line(sprintf('The site "%s" is already %s.', $slug, $status->value));

            return self::SUCCESS;
        }

        $changed = $site->withStatus($status);
        $this->sites->save($changed);
        $this->containers->evict($slug);
        $this->loggers->create('sites')->notice(
            sprintf('Site "%s" status changed from %s to %s.', $slug, $site->status->value, $status->value),
            ['site' => $slug, 'from' => $site->status->value, 'to' => $status->value],
        );
        $output->line(sprintf('The site "%s" is %s: %s', $slug, $status->value, $this->consequence($changed)));

        return self::SUCCESS;
    }
}
