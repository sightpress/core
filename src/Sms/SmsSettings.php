<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use Psr\EventDispatcher\EventDispatcherInterface;
use SensitiveParameter;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Crypto\SecretBoxException;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Module\Event\SettingsChanged;

/**
 * The site's own text message provider (N-2, ADR-0069), stored in its settings: provider, account, key (encrypted with
 * the tenant's `APP_SECRET`, never shown again, only {@see maskedKey()}), sender, region and the monthly budget. Empty
 * means "use the tenant's default". Every change is a {@see SettingsChanged} with the keys only, so the audit log never
 * holds a value.
 */
final readonly class SmsSettings
{
    public const string PROVIDER = 'sms.provider';
    public const string ACCOUNT = 'sms.account';
    public const string KEY = 'sms.key';
    public const string SENDER = 'sms.sender';
    public const string REGION = 'sms.region';
    public const string BUDGET = 'sms.monthly_budget';


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
    public function account(): ?string
    {
        return $this->text(self::ACCOUNT);
    }

    /**
     * @return non-empty-string|null
     */
    public function sender(): ?string
    {
        return $this->text(self::SENDER);
    }

    /**
     * @return non-empty-string|null
     */
    public function region(): ?string
    {
        return $this->text(self::REGION);
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
     * The site's budget in message parts a month (0 = no limit), or null to use the tenant's.
     *
     * @return int<0, max>|null
     */
    public function budget(): ?int
    {
        $stored = $this->text(self::BUDGET);

        return $stored !== null && preg_match('/^\d{1,9}$/', $stored) === 1 ? max(0, (int) $stored) : null;
    }

    /**
     * Stores the provider, account, sender and region, and the key when one is given (null keeps the current key).
     *
     * @return list<string> keys of the settings that changed
     */
    public function connect(string $provider, ?string $account, ?string $sender, ?string $region, #[SensitiveParameter] ?string $key, ?int $actorId): array
    {
        $changed = $this->change([
            self::PROVIDER => $provider,
            self::ACCOUNT => $account ?? '',
            self::SENDER => $sender ?? '',
            self::REGION => $region ?? '',
        ]);
        if ($key !== null && $key !== $this->key()) {
            $this->settings->set(self::KEY, $this->secrets->encrypt($key));
            $changed[] = self::KEY;
        }

        return $this->changed($changed, $actorId);
    }

    /**
     * @param int<0, max>|null $budget null = the tenant's
     *
     * @return list<string> keys of the settings that changed
     */
    public function limit(?int $budget, ?int $actorId): array
    {
        return $this->changed($this->change([self::BUDGET => $budget === null ? '' : (string) $budget]), $actorId);
    }

    /**
     * Forgets the site's own provider and key; the tenant's default (if any) applies again.
     *
     * @return list<string> keys of the settings that changed
     */
    public function remove(?int $actorId): array
    {
        return $this->changed($this->change([self::PROVIDER => '', self::ACCOUNT => '', self::KEY => '', self::SENDER => '', self::REGION => '']), $actorId);
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
            if (($this->settings->get($key) ?? '') !== $value) {
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
