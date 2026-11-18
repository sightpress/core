<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Contract;

use SightPress\Core\Tenant\Site;

/**
 * Who runs the site, as its admins describe it in the site settings: a short description, how to reach them and
 * their profiles on other sites. Search engines get it in the site's structured data (`Organization` or `Person`)
 * and AI readers in `llms.txt`. Also the name, languages, default language and time zone the admins chose, which win
 * over the tenant registry inside the site ({@see Site::withDisplay()}).
 */
final readonly class SiteProfile
{
    public const string NAME = 'site.name';
    public const string DEFAULT_LOCALE = 'site.default_locale';
    /** The site's languages, comma-separated (`en-GB,pt-PT`), written by {@see SiteLanguages}. */
    public const string LOCALES = 'site.locales';
    public const string DEFAULT_TIMEZONE = 'site.default_timezone';
    public const string DESCRIPTION = 'site.description';
    public const string EMAIL = 'site.contact_email';
    public const string PHONE = 'site.phone';
    public const string ADDRESS = 'site.address';
    public const string LEGAL_NAME = 'site.legal_name';
    public const string SAME_AS = 'site.same_as';

    /**
     * @param list<string> $sameAs addresses of the owner's profiles on other sites (https only)
     */
    public function __construct(
        public ?string $description = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $address = null,
        public ?string $legalName = null,
        public array $sameAs = [],
    ) {
    }

    public static function read(SiteSettings $settings): self
    {
        $text = static function (string $key) use ($settings): ?string {
            $value = trim((string) $settings->get($key));

            return $value === '' ? null : $value;
        };
        $email = $text(self::EMAIL);

        return new self(
            $text(self::DESCRIPTION),
            $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            $text(self::PHONE),
            $text(self::ADDRESS),
            $text(self::LEGAL_NAME),
            self::links($text(self::SAME_AS) ?? ''),
        );
    }

    /**
     * The site with the name, languages, default language and time zone its admins chose, when they chose them.
     */
    public static function display(Site $site, SiteSettings $settings): Site
    {
        $locales = trim((string) $settings->get(self::LOCALES));

        return $site->withDisplay(
            $settings->get(self::NAME),
            $settings->get(self::DEFAULT_LOCALE),
            $settings->get(self::DEFAULT_TIMEZONE),
            $locales === '' ? null : array_map(trim(...), explode(',', $locales)),
        );
    }

    /**
     * The `https://` addresses in a text with one address per line; anything else is left out.
     *
     * @return list<string>
     */
    public static function links(string $text): array
    {
        $links = [];
        foreach (self::lines($text) as $line) {
            $line = trim($line);
            if (self::isLink($line)) {
                $links[] = $line;
            }
        }

        return array_values(array_unique($links));
    }

    public static function isLink(string $line): bool
    {
        return strlen($line) <= 2000 && preg_match('~^https://[^\s/?#]+\.[^\s/?#]+(?:[/?#]\S*)?$~i', $line) === 1;
    }

    /**
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        $lines = preg_split('/\R/u', $text);

        return $lines === false ? [] : $lines;
    }
}
