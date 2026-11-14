<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use DateTimeZone;
use Monolog\Level;

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
        if (preg_match('/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2})?$/', $locale) !== 1) {
            throw new TenantConfigException(sprintf(
                'Tenant setting "admin.default_locale" is not valid ("%s"): use a locale such as en-GB.',
                $locale,
            ));
        }

        return $locale;
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
