<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Command;

use InvalidArgumentException;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteMediaLimits;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * `site:media:limits <slug> [--image=] [--video=] [--audio=] [--document=] [--pixels=] [--reset]`: shows or changes a
 * site's own upload limits, stored with the site in `config/sites.php`. A value above the tenant's ceiling
 * (`media.limits.max`) is refused here, not at upload time. A change goes to the `sites` log and the site's audit log.
 */
final readonly class SiteMediaLimitsCommand implements Command
{
    public const string SETTING = 'media.limits';

    private const array OPTIONS = ['image', 'video', 'audio', 'document', 'pixels'];

    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
        private TenantConfig $config,
        private LoggerFactory $loggers,
    ) {
    }

    public function name(): string
    {
        return 'site:media:limits';
    }

    public function description(): string
    {
        return 'Shows or changes the largest files a site accepts, up to the limit set for the whole install.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('image', 'Largest picture, for example 20M.', CommandParameterType::Option),
            new CommandParameter('video', 'Largest video, for example 1G.', CommandParameterType::Option),
            new CommandParameter('audio', 'Largest audio file, for example 200M.', CommandParameterType::Option),
            new CommandParameter('document', 'Largest document, for example 50M.', CommandParameterType::Option),
            new CommandParameter('pixels', 'Most pixels in a picture (width × height), for example 25000000.', CommandParameterType::Option),
            new CommandParameter('reset', 'Goes back to the limits of the whole install; other values given are then applied.', CommandParameterType::Flag),
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

        $given = [];
        foreach (self::OPTIONS as $option) {
            $value = $input->option($option);
            if ($value !== null) {
                $given[$option] = trim($value);
            }
        }
        $reset = $input->flag('reset');
        if ($given === [] && !$reset) {
            $this->show($site, $output);

            return self::SUCCESS;
        }

        try {
            $override = SiteMediaLimits::fromArray($given);
        } catch (InvalidArgumentException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }
        $limits = ($reset ? new SiteMediaLimits() : $site->mediaLimits)->merge($override);
        $above = $this->config->mediaLimits->aboveCeiling($limits);
        if ($above !== null) {
            [$key, $value, $ceiling] = $above;
            $output->error(sprintf(
                'The %s limit of %s is above the most this install allows (%s). Choose a lower value, or raise media.limits.max.%s in config/tenant.php.',
                $key,
                self::value($key, $value),
                self::value($key, $ceiling),
                $key,
            ));

            return self::FAILURE;
        }

        if ($limits->toArray() === $site->mediaLimits->toArray()) {
            $output->line('Nothing was changed.');
            $this->show($site, $output);

            return self::SUCCESS;
        }
        $changed = $site->withMediaLimits($limits);
        $this->sites->save($changed);
        $this->containers->evict($slug);
        Services::get($this->containers->forSite($changed), EventDispatcherInterface::class)->dispatch(new SettingsChanged([self::SETTING], null));
        $this->loggers->create('sites')->notice(sprintf('Site "%s" upload limits changed.', $slug), ['site' => $slug, 'limits' => $limits->toArray()]);
        $output->line(sprintf('The upload limits of "%s" have been saved.', $slug));
        $this->show($changed, $output);
        $output->line('Check that the server allows them with system:check --site=' . $slug . ' --recommend.');

        return self::SUCCESS;
    }

    private function show(Site $site, CommandOutput $output): void
    {
        $limits = $this->config->mediaLimits->forSite($site);
        $own = $site->mediaLimits->toArray();
        $output->line(sprintf('Upload limits of "%s":', $site->slug));
        foreach ([
            'image' => ['Pictures', $limits->imageBytes],
            'video' => ['Videos', $limits->videoBytes],
            'audio' => ['Audio files', $limits->audioBytes],
            'document' => ['Documents', $limits->documentBytes],
            'pixels' => ['Pixels in a picture', $limits->imagePixels],
        ] as $key => [$label, $value]) {
            $output->line(sprintf('  %s: %s (%s)', $label, self::value($key, $value), isset($own[$key]) ? 'this site' : 'install default'));
        }
    }

    private static function value(string $key, int $value): string
    {
        return $key === 'pixels' ? number_format($value, 0, '.', ',') : MediaLimits::describe($value);
    }
}
