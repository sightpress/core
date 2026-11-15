<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use DateTimeZone;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Database\DatabaseDsn;
use SightPress\Core\Infrastructure\Database\DatabaseDsnException;
use SightPress\Core\Infrastructure\Database\DatabaseProvisioner;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Site\Contract\FirstAdmin;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteMigrator;
use SightPress\Core\Tenant\SiteRegistryException;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\SiteStorage;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;
use Throwable;

/**
 * `site:create <slug>`: checks everything it was given, gets the database ready (creating it with `--create-db`),
 * runs the migrations, lets every module set the site up ({@see SiteProvisioning}: first admin, roles, password
 * link, and later starter pages and branding), and only then adds the site to the registry, enabled. A database
 * address is never printed with its user name or password.
 */
final readonly class CreateSiteCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SecretBox $secretBox,
        private DatabaseProvisioner $databases,
        private SiteMigrator $migrator,
        private SiteContainerFactory $containers,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private TenantConfig $config,
        private LoggerFactory $loggers,
    ) {
    }

    public function name(): string
    {
        return 'site:create';
    }

    public function description(): string
    {
        return 'Creates a site: its database, its first admin and its roles. Asks for anything required that is missing.';
    }

    public function parameters(): array
    {
        $option = CommandParameterType::Option;

        return [
            new CommandParameter('slug', 'Short name of the site (lower-case letters, digits and hyphens), for example demo:', required: true),
            new CommandParameter('domain', 'Web address of the site, without https:// (separate several with commas), for example example.org:', $option, true),
            new CommandParameter('db', 'Database address (DSN), for example sqlite:////srv/sites/demo.sqlite or postgresql://user:password@db:5432/demo:', $option, true),
            new CommandParameter('owner-type', 'Who runs the site: person or organization?', $option, true),
            new CommandParameter('locale', 'Main language of the site, for example en-GB:', $option, true),
            new CommandParameter('admin-email', 'Email address of the first admin:', $option, true),
            new CommandParameter('admin-first-name', 'First name of the first admin:', $option, true),
            new CommandParameter('admin-last-name', 'Last name of the first admin:', $option, true),
            new CommandParameter('name', 'Name of the site, shown to visitors (default: the slug).', $option),
            new CommandParameter('timezone', 'Time zone of the site, for example Europe/London (default: the tenant\'s).', $option),
            new CommandParameter('url', 'Address the printed links start with (default: https:// and the first domain).', $option),
            new CommandParameter('create-db', 'Create the database if it does not exist (the database user must be allowed to).', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        try {
            [$site, $dsn, $admin, $url] = $this->site($input);
        } catch (InvalidArgumentException | SiteRegistryException | DatabaseDsnException $exception) {
            $output->error($exception->getMessage());

            return self::INVALID;
        }

        $output->line(sprintf('Getting the %s ready…', $dsn->describe()));
        try {
            $this->databases->prepare($dsn, $input->flag('create-db'));
        } catch (DatabaseDsnException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $versions = $this->migrator->migrate($site);
            $output->line(sprintf('Ran %s.', self::migrations(count($versions))));

            $provisioning = new SiteProvisioning($site, $this->containers->forSite($site), $admin, $url);
            $this->events->dispatch($provisioning);
            $this->sites->save($site);
        } catch (Throwable $exception) {
            $output->error(sprintf(
                'Setting up the site failed: %s The site was not added to the registry. Its database may already '
                . 'have tables: empty it, or use another one, before you try again.',
                rtrim($exception->getMessage(), '.') . '.',
            ));

            return self::FAILURE;
        } finally {
            $this->containers->evict($site->slug);
        }

        foreach ($provisioning->lines() as $line) {
            $output->line($line);
        }
        $this->loggers->create('sites')->notice(sprintf('Site "%s" created (enabled).', $site->slug), [
            'site' => $site->slug,
            'status' => SiteStatus::Enabled->value,
            'database' => $dsn->describe(),
        ]);
        $output->line(sprintf('The site "%s" is ready at %s.', $site->slug, $url));

        return self::SUCCESS;
    }

    /**
     * @return array{Site, DatabaseDsn, FirstAdmin, string}
     */
    private function site(CommandInput $input): array
    {
        $slug = trim($input->argument('slug') ?? '');
        if ($this->sites->find($slug) !== null) {
            throw new InvalidArgumentException(sprintf('There is already a site "%s". Choose another slug.', $slug));
        }

        $domains = self::domains($input->option('domain') ?? '');
        foreach ($domains as $domain) {
            $owner = $this->sites->findByDomain($domain);
            if ($owner !== null) {
                throw new InvalidArgumentException(sprintf('The domain %s already belongs to the site "%s".', $domain, $owner->slug));
            }
        }

        $ownerType = OwnerType::tryFrom(mb_strtolower(trim($input->option('owner-type') ?? '')))
            ?? throw new InvalidArgumentException('The owner type must be person or organization.');
        $locale = trim($input->option('locale') ?? '');
        if (!LocaleCode::isValid($locale)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a locale: use one such as en-GB or pt-BR.', $locale));
        }
        $timezone = self::optional($input, 'timezone') ?? $this->config->defaultTimezone;
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a time zone: use one such as Europe/London.', $timezone));
        }
        $admin = new FirstAdmin(
            $input->option('admin-email') ?? '',
            $input->option('admin-first-name') ?? '',
            $input->option('admin-last-name') ?? '',
        );
        if (!$this->secretBox->isConfigured()) {
            throw new InvalidArgumentException('APP_SECRET is not set: set it in the environment before you create a site.');
        }
        $plainDsn = trim($input->option('db') ?? '');
        $dsn = DatabaseDsn::parse($plainDsn);
        $url = rtrim(self::optional($input, 'url') ?? 'https://' . $domains[0], '/');
        if (preg_match('#^https?://[^/\s]+$#', $url) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a web address: use one such as https://example.org.', $url));
        }

        $site = new Site(
            Site::newId(),
            $slug,
            self::optional($input, 'name') ?? $slug,
            SiteStatus::Enabled,
            $ownerType,
            $domains,
            $this->secretBox->encrypt($plainDsn),
            new SiteStorage(),
            $locale,
            [$locale],
            $timezone,
            $this->clock->now(),
        );

        return [$site, $dsn, $admin, $url];
    }

    /**
     * @return non-empty-list<string>
     */
    private static function domains(string $value): array
    {
        $domains = [];
        $parts = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts === false ? [] : $parts as $domain) {
            $domains[] = rtrim(mb_strtolower($domain), '.');
        }
        $domains = array_values(array_unique(array_filter($domains, static fn (string $domain): bool => $domain !== '')));
        if ($domains === []) {
            throw new InvalidArgumentException('Give the site at least one domain, for example example.org.');
        }

        return $domains;
    }

    /**
     * The option's value, trimmed, or null when it is missing or blank.
     */
    private static function optional(CommandInput $input, string $name): ?string
    {
        $value = trim($input->option($name) ?? '');

        return $value === '' ? null : $value;
    }

    private static function migrations(int $count): string
    {
        return $count === 1 ? '1 database migration' : sprintf('%d database migrations', $count);
    }
}
