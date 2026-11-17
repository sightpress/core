<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use DateTimeImmutable;
use DateTimeZone;
use SightPress\Core\Infrastructure\Crypto\SecretBox;

/**
 * One site of the tenant, as listed in the site registry (ADR-0002). The database DSN stays encrypted here; only
 * the code that opens the site's connection decrypts it.
 */
final readonly class Site
{
    /** 1 to 63 lower-case letters, digits and hyphens; no hyphen at either end. */
    public const string SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public const string ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** A lower-case host name (IDNs in their `xn--` form), without a port or a trailing dot. */
    public const string DOMAIN_PATTERN = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?'
        . '(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';

    /** @var non-empty-string */
    public string $id;

    /** @var non-empty-string */
    public string $slug;

    /** @var non-empty-string */
    public string $name;

    /** @var list<non-empty-string> */
    public array $domains;

    /** @var non-empty-string */
    public string $databaseDsn;

    /** @var non-empty-string */
    public string $defaultLocale;

    /** @var non-empty-list<non-empty-string> */
    public array $locales;

    /** @var non-empty-string */
    public string $defaultTimezone;

    public DateTimeImmutable $createdAt;

    /**
     * @param string       $id              UUID, stays the same if the slug changes
     * @param string       $slug            the site's key in the registry and in CLI commands
     * @param list<string> $domains         host names that point at the site
     * @param string       $databaseDsn     encrypted with {@see SecretBox}
     * @param list<string> $locales         every locale of the site, the default one included
     * @param string       $defaultTimezone IANA time zone for showing dates to visitors
     */
    public function __construct(
        string $id,
        string $slug,
        string $name,
        public SiteStatus $status,
        public OwnerType $ownerType,
        array $domains,
        string $databaseDsn,
        public SiteStorage $storage,
        string $defaultLocale,
        array $locales,
        string $defaultTimezone,
        DateTimeImmutable $createdAt,
    ) {
        $this->id = self::matches($id, self::ID_PATTERN, 'id', 'use a UUID');
        $this->slug = self::matches($slug, self::SLUG_PATTERN, 'slug', 'use lower-case letters, digits and hyphens');
        $name = trim($name);
        if ($name === '') {
            throw new SiteRegistryException('"name" must not be empty.');
        }
        $this->name = $name;
        $this->domains = self::domains($domains);
        if ($databaseDsn === '' || !SecretBox::looksEncrypted($databaseDsn)) {
            throw new SiteRegistryException('"db_dsn" must be encrypted; set it with a site:* command.');
        }
        $this->databaseDsn = $databaseDsn;
        $this->defaultLocale = self::locale($defaultLocale, 'default_locale');
        $this->locales = self::locales($locales, $this->defaultLocale);
        if ($defaultTimezone === '' || !in_array($defaultTimezone, DateTimeZone::listIdentifiers(), true)) {
            throw new SiteRegistryException('"default_timezone" is not valid: use an IANA time zone such as Europe/London.');
        }
        $this->defaultTimezone = $defaultTimezone;
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function isEnabled(): bool
    {
        return $this->status === SiteStatus::Enabled;
    }

    /**
     * The same site with another status.
     */
    public function withStatus(SiteStatus $status): self
    {
        return new self(
            $this->id,
            $this->slug,
            $this->name,
            $status,
            $this->ownerType,
            $this->domains,
            $this->databaseDsn,
            $this->storage,
            $this->defaultLocale,
            $this->locales,
            $this->defaultTimezone,
            $this->createdAt,
        );
    }

    /**
     * The same site with what its admins chose in the site's own settings: the name visitors see, the default
     * language (one of the site's languages) and the time zone dates are shown in. A value that doesn't fit is
     * ignored, so a bad setting never stops the site.
     */
    public function withDisplay(?string $name, ?string $defaultLocale, ?string $defaultTimezone): self
    {
        $name = $name === null ? '' : trim($name);
        $locale = $defaultLocale !== null && in_array($defaultLocale, $this->locales, true) ? $defaultLocale : $this->defaultLocale;
        $timezone = $defaultTimezone !== null && in_array($defaultTimezone, DateTimeZone::listIdentifiers(), true) ? $defaultTimezone : $this->defaultTimezone;

        return new self(
            $this->id,
            $this->slug,
            $name === '' ? $this->name : $name,
            $this->status,
            $this->ownerType,
            $this->domains,
            $this->databaseDsn,
            $this->storage,
            $locale,
            $this->locales,
            $timezone,
            $this->createdAt,
        );
    }

    /**
     * The same site on another database.
     *
     * @param string $databaseDsn encrypted with {@see SecretBox}
     */
    public function withDatabaseDsn(string $databaseDsn): self
    {
        return new self(
            $this->id,
            $this->slug,
            $this->name,
            $this->status,
            $this->ownerType,
            $this->domains,
            $databaseDsn,
            $this->storage,
            $this->defaultLocale,
            $this->locales,
            $this->defaultTimezone,
            $this->createdAt,
        );
    }

    /**
     * A new random site id (UUID version 4).
     */
    public static function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }

    /**
     * @return non-empty-string
     */
    private static function matches(string $value, string $pattern, string $key, string $rule): string
    {
        if ($value === '' || preg_match($pattern, $value) !== 1) {
            throw new SiteRegistryException(sprintf('"%s" is not valid: %s.', $key, $rule));
        }

        return $value;
    }

    /**
     * @param list<string> $domains
     *
     * @return list<non-empty-string>
     */
    private static function domains(array $domains): array
    {
        $valid = [];
        foreach ($domains as $index => $domain) {
            $valid[] = self::matches(
                $domain,
                self::DOMAIN_PATTERN,
                sprintf('domains[%d]', $index),
                'use a lower-case host name without a port, such as example.org',
            );
        }
        if (count(array_unique($valid)) !== count($valid)) {
            throw new SiteRegistryException('"domains" lists the same domain twice.');
        }

        return $valid;
    }

    /**
     * @return non-empty-string
     */
    private static function locale(string $locale, string $key): string
    {
        return self::matches($locale, LocaleCode::PATTERN, $key, 'use a locale such as en-GB');
    }

    /**
     * @param list<string> $locales
     *
     * @return non-empty-list<non-empty-string>
     */
    private static function locales(array $locales, string $default): array
    {
        $valid = [];
        foreach ($locales as $index => $locale) {
            $valid[] = self::locale($locale, sprintf('locales[%d]', $index));
        }
        if (!in_array($default, $valid, true)) {
            throw new SiteRegistryException('"locales" must include the default locale.');
        }
        if (count(array_unique($valid)) !== count($valid)) {
            throw new SiteRegistryException('"locales" lists the same locale twice.');
        }

        return $valid;
    }
}
