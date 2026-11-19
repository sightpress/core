<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

use Psr\EventDispatcher\EventDispatcherInterface;
use SensitiveParameter;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Crypto\SecretBoxException;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Site\Contract\SiteSettings;

/**
 * The site's own assistant settings (ADR-0040), stored in its settings: provider, model, base URL, API key
 * (encrypted with the tenant's `APP_SECRET`, {@see SecretBox}; never shown again, only {@see maskedKey()}), the
 * monthly token budget and the roles that may use the assistant. Empty means "use the tenant's default". Every
 * change is a {@see SettingsChanged} with the keys only, so the audit log never holds a value.
 */
final readonly class AssistantSettings
{
    public const string PROVIDER = 'assistant.provider';
    public const string MODEL = 'assistant.model';
    public const string BASE_URL = 'assistant.base_url';
    public const string KEY = 'assistant.key';
    public const string BUDGET = 'assistant.monthly_budget';
    public const string ROLES = 'assistant.roles';

    /** Roles that may use the assistant until an admin chooses (they have `assistant.use`). */
    public const array DEFAULT_ROLES = ['admin', 'editor', 'contributor'];

    public function __construct(
        private SiteSettings $settings,
        private SecretBox $secrets,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @return non-empty-string|null
     */
    public function provider(): ?string
    {
        return $this->text(self::PROVIDER);
    }

    /**
     * @return non-empty-string|null
     */
    public function model(): ?string
    {
        return $this->text(self::MODEL);
    }

    /**
     * @return non-empty-string|null
     */
    public function baseUrl(): ?string
    {
        return $this->text(self::BASE_URL);
    }

    public function hasKey(): bool
    {
        return $this->text(self::KEY) !== null;
    }

    /**
     * The site's own key, or null when it has none or it can't be decrypted (another `APP_SECRET`).
     */
    public function key(): ?string
    {
        $stored = $this->text(self::KEY);
        if ($stored === null) {
            return null;
        }
        try {
            $key = $this->secrets->decrypt($stored);
        } catch (SecretBoxException) {
            return null;
        }

        return $key === '' ? null : $key;
    }

    /**
     * The key as `••••1234` (the last 4 characters of a key longer than 8), or null when there is none.
     */
    public function maskedKey(): ?string
    {
        $key = $this->key();
        if ($key === null) {
            return $this->hasKey() ? '••••' : null;
        }

        return '••••' . (mb_strlen($key) > 8 ? mb_substr($key, -4) : '');
    }

    /**
     * The site's budget in tokens a month (0 = no limit), or null to use the tenant's.
     *
     * @return int<0, max>|null
     */
    public function budget(): ?int
    {
        $stored = $this->text(self::BUDGET);

        return $stored !== null && preg_match('/^\d{1,12}$/', $stored) === 1 ? max(0, (int) $stored) : null;
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        $stored = $this->settings->get(self::ROLES);
        if ($stored === null) {
            return self::DEFAULT_ROLES;
        }

        return array_values(array_filter(explode(',', $stored), static fn (string $role): bool => $role !== ''));
    }

    /**
     * Stores the provider, model and base URL, and the key when one is given (null keeps the current key).
     *
     * @return list<string> keys of the settings that changed
     */
    public function connect(string $provider, string $model, ?string $baseUrl, #[SensitiveParameter] ?string $key, ?int $actorId): array
    {
        $changed = $this->change([
            self::PROVIDER => $provider,
            self::MODEL => $model,
            self::BASE_URL => $baseUrl ?? '',
        ]);
        if ($key !== null && $key !== $this->key()) {
            $this->settings->set(self::KEY, $this->secrets->encrypt($key));
            $changed[] = self::KEY;
        }

        return $this->changed($changed, $actorId);
    }

    /**
     * Forgets the site's key.
     */
    public function removeKey(?int $actorId): bool
    {
        if (!$this->hasKey()) {
            return false;
        }
        $this->settings->set(self::KEY, '');
        $this->changed([self::KEY], $actorId);

        return true;
    }

    /**
     * @param int<0, max>|null $budget null = the tenant's
     * @param list<string>     $roles
     *
     * @return list<string> keys of the settings that changed
     */
    public function limit(?int $budget, array $roles, ?int $actorId): array
    {
        return $this->changed($this->change([
            self::BUDGET => $budget === null ? '' : (string) $budget,
            self::ROLES => implode(',', array_values(array_unique($roles))),
        ]), $actorId);
    }

    /**
     * @param array<string, string> $values
     *
     * @return list<string>
     */
    private function change(array $values): array
    {
        $changed = [];
        foreach ($values as $key => $value) {
            $current = $key === self::ROLES ? implode(',', $this->roles()) : ($this->settings->get($key) ?? '');
            if ($current !== $value) {
                $this->settings->set($key, $value);
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function changed(array $keys, ?int $actorId): array
    {
        if ($keys !== []) {
            $this->events->dispatch(new SettingsChanged($keys, $actorId));
        }

        return $keys;
    }

    /**
     * @return non-empty-string|null
     */
    private function text(string $key): ?string
    {
        $value = trim($this->settings->get($key) ?? '');

        return $value === '' ? null : $value;
    }
}
