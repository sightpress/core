<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SensitiveParameter;
use SightPress\Core\Auth\Contract\Passwords;
use SightPress\Core\Site\Contract\SiteSettings;

/**
 * The site's password policy: how long a new password must be and which kinds of characters it must include.
 * Every site has one. By default a password needs at least 12 characters, with a capital letter, a lower-case
 * letter, a number and a symbol; a site admin can change that in the site settings (setting keys below). It is
 * never shorter than {@see FLOOR} or longer than {@see MAX_LENGTH}, and never the email address.
 *
 * Letters, numbers and symbols are counted in any script (`\p{Lu}`, `\p{Ll}`, `\p{Nd}`, `\p{P}` and `\p{S}`), so
 * "É" is a capital letter and "€" a symbol. A space is allowed but is not a symbol.
 *
 * The policy applies when someone chooses a password (setup, reset, invitation, profile); people keep their
 * current password until they next change it.
 */
final readonly class PasswordPolicy
{
    public const string MIN_LENGTH_SETTING = Passwords::MIN_LENGTH_SETTING;

    public const string UPPER_SETTING = 'auth.password_upper';

    public const string LOWER_SETTING = 'auth.password_lower';

    public const string DIGIT_SETTING = 'auth.password_digit';

    public const string SYMBOL_SETTING = 'auth.password_symbol';

    /** The default minimum length. */
    public const int MIN_LENGTH = 12;

    /** The shortest minimum length a site can choose. */
    public const int FLOOR = Passwords::SHORTEST_MIN_LENGTH;

    public const int MAX_LENGTH = Passwords::MAX_LENGTH;

    /** Kind => [pattern, what the person must include]. */
    private const array KINDS = [
        'upper' => ['/\p{Lu}/u', 'a capital letter'],
        'lower' => ['/\p{Ll}/u', 'a lower-case letter'],
        'digit' => ['/\p{Nd}/u', 'a number'],
        'symbol' => ['/[\p{P}\p{S}]/u', 'a symbol, such as ! ? # or %'],
    ];

    public int $minLength;

    public function __construct(
        int $minLength = self::MIN_LENGTH,
        public bool $upper = true,
        public bool $lower = true,
        public bool $digit = true,
        public bool $symbol = true,
    ) {
        $this->minLength = max(self::FLOOR, min(self::MAX_LENGTH, $minLength));
    }

    /**
     * The site's policy: what it stored, or the default for each setting it never stored or stored badly.
     */
    public static function fromSettings(SiteSettings $settings): self
    {
        $length = $settings->get(self::MIN_LENGTH_SETTING);
        $flag = static fn (string $key): bool => $settings->get($key) !== '0';

        return new self(
            $length !== null && preg_match('/^\d{1,4}$/', $length) === 1 ? (int) $length : self::MIN_LENGTH,
            $flag(self::UPPER_SETTING),
            $flag(self::LOWER_SETTING),
            $flag(self::DIGIT_SETTING),
            $flag(self::SYMBOL_SETTING),
        );
    }

    /**
     * The problem with the password, as a sentence for the form, or null when it is fine. When several kinds of
     * characters are missing, the sentence names all of them, so the person can fix the password in one go.
     */
    public function problem(#[SensitiveParameter] string $password, string $email = ''): ?string
    {
        $length = mb_strlen($password);
        if ($length < $this->minLength) {
            return sprintf('Your password must be at least %d characters long.', $this->minLength);
        }
        if ($length > self::MAX_LENGTH) {
            return sprintf('Your password must be %d characters or fewer.', self::MAX_LENGTH);
        }
        if ($email !== '' && mb_strtolower(trim($password)) === mb_strtolower(trim($email))) {
            return 'Your password must not be your email address.';
        }
        $missing = [];
        foreach ($this->required() as [$pattern, $what]) {
            if (preg_match($pattern, $password) !== 1) {
                $missing[] = $what;
            }
        }

        return $missing === [] ? null : sprintf('Your password must include %s.', self::listed($missing));
    }

    /**
     * What a new password needs, in one sentence for the hint under the password field.
     */
    public function hint(): string
    {
        $kinds = array_column($this->required(), 1);

        return $kinds === []
            ? sprintf('At least %d characters. A few unrelated words make a strong password that is easy to remember.', $this->minLength)
            : sprintf('At least %d characters, including %s.', $this->minLength, self::listed($kinds));
    }

    /**
     * @return list<array{string, string}>
     */
    private function required(): array
    {
        $on = ['upper' => $this->upper, 'lower' => $this->lower, 'digit' => $this->digit, 'symbol' => $this->symbol];

        return array_values(array_filter(self::KINDS, static fn (string $kind): bool => $on[$kind], ARRAY_FILTER_USE_KEY));
    }

    /**
     * @param non-empty-list<string> $items
     */
    private static function listed(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? $last : implode(', ', $items) . ' and ' . $last;
    }
}
