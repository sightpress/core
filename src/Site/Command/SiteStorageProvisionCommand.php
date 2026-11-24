<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Infrastructure\Storage\Provisioning\ProvisioningException;
use SightPress\Core\Infrastructure\Storage\Provisioning\StorageProvisioning;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:storage:provision <slug> --provider=aws|cloudflare [--region=] [--bucket=] [--domain=] [--dry-run]`: creates a
 * site's bucket, upload rules and CDN at a provider. `--dry-run` prints the plan and contacts nobody.
 */
final readonly class SiteStorageProvisionCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private StorageProvisioning $provisioning,
        private SiteContainerFactory $containers,
        private LoggerFactory $loggers,
    ) {
    }

    public function name(): string
    {
        return 'site:storage:provision';
    }

    public function description(): string
    {
        return 'Creates a site\'s cloud bucket, upload rules and CDN at AWS or Cloudflare. Use --dry-run to see the plan first.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('provider', 'aws or cloudflare.', CommandParameterType::Option),
            new CommandParameter('region', 'AWS region, or R2 jurisdiction (eu, us, default). The default follows the site\'s time zone.', CommandParameterType::Option),
            new CommandParameter('bucket', 'Name of the bucket.', CommandParameterType::Option),
            new CommandParameter('domain', 'Cloudflare only: host name for a separate public bucket.', CommandParameterType::Option),
            new CommandParameter('dry-run', 'Print the plan. Nothing is created and no provider is contacted.', CommandParameterType::Flag),
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
        $provider = (string) $input->option('provider');
        try {
            $request = $this->provisioning->request($site, $provider, $input->option('region'), $input->option('bucket'), $input->option('domain'));
            $plan = $this->provisioning->plan($site, $provider, $request);
            $output->line(sprintf('Provider: %s. Location: %s.', $plan->provider, $plan->location));
            foreach ($plan->steps as $step) {
                $output->line('- ' . $step);
            }
            $output->line((string) $plan->transfer);
            if ($input->flag('dry-run')) {
                $output->line('Dry run: nothing was created and no provider was contacted.');

                return self::SUCCESS;
            }
            $result = $this->provisioning->provision($provider, $request);
        } catch (ProvisioningException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }
        Services::get($this->containers->forSite($site), EventDispatcherInterface::class)->dispatch(new SettingsChanged([StorageProvisioning::SETTING], null));
        $this->loggers->create('sites')->notice(sprintf('Site "%s" storage provisioned.', $slug), ['site' => $slug, 'provider' => $provider, 'bucket' => $request->bucket, 'region' => $request->region]);
        $output->line(sprintf('Created: %s. Already there: %s.', $result->created === [] ? 'nothing' : implode(', ', $result->created), $result->unchanged === [] ? 'nothing' : implode(', ', $result->unchanged)));
        if ($result->cdnHost !== null) {
            $output->line(sprintf('The CDN will answer at https://%s once it is deployed. The site doesn\'t use it until you run site:storage:cdn.', $result->cdnHost));
        }
        $output->line(sprintf('Next: create keys limited to this bucket, then connect it with site:storage:s3 %s --bucket=%s.', $slug, $request->bucket));

        return self::SUCCESS;
    }
}
