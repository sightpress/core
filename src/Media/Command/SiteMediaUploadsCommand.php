<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Command;

use DateTimeZone;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\Upload\ChunkedUploads;
use SightPress\Core\Media\Upload\UploadSessions;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:media:uploads <slug> [--purge-expired]`: lists a site's uploads in progress, or removes the abandoned ones.
 * File names are personal data, so the list shows only ids, sizes and dates.
 */
final readonly class SiteMediaUploadsCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    public function name(): string
    {
        return 'site:media:uploads';
    }

    public function description(): string
    {
        return 'Lists the large uploads in progress on a site, or removes the abandoned ones.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('purge-expired', 'Removes uploads idle for 24 hours and records of uploads finished 7 days ago.', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = trim((string) $input->argument('slug'));
        $site = $this->sites->find($slug);
        if ($site === null) {
            $output->error(sprintf('There is no site "%s".', $slug));

            return self::FAILURE;
        }
        $services = $this->containers->forSite($site);
        if ($input->flag('purge-expired')) {
            $purged = Services::get($services, ChunkedUploads::class)->purgeExpired();
            $output->line(sprintf('Removed %d expired %s.', $purged, $purged === 1 ? 'upload' : 'uploads'));

            return self::SUCCESS;
        }
        $sessions = Services::get($services, UploadSessions::class)->all();
        if ($sessions === []) {
            $output->line('No uploads in progress.');
        }
        foreach ($sessions as $session) {
            $output->line(sprintf(
                '%s: %s of %s, %s, last active %s UTC.',
                $session->id,
                MediaLimits::describe($session->receivedBytes),
                MediaLimits::describe($session->totalBytes),
                $session->isComplete() ? 'finished' : 'in progress',
                $session->updatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'),
            ));
        }

        return self::SUCCESS;
    }
}
