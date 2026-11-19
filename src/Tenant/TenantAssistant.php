<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use SensitiveParameter;

/**
 * The tenant's `assistant` settings (ADR-0010, ADR-0040): an optional default provider, model and base URL that sites
 * inherit, with its key from the environment (`ASSISTANT_API_KEY`, never in a config file), whether sites may use
 * keys of their own, whether providers on private or link-local addresses are allowed (a model server on the local
 * network) and the default monthly token budget of a site.
 */
final readonly class TenantAssistant
{
    /** Environment variable holding the tenant's API key. */
    public const string KEY_VARIABLE = 'ASSISTANT_API_KEY';

    /**
     * @param non-empty-string|null $provider     provider id of the tenant's default, null when there is none
     * @param non-empty-string|null $model        model of the tenant's default
     * @param non-empty-string|null $baseUrl      base URL of the tenant's default (OpenAI-compatible servers)
     * @param non-empty-string|null $apiKey       `ASSISTANT_API_KEY`
     * @param bool                  $siteKeys     whether a site's admins may choose their own provider and key
     * @param bool                  $allowPrivateHosts whether a provider may be on a private or link-local address
     * @param int<0, max>           $monthlyBudget tokens a site may use each month by default; 0 = no limit
     */
    public function __construct(
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $baseUrl = null,
        #[SensitiveParameter] public ?string $apiKey = null,
        public bool $siteKeys = true,
        public bool $allowPrivateHosts = false,
        public int $monthlyBudget = 0,
    ) {
    }

    /**
     * @param array<mixed>          $section     the `assistant` section of `config/tenant.php`
     * @param array<string, string> $environment environment variables
     */
    public static function fromArray(array $section, #[SensitiveParameter] array $environment = []): self
    {
        $budget = $section['monthly_budget'] ?? 0;
        if (!is_int($budget) || $budget < 0) {
            throw new TenantConfigException('Tenant setting "assistant.monthly_budget" must be a whole number of tokens, 0 or more (0 = no limit).');
        }
        $key = trim($environment[self::KEY_VARIABLE] ?? '');

        return new self(
            provider: self::text($section, 'provider'),
            model: self::text($section, 'model'),
            baseUrl: self::text($section, 'base_url'),
            apiKey: $key === '' ? null : $key,
            siteKeys: self::flag($section, 'site_keys', true),
            allowPrivateHosts: self::flag($section, 'allow_private_hosts', false),
            monthlyBudget: $budget,
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
            throw new TenantConfigException(sprintf('Tenant setting "assistant.%s" must be text, or null.', $key));
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
            throw new TenantConfigException(sprintf('Tenant setting "assistant.%s" must be true or false.', $key));
        }

        return $value;
    }
}
