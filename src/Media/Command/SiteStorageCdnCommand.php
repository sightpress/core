<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Command;

use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRegistryException;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:storage:cdn <slug> [--url=] [--remove]`: shows, sets or removes the CDN that delivers a site's public files.
 */
final readonly class SiteStorageCdnCommand implements Command
{
    public const string SETTING = 'storage.cdn_url';

    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
        private LoggerFactory $loggers,
    ) {
    }

    public function name(): string
    {
        return 'site:storage:cdn';
    }

    public function description(): string
    {
        return 'Shows, sets or removes the CDN address that delivers a site\'s files.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('url', 'The CDN address, for example https://cdn.example.org.', CommandParameterType::Option),
            new CommandParameter('remove', 'Stop using a CDN: the site delivers its files itself.', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = trim((string) $input->argument('slug'));
        $site = $this->sites->find($slug);
        if ($site === null || $site->status === SiteStatus::Deleted) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }
        $url = $input->option('url');
        $remove = $input->flag('remove');
        if ($url !== null && $remove) {
            $output->error('Use either --url or --remove.');

            return self::FAILURE;
        }
        if ($url === null && !$remove) {
            $output->line($site->storage->cdnUrl === null
                ? sprintf('The site "%s" delivers its files itself.', $slug)
                : sprintf('The site "%s" delivers its files through %s.', $slug, $site->storage->cdnUrl));

            return self::SUCCESS;
        }

        try {
            $storage = $site->storage->withCdn($remove ? null : rtrim(trim((string) $url), '/'));
        } catch (SiteRegistryException $exception) {
            $output->error($exception->getMessage() . ' Nothing was changed.');

            return self::FAILURE;
        }
        if ($storage->cdnUrl === $site->storage->cdnUrl) {
            $output->line('Nothing was changed.');

            return self::SUCCESS;
        }
        $changed = $site->withStorage($storage);
        $this->sites->save($changed);
        $this->containers->evict($slug);
        Services::get($this->containers->forSite($changed), EventDispatcherInterface::class)->dispatch(new SettingsChanged([self::SETTING], null));
        $this->loggers->create('sites')->notice(sprintf('Site "%s" CDN changed.', $slug), ['site' => $slug, 'cdn' => $storage->cdnUrl]);
        $output->line($storage->cdnUrl === null
            ? sprintf('The site "%s" now delivers its files itself.', $slug)
            : sprintf('The site "%s" now delivers its files through %s. Run theme:publish-assets so its theme files are there too.', $slug, $storage->cdnUrl));

        return self::SUCCESS;
    }
}
