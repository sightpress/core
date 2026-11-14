<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Crypto;

use SensitiveParameter;

/**
 * Encrypts secrets kept in config files (for example a site's database DSN, ADR-0002) with libsodium's secret box
 * (XSalsa20-Poly1305), using a key derived from the tenant's `APP_SECRET`.
 *
 * An encrypted value looks like `sodium:v1:<base64url of nonce + ciphertext>`, so it can sit in a PHP config file
 * or in version control without exposing the secret.
 */
final readonly class SecretBox
{
    public const string PREFIX = 'sodium:v1:';

    /** Key derivation context: exactly 8 bytes (libsodium). */
    private const string CONTEXT = 'spsecret';

    /**
     * @param string|null $secret the tenant's `APP_SECRET`; null when it isn't set
     */
    public function __construct(#[SensitiveParameter] private ?string $secret)
    {
    }

    public function isConfigured(): bool
    {
        return $this->secret !== null;
    }

    /**
     * Whether a value has the shape of something {@see encrypt()} made. It doesn't check the key.
     */
    public static function looksEncrypted(string $value): bool
    {
        if (!str_starts_with($value, self::PREFIX)) {
            return false;
        }

        $decoded = self::decode(substr($value, strlen(self::PREFIX)));

        return $decoded !== null
            && strlen($decoded) >= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
    }

    /**
     * @return non-empty-string
     */
    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->key();
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        sodium_memzero($key);

        return self::PREFIX . rtrim(strtr(base64_encode($nonce . $ciphertext), '+/', '-_'), '=');
    }

    public function decrypt(string $value): string
    {
        if (!self::looksEncrypted($value)) {
            throw new SecretBoxException('The value is not encrypted with SightPress\'s secret box.');
        }

        $decoded = (string) self::decode(substr($value, strlen(self::PREFIX)));
        $key = $this->key();
        $plaintext = sodium_crypto_secretbox_open(
            substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );
        sodium_memzero($key);

        if ($plaintext === false) {
            throw new SecretBoxException(
                'The value could not be decrypted: it was encrypted with another APP_SECRET, or it was changed.',
            );
        }

        return $plaintext;
    }

    private function key(): string
    {
        if ($this->secret === null) {
            throw new SecretBoxException(
                'APP_SECRET is not set. Set it in the environment (at least 32 characters) and keep a backup of it.',
            );
        }

        $master = sodium_crypto_generichash($this->secret, '', SODIUM_CRYPTO_KDF_KEYBYTES);
        $key = sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 1, self::CONTEXT, $master);
        sodium_memzero($master);

        return $key;
    }

    private static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
