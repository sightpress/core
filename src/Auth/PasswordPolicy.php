<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SightPress\Core\Template\Message;
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
        'upper' => '/\p{Lu}/u',
        'lower' => '/\p{Ll}/u',
        'digit' => '/\p{Nd}/u',
        'symbol' => '/[\p{P}\p{S}]/u',
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
    public function problem(#[SensitiveParameter] string $password, string $email = ''): string|Message|null
    {
        $length = mb_strlen($password);
        if ($length < $this->minLength) {
            return new Message('Your password must be at least {min_length} characters long.', ['min_length' => $this->minLength]);
        }
        if ($length > self::MAX_LENGTH) {
            return new Message('Your password must be {max} characters or fewer.', ['max' => (string) self::MAX_LENGTH]);
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

        return $missing === [] ? null : new Message('Your password must include {missing}.', ['missing' => self::listed($missing)]);
    }

    /**
     * What a new password needs, in one sentence for the hint under the password field.
     */
    public function hint(): Message
    {
        $kinds = array_column($this->required(), 1);

        return $kinds === []
            ? new Message('At least {min_length} characters. A few unrelated words make a strong password that is easy to remember.', ['min_length' => (string) $this->minLength])
            : new Message('At least {min_length} characters, including {kinds}.', ['min_length' => (string) $this->minLength, 'kinds' => self::listed($kinds)]);
    }

    /**
     * @return list<array{string, Message}>
     */
    private function required(): array
    {
        $on = ['upper' => $this->upper, 'lower' => $this->lower, 'digit' => $this->digit, 'symbol' => $this->symbol];

        $what = [
            'upper' => new Message('a capital letter'),
            'lower' => new Message('a lower-case letter'),
            'digit' => new Message('a number'),
            'symbol' => new Message('a symbol, such as ! ? # or %'),
        ];
        $required = [];
        foreach (self::KINDS as $kind => $pattern) {
            if ($on[$kind]) {
                $required[] = [$pattern, $what[$kind]];
            }
        }

        return $required;
    }

    /**
     * @param non-empty-list<Message> $items
     */
    private static function listed(array $items): Message
    {
        return Message::list($items);
    }
}
