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
 * `site:create <slug>`: asks for what is missing (the database piece by piece), checks everything it was given, gets the database ready (creating it with `--create-db`),
 * runs the migrations, lets every module set the site up ({@see SiteProvisioning}: first admin, roles, setup
 * code, and later starter pages and branding), and only then adds the site to the registry, enabled. A database
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
        $slug = static fn (CommandInput $answers): string => $answers->argument('slug') ?? '';
        $server = static fn (CommandInput $answers): bool => trim($answers->option('db') ?? '') !== ''
            || $answers->option('db-engine') !== 'sqlite';

        return [
            new CommandParameter(
                'slug',
                'Short name of the site, used in commands and file names: lower-case letters, digits and hyphens, for example demo.',
                required: true,
                label: 'Short name',
                normalise: $this->slug(...),
            ),
            new CommandParameter('name', 'Name of the site, shown to visitors:', $option, label: 'Name', default: $slug, ask: true),
            new CommandParameter(
                'domain',
                'Web address of the site, without https://, for example example.org. Separate several with commas.',
                $option,
                true,
                label: 'Domains',
                normalise: $this->domains(...),
            ),
            new CommandParameter(
                'url',
                'Address the site is opened at, for the links this command prints:',
                $option,
                label: 'Address of the links',
                default: static fn (CommandInput $answers): ?string => self::firstDomain($answers->option('domain')),
                normalise: self::url(...),
                ask: true,
            ),
            new CommandParameter(
                'owner-type',
                'Who runs the site, a person or an organization?',
                $option,
                true,
                label: 'Run by',
                choices: array_map(static fn (OwnerType $type): string => $type->value, OwnerType::cases()),
            ),
            new CommandParameter(
                'locale',
                'Main language of the site, for example en-GB for British English or pt-BR for Brazilian Portuguese:',
                $option,
                true,
                label: 'Main language',
                default: 'en-GB',
                normalise: static fn (string $locale): string => LocaleCode::normalise($locale),
                describe: static fn (string $locale): string => LocaleCode::describe($locale),
            ),
            new CommandParameter(
                'timezone',
                'Time zone of the site, for example Europe/London:',
                $option,
                label: 'Time zone',
                default: $this->config->defaultTimezone,
                normalise: self::timezone(...),
                ask: true,
            ),
            ...new DatabaseQuestions($this->config->sitesDir)->parameters($slug),
            new CommandParameter(
                'create-db',
                'Create the database if it does not exist yet? The database user must be allowed to.',
                CommandParameterType::Flag,
                label: 'Create the database',
                default: 'yes',
                when: $server,
                ask: true,
            ),
            new CommandParameter(
                'admin-email',
                'Email address of the first admin:',
                $option,
                true,
                label: 'First admin\'s email',
                normalise: self::email(...),
            ),
            new CommandParameter('admin-first-name', 'First name of the first admin:', $option, true, label: 'First admin\'s first name'),
            new CommandParameter('admin-last-name', 'Last name of the first admin:', $option, true, label: 'First admin\'s last name'),
            new CommandParameter(
                'with-demo-content',
                'Add demo content for trying SightPress out? That is a page with every kind of block, its media files and an account for each role.',
                CommandParameterType::Flag,
                label: 'Demo content',
                default: 'no',
                ask: true,
            ),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        try {
            [$site, $dsn, $admin, $url] = $this->site($input);
        } catch (InvalidArgumentException | DatabaseDsnException $exception) {
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

            $provisioning = new SiteProvisioning($site, $this->containers->forSite($site), $admin, $url, $input->flag('with-demo-content'));
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
     * The site from the command's values, which {@see parameters()} already checked and normalised.
     *
     * @return array{Site, DatabaseDsn, FirstAdmin, string}
     */
    private function site(CommandInput $input): array
    {
        if (!$this->secretBox->isConfigured()) {
            throw new InvalidArgumentException('APP_SECRET is not set: set it in the environment before you create a site.');
        }
        $slug = $input->argument('slug') ?? '';
        $domains = explode(', ', $input->option('domain') ?? '');
        $locale = $input->option('locale') ?? '';
        $plainDsn = DatabaseQuestions::dsn($input);
        $dsn = DatabaseDsn::parse($plainDsn);

        $site = new Site(
            Site::newId(),
            $slug,
            $input->option('name') ?? $slug,
            SiteStatus::Enabled,
            OwnerType::from($input->option('owner-type') ?? ''),
            $domains,
            $this->secretBox->encrypt($plainDsn),
            new SiteStorage(),
            $locale,
            [$locale],
            $input->option('timezone') ?? $this->config->defaultTimezone,
            $this->clock->now(),
        );
        $admin = new FirstAdmin($input->option('admin-email') ?? '', $input->option('admin-first-name') ?? '', $input->option('admin-last-name') ?? '');

        return [$site, $dsn, $admin, $input->option('url') ?? 'https://' . $site->domains[0]];
    }

    private function slug(string $slug): string
    {
        $slug = mb_strtolower($slug);
        if (preg_match(Site::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a short name: use lower-case letters, digits and hyphens, for example demo.', $slug));
        }
        if ($this->sites->find($slug) !== null) {
            throw new InvalidArgumentException(sprintf('There is already a site "%s". Choose another short name.', $slug));
        }

        return $slug;
    }

    /**
     * Lower-case domains joined by ", ", without https://, paths or trailing dots; each one must be free.
     */
    private function domains(string $value): string
    {
        $domains = self::split($value);
        if ($domains === []) {
            throw new InvalidArgumentException('Give the site at least one domain, for example example.org.');
        }
        foreach ($domains as $domain) {
            if (preg_match(Site::DOMAIN_PATTERN, $domain) !== 1) {
                throw new InvalidArgumentException(sprintf('"%s" is not a domain: use one such as example.org or localhost.', $domain));
            }
            $owner = $this->sites->findByDomain($domain);
            if ($owner !== null) {
                throw new InvalidArgumentException(sprintf('The domain %s already belongs to the site "%s".', $domain, $owner->slug));
            }
        }

        return implode(', ', $domains);
    }

    /**
     * @return list<string>
     */
    private static function split(string $value): array
    {
        $domains = [];
        $parts = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts === false ? [] : $parts as $domain) {
            $domain = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', mb_strtolower($domain));
            $domain = rtrim(explode('/', $domain)[0], '.');
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    private static function firstDomain(?string $domains): ?string
    {
        $first = self::split($domains ?? '')[0] ?? null;

        return $first === null ? null : 'https://' . $first;
    }

    private static function url(string $url): string
    {
        $url = rtrim($url, '/');
        if (preg_match('#^https?://[^/\s]+$#i', $url) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a web address: use one such as https://example.org.', $url));
        }

        return $url;
    }

    private static function timezone(string $timezone): string
    {
        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            if (strcasecmp($identifier, $timezone) === 0) {
                return $identifier;
            }
        }

        throw new InvalidArgumentException(sprintf('"%s" is not a time zone: use one such as Europe/London.', $timezone));
    }

    private static function email(string $email): string
    {
        $email = mb_strtolower($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not an email address: type one such as ada@example.org.', $email));
        }

        return $email;
    }

    private static function migrations(int $count): string
    {
        return $count === 1 ? '1 database migration' : sprintf('%d database migrations', $count);
    }
}
