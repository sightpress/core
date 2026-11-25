<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use SensitiveParameter;

/**
 * The tenant's `sms` settings (N-2, ADR-0069): an optional default text message provider that sites inherit (its key
 * from `SMS_API_KEY`, never in a config file), whether site admins may set up a provider of their own, the default
 * monthly budget of a site and the part of it kept for security codes, the countries numbers may be in, and the
 * limits that stop a sign-up form from being used to run up costs (SMS pumping).
 */
final readonly class TenantSms
{
    /** Environment variable holding the tenant's provider key. */
    public const string KEY_VARIABLE = 'SMS_API_KEY';

    /** Default limits: codes or messages per hour, and lookups per day. */
    public const array LIMITS = [
        'per_number' => 3,
        'per_client' => 10,
        'per_account' => 5,
        'per_site' => 100,
        'lookups_per_day' => 100,
    ];

    /**
     * @param non-empty-string|null      $provider         provider id of the tenant's default, null when there is none
     * @param non-empty-string|null      $account          the default's account or key id
     * @param non-empty-string|null      $key              `SMS_API_KEY`
     * @param non-empty-string|null      $sender           the default's sender (number or name)
     * @param non-empty-string|null      $region           the default's region, for providers that have regions
     * @param bool                       $siteProviders    whether a site's admins may set up their own provider
     * @param bool                       $requireForExisting whether people who already have an account must add a
     *                                                     number at their next sign-in (otherwise they may skip it)
     * @param int<0, max>                $monthlyBudget    message parts a site may send each month; 0 = no limit
     * @param int<0, max>                $reservedForCodes parts of the budget only security codes may use, so a
     *                                                     used-up budget never locks people out
     * @param list<non-empty-string>|null $countries       countries numbers may be in (ISO 3166-1 alpha-2); null =
     *                                                     the region of each site's main language
     * @param array{per_number: positive-int, per_client: positive-int, per_account: positive-int, per_site: positive-int, lookups_per_day: positive-int} $limits
     */
    public function __construct(
        public ?string $provider = null,
        public ?string $account = null,
        #[SensitiveParameter] public ?string $key = null,
        public ?string $sender = null,
        public ?string $region = null,
        public bool $siteProviders = false,
        public bool $requireForExisting = false,
        public int $monthlyBudget = 1000,
        public int $reservedForCodes = 50,
        public ?array $countries = null,
        public array $limits = self::LIMITS,
    ) {
    }

    /**
     * @param array<mixed>          $section     the `sms` section of `config/tenant.php`
     * @param array<string, string> $environment environment variables
     */
    public static function fromArray(array $section, #[SensitiveParameter] array $environment = []): self
    {
        $budget = self::count($section, 'monthly_budget', 1000, 'a whole number of message parts, 0 or more (0 = no limit)');
        $reserved = self::count($section, 'reserved_for_codes', 50, 'a whole number of message parts, 0 or more');
        if ($budget > 0 && $reserved >= $budget) {
            throw new TenantConfigException('Tenant setting "sms.reserved_for_codes" must be smaller than "sms.monthly_budget": it is the part of the budget kept for security codes.');
        }
        $key = trim($environment[self::KEY_VARIABLE] ?? '');

        return new self(
            provider: self::text($section, 'provider'),
            account: self::text($section, 'account'),
            key: $key === '' ? null : $key,
            sender: self::text($section, 'sender'),
            region: self::text($section, 'region'),
            siteProviders: self::flag($section, 'site_providers', false),
            requireForExisting: self::flag($section, 'require_for_existing', false),
            monthlyBudget: $budget,
            reservedForCodes: $reserved,
            countries: self::countries($section),
            limits: self::limits($section),
        );
    }

    /**
     * Whether the tenant gives sites a provider to inherit (it may still lack a key).
     */
    public function hasDefault(): bool
    {
        return $this->provider !== null;
    }

    /**
     * @param array<mixed> $section
     *
     * @return int<0, max>
     */
    private static function count(array $section, string $key, int $default, string $what): int
    {
        $value = $section[$key] ?? $default;
        if (!is_int($value) || $value < 0) {
            throw new TenantConfigException(sprintf('Tenant setting "sms.%s" must be %s.', $key, $what));
        }

        return $value;
    }

    /**
     * @param array<mixed> $section
     *
     * @return list<non-empty-string>|null
     */
    private static function countries(array $section): ?array
    {
        $value = $section['countries'] ?? null;
        if ($value === null) {
            return null;
        }
        $countries = [];
        foreach (is_array($value) && $value !== [] && array_is_list($value) ? $value : [null] as $country) {
            if (!is_string($country) || preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
                throw new TenantConfigException('Tenant setting "sms.countries" must list countries as two-letter codes, for example [\'GB\', \'IE\'], or be null for the region of each site\'s main language.');
            }
            $countries[] = strtoupper($country);
        }

        return array_values(array_unique($countries));
    }

    /**
     * @param array<mixed> $section
     *
     * @return array{per_number: positive-int, per_client: positive-int, per_account: positive-int, per_site: positive-int, lookups_per_day: positive-int}
     */
    private static function limits(array $section): array
    {
        $value = $section['limits'] ?? [];
        if (!is_array($value)) {
            throw new TenantConfigException('Tenant setting "sms.limits" must be an array.');
        }
        $limits = self::LIMITS;
        foreach ($value as $name => $limit) {
            if (!is_string($name) || !array_key_exists($name, self::LIMITS)) {
                throw new TenantConfigException(sprintf('Tenant setting "sms.limits" has an unknown limit "%s": use %s.', (string) $name, implode(', ', array_keys(self::LIMITS))));
            }
            if (!is_int($limit) || $limit < 1) {
                throw new TenantConfigException(sprintf('Tenant setting "sms.limits.%s" must be a whole number, at least 1.', $name));
            }
            $limits[$name] = $limit;
        }

        return $limits;
    }

    /**
     * @param array<mixed> $section
     *
     * @return non-empty-string|null
     */
    private static function text(array $section, string $key): ?string
    {
        $value = $section[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            throw new TenantConfigException(sprintf('Tenant setting "sms.%s" must be text, or null.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $section
     */
    private static function flag(array $section, string $key, bool $default): bool
    {
        $value = $section[$key] ?? $default;
        if (!is_bool($value)) {
            throw new TenantConfigException(sprintf('Tenant setting "sms.%s" must be true or false.', $key));
        }

        return $value;
    }
}
