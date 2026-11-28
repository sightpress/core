<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Command;

use RuntimeException;
use SightPress\Core\Http\Session\SessionStoreCheck;
use SightPress\Core\Http\Upload\ServerLimits;
use SightPress\Core\Http\Upload\ServerLimitsCheck;
use SightPress\Core\Http\Upload\ServerRecommendation;
use SightPress\Core\Http\Upload\UploadProbeClient;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\UploadAllowance;
use SightPress\Core\Media\UploadRefusals;
use SightPress\Core\Tenant\PolicyReport;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `system:check`: the server's upload and input limits, the largest file of each kind that can be uploaded, and
 * the settings to change ({@see ServerLimitsCheck}), and modules that are unavailable or named in `config/tenant.php`
 * without being installed ({@see SiteModules::problems()}), and sites and accounts outside the tenant's policies
 * ({@see PolicyReport}, as notes), and where sign-ins are kept and whether that store answers
 * ({@see SessionStoreCheck}). Exits with 1 when something must be fixed, for CI and
 * monitoring.
 *
 * Run it the way the web server runs PHP (in the skeleton: `docker compose exec php bin/sightpress system:check`):
 * the command line can have its own php.ini.
 */
final readonly class SystemCheckCommand implements Command
{
    public function __construct(
        private ServerLimits $server,
        private MediaLimits $limits,
        private ServerLimitsCheck $check,
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
        private TenantConfig $config,
        private UploadProbeClient $probe,
        private SiteModules $modules,
        private PolicyReport $policies,
        private SessionStoreCheck $sessions,
    ) {
    }

    public function name(): string
    {
        return 'system:check';
    }

    public function description(): string
    {
        return 'Checks the server\'s upload and input limits and says which settings to change.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('site', 'Check against the limits of this site (its slug), and count the uploads refused for it.', CommandParameterType::Option),
            new CommandParameter('probe-url', 'Send test requests to this address to find the web server\'s real limit, for example http://nginx.', CommandParameterType::Option),
            new CommandParameter('recommend', 'Print the server values the sites\' limits need.', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $server = $this->server;
        $limits = $this->limits;
        $check = $this->check;
        $refused = 0;
        $slug = trim((string) $input->option('site'));
        $site = null;
        if ($slug !== '') {
            $site = $this->sites->find($slug);
            if ($site === null || $site->status === SiteStatus::Deleted) {
                $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

                return self::FAILURE;
            }
            $limits = $this->config->mediaLimits->forSite($site);
            $check = new ServerLimitsCheck($limits, $server);
            $refused = Services::get($this->containers->forSite($site), UploadRefusals::class)->lastSevenDays();
        }
        $allowance = new UploadAllowance($limits, $server);
        $request = $server->requestLimit();

        $output->line('Upload limits');
        $output->line(sprintf('  Largest file in one request: %s', self::size($request)));
        $output->line(sprintf(
            '    upload_max_filesize %s, post_max_size %s, web server %s',
            self::size($server->uploadMaxFilesize),
            self::size($server->postMaxSize),
            $server->proxyBodyLimit === null ? 'not stated' : MediaLimits::describe($server->proxyBodyLimit),
        ));
        foreach (MediaKind::cases() as $kind) {
            $max = $allowance->maxBytes($kind);
            $line = sprintf('  %s: %s', ucfirst((string) UploadAllowance::plural($kind)), MediaLimits::describe($max));
            if ($allowance->limitedByServer($kind)) {
                $line .= sprintf(' (the site allows %s)', MediaLimits::describe($limits->maxBytes($kind)));
            }
            $output->line($line);
        }
        $output->line(sprintf(
            '  memory_limit %s, max_input_vars %d, max_file_uploads %d',
            self::size($server->memoryLimit),
            $server->maxInputVars,
            $server->maxFileUploads,
        ));
        $output->line(sprintf(
            '  Folder for files being uploaded: %s (%s)',
            $server->tempDir,
            match (true) {
                !$server->tempWritable => 'missing or not writable',
                $server->tempFree === null => 'free space unknown',
                default => MediaLimits::describe($server->tempFree) . ' free',
            },
        ));

        foreach ($check->notes() as $note) {
            $output->line('Note: ' . $note);
        }

        $problems = array_map(strval(...), $check->problems());
        // A note, not a problem: anyone can send one oversized request, and that mustn't turn monitoring red for a week.
        if ($refused > 0) {
            $output->line(sprintf('Note: The web server refused %d upload(s) for this site in the last 7 days because they were too large.', $refused));
        }
        $probeUrl = trim((string) $input->option('probe-url'));
        if ($probeUrl !== '') {
            $problem = $this->probe($probeUrl, $limits, $output);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        if ($input->flag('recommend')) {
            $this->recommend($site, $limits, $output);
        }
        // Sites and accounts outside a policy that was tightened keep working (X-5): notes, not problems.
        foreach ($this->policies->notes() as $note) {
            $output->line('Note: ' . $note);
        }
        // Modules that can't be used, and tenant settings naming a module that isn't installed (ADR-0065).
        array_push($problems, ...$this->modules->problems());
        $output->line('Sessions: ' . $this->sessions->describe());
        $sessionProblem = $this->sessions->problem();
        if ($sessionProblem !== null) {
            $problems[] = $sessionProblem;
        }
        foreach ($problems as $problem) {
            $output->error($problem);
        }
        if ($problems !== []) {
            return self::FAILURE;
        }
        $output->line('No problems found.');

        return self::SUCCESS;
    }

    /**
     * Measures the web server's limit and compares it with what `uploads.proxy_body_limit` states. Returns the problem
     * to report, if any.
     */
    private function probe(string $url, MediaLimits $limits, CommandOutput $output): ?string
    {
        $stated = $this->server->proxyBodyLimit;
        $largest = max(array_map($limits->maxBytes(...), MediaKind::cases()));
        try {
            $result = $this->probe->measure($url, ($stated ?? $largest + ServerLimits::REQUEST_OVERHEAD) + MediaLimits::MEGABYTE);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
        if ($result['refused'] === null) {
            $output->line(sprintf('Probe: the web server accepted every size tried, up to %s.', MediaLimits::describe($result['accepted'])));

            return null;
        }
        $output->line(sprintf('Probe: the web server accepts %s and refuses %s.', MediaLimits::describe($result['accepted']), MediaLimits::describe($result['refused'])));
        if ($stated !== null && $result['refused'] <= $stated) {
            return sprintf(
                'The web server refuses requests of %s, but uploads.proxy_body_limit in config/tenant.php says it accepts %s. Raise the web server\'s limit, or correct the setting.',
                MediaLimits::describe($result['refused']),
                MediaLimits::describe($stated),
            );
        }

        return null;
    }

    /**
     * The values for one site's limits, or for the largest limits of all the sites.
     */
    private function recommend(?Site $site, MediaLimits $limits, CommandOutput $output): void
    {
        $all = $site !== null ? [$limits] : [$limits, ...array_map(
            $this->config->mediaLimits->forSite(...),
            array_values(array_filter($this->sites->all(), static fn (Site $each): bool => $each->status !== SiteStatus::Deleted)),
        )];
        $recommendation = new ServerRecommendation($all);
        $output->line(sprintf('Recommended server values for %s (largest file %s):', $site === null ? 'all sites' : '"' . $site->slug . '"', MediaLimits::describe($recommendation->largestFile)));
        $output->line('Without chunked uploads:');
        foreach ($recommendation->lines(false) as $line) {
            $output->line('  ' . $line);
        }
        $output->line('With chunked uploads (one 8 MB part per request):');
        foreach ($recommendation->lines(true) as $line) {
            $output->line('  ' . $line);
        }
    }

    private static function size(?int $bytes): string
    {
        return $bytes === null ? 'no limit' : MediaLimits::describe($bytes);
    }
}
