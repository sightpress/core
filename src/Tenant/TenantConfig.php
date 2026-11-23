<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use DateTimeZone;
use Monolog\Level;
use SensitiveParameter;
use SightPress\Core\Http\Upload\IniSize;

/**
 * The tenant's settings: one install of SightPress, read once per process from `config/tenant.php` and the
 * environment (`APP_ENV`, `APP_DEBUG`).
 *
 * Every date is stored and processed in UTC; {@see $defaultTimezone} is only the display timezone for visitors
 * when neither the site nor the user has one.
 */
final readonly class TenantConfig
{
    /**
     * @param non-empty-string $projectDir
     * @param non-empty-string $registryPath
     * @param non-empty-string $sitesDir
     * @param non-empty-string $cacheDir
     * @param non-empty-string $logPath
     * @param non-empty-string $adminLocale
     * @param non-empty-string $defaultTimezone
     * @param non-empty-string $sessionPath        folder for session data
     * @param positive-int     $sessionIdleTimeout seconds without a request before a session ends
     * @param non-empty-string|null $secret       `APP_SECRET`: derives the key that encrypts site DSNs; null when unset
     * @param non-empty-string $mailerDsn         `MAILER_DSN`: how email is sent; `null://null` (nothing is sent) when unset
     * @param non-empty-string $mailFrom          sender address of every email
     * @param non-empty-string $mailFromName      sender name of every email
     * @param array<string, bool> $moduleDefaults `modules.defaults`: module id => whether new sites start with it on,
     *                                            in place of the module's own default
     * @param array<string, bool> $moduleLocks    `modules.locked`: module id => on or off for every site; the sites'
     *                                            admins can't change it ("Set by your organisation")
     * @param positive-int|null $uploadProxyBodyLimit `uploads.proxy_body_limit`: the web server's request body limit
     *                                            in bytes (nginx `client_max_body_size` …), which PHP can't read;
     *                                            null when not stated
     * @param TenantAssistant $assistant          `assistant`: the AI assistant's tenant default and limits
     * @param TenantThemes    $themes             `themes`: which installed themes sites may use, and new sites' theme
     * @param TenantMediaLimits $mediaLimits      `media.limits`: every site's upload limits, and the ceiling of a site's own
     */
    public function __construct(
        public string $projectDir,
        public Environment $environment,
        public bool $debug,
        public string $registryPath,
        public string $sitesDir,
        public string $cacheDir,
        public string $logPath,
        public Level $logLevel,
        public string $adminLocale = 'en-GB',
        public string $defaultTimezone = 'UTC',
        public string $sessionPath = '/tmp/sightpress-sessions',
        public int $sessionIdleTimeout = 7200,
        #[SensitiveParameter] public ?string $secret = null,
        #[SensitiveParameter] public string $mailerDsn = 'null://null',
        public string $mailFrom = 'no-reply@localhost',
        public string $mailFromName = 'SightPress',
        public array $moduleDefaults = [],
        public array $moduleLocks = [],
        public ?int $uploadProxyBodyLimit = null,
        public TenantAssistant $assistant = new TenantAssistant(),
        public TenantThemes $themes = new TenantThemes(),
        public TenantMediaLimits $mediaLimits = new TenantMediaLimits(),
    ) {
    }

    /**
     * @param array<mixed>          $config      what `config/tenant.php` returns
     * @param array<string, string> $environment environment variables
     */
    public static function fromArray(array $config, string $projectDir, array $environment = []): self
    {
        $projectDir = rtrim($projectDir, '/');
        if ($projectDir === '') {
            throw new TenantConfigException('The project folder must not be empty.');
        }

        $env = Environment::fromValue($environment['APP_ENV'] ?? null);
        $registry = self::section($config, 'registry');
        $admin = self::section($config, 'admin');
        $log = self::section($config, 'log');
        $session = self::section($config, 'session');
        $mail = self::section($config, 'mail');
        $modules = self::section($config, 'modules');
        $uploads = self::section($config, 'uploads');
        $dsn = trim($environment['MAILER_DSN'] ?? '');

        return new self(
            projectDir: $projectDir,
            environment: $env,
            debug: self::debug($environment['APP_DEBUG'] ?? null, $env),
            registryPath: self::string($registry, 'path', 'registry.path', $projectDir . '/config/sites.php'),
            sitesDir: self::string($config, 'sites_dir', 'sites_dir', $projectDir . '/sites'),
            cacheDir: self::string($config, 'cache_dir', 'cache_dir', $projectDir . '/var/cache/' . $env->value),
            logPath: self::string($log, 'path', 'log.path', $projectDir . '/var/log/' . $env->value . '.log'),
            logLevel: self::logLevel($log),
            adminLocale: self::locale($admin),
            defaultTimezone: self::timezone($config),
            sessionPath: self::string($session, 'path', 'session.path', $projectDir . '/var/sessions'),
            sessionIdleTimeout: self::idleTimeout($session),
            secret: self::secret($environment['APP_SECRET'] ?? null),
            mailerDsn: $dsn === '' ? 'null://null' : $dsn,
            mailFrom: self::email($mail),
            mailFromName: self::string($mail, 'from_name', 'mail.from_name', 'SightPress'),
            moduleDefaults: self::switches($modules, 'defaults'),
            moduleLocks: self::switches($modules, 'locked'),
            uploadProxyBodyLimit: self::bodyLimit($uploads),
            assistant: TenantAssistant::fromArray(self::section($config, 'assistant'), $environment),
            themes: TenantThemes::fromArray(self::section($config, 'themes')),
            mediaLimits: TenantMediaLimits::fromArray(self::section($config, 'media')),
        );
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private static function section(array $config, string $key): array
    {
        $section = $config[$key] ?? [];
        if (!is_array($section)) {
            throw new TenantConfigException(sprintf('Tenant setting "%s" must be an array.', $key));
        }

        return $section;
    }

    /**
     * @param array<mixed>     $config
     * @param non-empty-string $default
     *
     * @return non-empty-string
     */
    private static function string(array $config, string $key, string $name, string $default): string
    {
        $value = $config[$key] ?? $default;
        if (!is_string($value) || trim($value) === '') {
            throw new TenantConfigException(sprintf('Tenant setting "%s" must be a non-empty string.', $name));
        }

        return $value;
    }

    /**
     * `modules.defaults` or `modules.locked`: module ids, each true (on) or false (off). Whether the modules exist is
     * checked when the kernel loads them ({@see \SightPress\Core\Module\ModulePolicy}).
     *
     * @param array<mixed> $modules
     *
     * @return array<string, bool>
     */
    private static function switches(array $modules, string $key): array
    {
        $switches = self::section($modules, $key);
        foreach ($switches as $id => $on) {
            if (!is_string($id) || !is_bool($on)) {
                throw new TenantConfigException(sprintf(
                    'Tenant setting "modules.%s" must list module ids, each set to true (on) or false (off), for example [\'acme.events\' => false].',
                    $key,
                ));
            }
        }

        /** @var array<string, bool> $switches */
        return $switches;
    }

    /**
     * `uploads.proxy_body_limit`: bytes, or a size the way php.ini writes it (`65M`, `1G`); null or `''` when the web
     * server's limit isn't known.
     *
     * @param array<mixed> $uploads
     *
     * @return positive-int|null
     */
    private static function bodyLimit(array $uploads): ?int
    {
        $value = $uploads['proxy_body_limit'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $bytes = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^\s*\d+\s*[kmg]?\s*$/i', $value) === 1 => IniSize::bytes($value),
            default => null,
        };
        if ($bytes === null || $bytes < 1) {
            throw new TenantConfigException(
                'Tenant setting "uploads.proxy_body_limit" must be the web server\'s request body limit, in bytes or as a size such as 65M or 1G.',
            );
        }

        return $bytes;
    }

    /**
     * @return non-empty-string|null
     */
    private static function secret(#[SensitiveParameter] ?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (strlen($value) < 32) {
            throw new TenantConfigException(
                'APP_SECRET is too short: use at least 32 random characters, for example the output of '
                . '`php -r "echo bin2hex(random_bytes(32));"`.',
            );
        }

        return $value;
    }

    private static function debug(?string $value, Environment $environment): bool
    {
        if ($value === null || trim($value) === '') {
            return $environment === Environment::Dev;
        }

        $debug = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($debug === null) {
            throw new TenantConfigException(sprintf('APP_DEBUG is not valid ("%s"): use 1 or 0.', $value));
        }

        return $debug;
    }

    /**
     * @param array<mixed> $log
     */
    private static function logLevel(array $log): Level
    {
        $value = self::string($log, 'level', 'log.level', 'notice');

        return match (strtolower($value)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => throw new TenantConfigException(sprintf(
                'Tenant setting "log.level" is not valid ("%s"): use debug, info, notice, warning or error.',
                $value,
            )),
        };
    }

    /**
     * @param array<mixed> $session
     *
     * @return positive-int
     */
    private static function idleTimeout(array $session): int
    {
        $timeout = $session['idle_timeout'] ?? 7200;
        if (!is_int($timeout) || $timeout < 60) {
            throw new TenantConfigException(
                'Tenant setting "session.idle_timeout" must be a whole number of seconds, at least 60.',
            );
        }

        return $timeout;
    }

    /**
     * @param array<mixed> $admin
     *
     * @return non-empty-string
     */
    private static function locale(array $admin): string
    {
        $locale = self::string($admin, 'default_locale', 'admin.default_locale', 'en-GB');
        if (!LocaleCode::isValid($locale)) {
            throw new TenantConfigException(sprintf(
                'Tenant setting "admin.default_locale" is not valid ("%s"): use a locale such as en-GB.',
                $locale,
            ));
        }

        return $locale;
    }

    /**
     * @param array<mixed> $mail
     *
     * @return non-empty-string
     */
    private static function email(array $mail): string
    {
        $from = self::string($mail, 'from', 'mail.from', 'no-reply@localhost');
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false && preg_match('/^[^@\s]+@localhost$/', $from) !== 1) {
            throw new TenantConfigException(sprintf(
                'Tenant setting "mail.from" is not an email address ("%s").',
                $from,
            ));
        }

        return $from;
    }

    /**
     * @param array<mixed> $config
     *
     * @return non-empty-string
     */
    private static function timezone(array $config): string
    {
        $timezone = self::string($config, 'timezone', 'timezone', 'UTC');
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new TenantConfigException(sprintf(
                'Tenant setting "timezone" is not valid ("%s"): use an IANA time zone such as Europe/London.',
                $timezone,
            ));
        }

        return $timezone;
    }
}
