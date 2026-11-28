<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * How long each site keeps personal data (GD-2, D45, ADR-0075), from `privacy.retention` in `config/tenant.php`: for
 * each kind, the default and the shortest and longest a site's admins may choose on *Settings → Privacy*. The tenant
 * may narrow the limits SightPress allows ({@see LIMITS}), never widen them. Checked at boot.
 *
 * ```php
 * 'privacy' => ['retention' => [
 *     'audit_months' => ['default' => 24, 'min' => 12, 'max' => 84],
 *     'sms_days' => 30,                       // only the default
 * ]],
 * ```
 */
final readonly class TenantRetention
{
    /** The audit log, in months (D45: 24, sites 6–84). */
    public const string AUDIT = 'audit_months';

    /** Accounts whose email address was never confirmed, in days after they were made. */
    public const string UNVERIFIED = 'unverified_days';

    /** Invitations that weren't accepted, in days after they expired. */
    public const string INVITATIONS = 'invitation_days';

    /** Used or expired 6-digit codes, in days. */
    public const string CODES = 'code_days';

    /** The record of text messages sent, in days. */
    public const string SMS = 'sms_days';

    /** The record of AI assistant use, in months (the budget month plus a year). */
    public const string ASSISTANT = 'assistant_months';

    /** @var array<string, array{default: positive-int, min: positive-int, max: positive-int}> what SightPress allows */
    public const array LIMITS = [
        self::AUDIT => ['default' => 24, 'min' => 6, 'max' => 84],
        self::UNVERIFIED => ['default' => 7, 'min' => 1, 'max' => 30],
        self::INVITATIONS => ['default' => 30, 'min' => 1, 'max' => 365],
        self::CODES => ['default' => 1, 'min' => 1, 'max' => 7],
        self::SMS => ['default' => 90, 'min' => 7, 'max' => 730],
        self::ASSISTANT => ['default' => 13, 'min' => 2, 'max' => 25],
    ];

    /**
     * @param array<string, array{default: positive-int, min: positive-int, max: positive-int}> $limits
     */
    public function __construct(public array $limits = self::LIMITS)
    {
    }

    /**
     * @param array<mixed> $section `privacy.retention`
     *
     * @throws TenantConfigException for an unknown kind, or a value outside what SightPress allows
     */
    public static function fromArray(array $section): self
    {
        $limits = self::LIMITS;
        foreach ($section as $key => $value) {
            $key = (string) $key;
            if (!isset(self::LIMITS[$key])) {
                throw new TenantConfigException(sprintf('Tenant setting "privacy.retention.%s" is not known: use %s.', $key, implode(', ', array_keys(self::LIMITS))));
            }
            $given = is_array($value) ? $value : ['default' => $value];
            $limit = self::LIMITS[$key];
            foreach (['default', 'min', 'max'] as $part) {
                if (!array_key_exists($part, $given)) {
                    continue;
                }
                if (!is_int($given[$part])) {
                    throw new TenantConfigException(sprintf('Tenant setting "privacy.retention.%s.%s" must be a whole number.', $key, $part));
                }
                $limit[$part] = $given[$part];
            }
            $hard = self::LIMITS[$key];
            if ($limit['min'] < $hard['min'] || $limit['max'] > $hard['max'] || $limit['min'] > $limit['max']) {
                throw new TenantConfigException(sprintf('Tenant setting "privacy.retention.%s" must keep its limits between %d and %d, with min no more than max.', $key, $hard['min'], $hard['max']));
            }
            if ($limit['default'] < $limit['min'] || $limit['default'] > $limit['max']) {
                throw new TenantConfigException(sprintf('Tenant setting "privacy.retention.%s.default" must be between its min (%d) and max (%d).', $key, $limit['min'], $limit['max']));
            }
            $limits[$key] = $limit;
        }

        return new self($limits);
    }

    /**
     * @return array{default: positive-int, min: positive-int, max: positive-int}
     */
    public function limit(string $key): array
    {
        return $this->limits[$key] ?? throw new \InvalidArgumentException(sprintf('There is no retention "%s".', $key));
    }

    public static function inMonths(string $key): bool
    {
        return str_ends_with($key, '_months');
    }
}
