<?php

declare(strict_types=1);

namespace SightPress\Core\User\Entity;

use SightPress\Core\Template\Message;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\User\AvatarKind;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\UserException;
use SightPress\Core\User\UserStatus;

/**
 * A person with an account on one site. Each site keeps its own users, in its own database.
 *
 * Names are Unicode and only trimmed and length-checked: they are never matched against Latin patterns. The
 * effective name (shown in the admin, bylines, emails and the audit log) is the display name, or the first name
 * when there is none. All times are UTC; the time zone is only used to show them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
final class User implements Account
{
    public const int NAME_MAX_LENGTH = 100;
    public const int EMAIL_MAX_LENGTH = 254;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: self::EMAIL_MAX_LENGTH, unique: true)]
    private string $email;

    #[ORM\Column(name: 'password_hash', type: Types::STRING, length: 255, nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::Pending;

    #[ORM\Column(name: 'first_name', type: Types::STRING, length: self::NAME_MAX_LENGTH)]
    private string $firstName;

    #[ORM\Column(name: 'last_name', type: Types::STRING, length: self::NAME_MAX_LENGTH)]
    private string $lastName;

    #[ORM\Column(name: 'display_name', type: Types::STRING, length: self::NAME_MAX_LENGTH, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(name: 'avatar_path', type: Types::STRING, length: 255, nullable: true)]
    private ?string $avatarPath = null;

    #[ORM\Column(name: 'avatar_kind', type: Types::STRING, length: 20, nullable: true, enumType: AvatarKind::class)]
    private ?AvatarKind $avatarKind = null;

    #[ORM\Column(name: 'avatar_color', type: Types::STRING, length: 7, nullable: true)]
    private ?string $avatarColor = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $timezone;

    #[ORM\Column(type: Types::STRING, length: 35, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(name: 'admin_reduced_motion', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $adminReducedMotion = false;

    #[ORM\Column(name: 'admin_larger_text', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $adminLargerText = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /**
     * @param string $timezone IANA time zone id, for example `Europe/Lisbon`
     */
    public function __construct(
        string $email,
        string $firstName,
        string $lastName,
        string $timezone,
        DateTimeImmutable $now,
        ?string $displayName = null,
        ?string $locale = null,
    ) {
        $this->email = self::checkEmail($email);
        $this->firstName = self::checkName($firstName, 'first name');
        $this->lastName = self::checkName($lastName, 'last name');
        $this->displayName = self::checkDisplayName($displayName);
        $this->timezone = self::checkTimezone($timezone);
        $this->locale = self::checkLocale($locale);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * The id, once the user has been saved.
     */
    public function id(): ?int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isPending(): bool
    {
        return $this->status === UserStatus::Pending;
    }

    public function awaitsEmailVerification(): bool
    {
        return $this->status === UserStatus::Unverified;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function displayName(): ?string
    {
        return $this->displayName;
    }

    /**
     * The name to show: the display name, or the first name when there is none.
     */
    public function effectiveName(): string
    {
        return $this->displayName ?? $this->firstName;
    }

    /**
     * First and last name, for example in the admin's user list.
     */
    public function fullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function passwordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * The person's preferred locale, or null for the site default.
     */
    public function locale(): ?string
    {
        return $this->locale;
    }

    /**
     * Whether the admin panel should keep still for this person: no animations, transitions or smooth scrolling.
     */
    public function adminReducedMotion(): bool
    {
        return $this->adminReducedMotion;
    }

    /**
     * Whether the admin panel should show its text larger for this person.
     */
    public function adminLargerText(): bool
    {
        return $this->adminLargerText;
    }

    public function avatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function avatarKind(): ?AvatarKind
    {
        return $this->avatarKind;
    }

    public function avatarColor(): ?string
    {
        return $this->avatarColor;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function rename(string $firstName, string $lastName, ?string $displayName, DateTimeImmutable $now): void
    {
        $this->firstName = self::checkName($firstName, 'first name');
        $this->lastName = self::checkName($lastName, 'last name');
        $this->displayName = self::checkDisplayName($displayName);
        $this->updatedAt = $now;
    }

    public function changeTimezone(string $timezone, DateTimeImmutable $now): void
    {
        $this->timezone = self::checkTimezone($timezone);
        $this->updatedAt = $now;
    }

    public function changeLocale(?string $locale, DateTimeImmutable $now): void
    {
        $this->locale = self::checkLocale($locale);
        $this->updatedAt = $now;
    }

    /**
     * A new sign-in address, once a code proved the person can read it.
     */
    public function changeEmail(string $email, DateTimeImmutable $now): void
    {
        $this->email = self::checkEmail($email);
        $this->updatedAt = $now;
    }

    /**
     * How the admin panel is shown to this person.
     */
    public function changeAdminPreferences(bool $reducedMotion, bool $largerText, DateTimeImmutable $now): void
    {
        $this->adminReducedMotion = $reducedMotion;
        $this->adminLargerText = $largerText;
        $this->updatedAt = $now;
    }

    /**
     * Stores a new password hash (argon2id, made by the caller) and activates a pending account.
     */
    public function setPasswordHash(string $hash, DateTimeImmutable $now): void
    {
        if ($hash === '') {
            throw new UserException('A password hash can\'t be empty.');
        }

        $this->passwordHash = $hash;
        if ($this->status === UserStatus::Pending) {
            $this->status = UserStatus::Active;
        }
        $this->updatedAt = $now;
    }

    /**
     * @param string $color background colour as `#rrggbb`
     */
    public function setAvatar(AvatarKind $kind, string $path, string $color, DateTimeImmutable $now): void
    {
        if ($path === '') {
            throw new UserException('An avatar needs a file path.');
        }
        if (preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
            throw new UserException(new Message('Avatar colour "{color}" must be written as #rrggbb in lower case.', ['color' => $color]));
        }

        $this->avatarKind = $kind;
        $this->avatarPath = $path;
        $this->avatarColor = $color;
        $this->updatedAt = $now;
    }

    /**
     * The person signed up themselves: the account waits until they type the code we emailed them
     * ({@see confirmEmail()}). It needs its password already.
     */
    public function awaitEmailVerification(DateTimeImmutable $now): void
    {
        if ($this->passwordHash === null) {
            throw new UserException('An account without a password can\'t wait for its email address to be confirmed; set a password first.');
        }

        $this->status = UserStatus::Unverified;
        $this->updatedAt = $now;
    }

    /**
     * The person typed the code we emailed them: an account that was waiting for it can sign in. Other accounts
     * don't change.
     */
    public function confirmEmail(DateTimeImmutable $now): void
    {
        if ($this->status === UserStatus::Unverified) {
            $this->status = UserStatus::Active;
            $this->updatedAt = $now;
        }
    }

    public function activate(DateTimeImmutable $now): void
    {
        if ($this->passwordHash === null) {
            throw new UserException('An account without a password can\'t be activated; set a password first.');
        }

        $this->status = UserStatus::Active;
        $this->updatedAt = $now;
    }

    public function deactivate(DateTimeImmutable $now): void
    {
        if ($this->status === UserStatus::Unverified) {
            throw new UserException('This account hasn\'t been confirmed yet, so it can\'t sign in anyway. It is removed a week after it was made.');
        }
        $this->status = UserStatus::Deactivated;
        $this->updatedAt = $now;
    }

    /**
     * Undoes {@see deactivate()}: the account can sign in again, or is pending when no password was ever set.
     */
    public function reactivate(DateTimeImmutable $now): void
    {
        $this->status = $this->passwordHash === null ? UserStatus::Pending : UserStatus::Active;
        $this->updatedAt = $now;
    }

    /**
     * Trims and lower-cases an email address and checks it looks like one.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function checkEmail(string $email): string
    {
        $email = self::normalizeEmail($email);
        if (
            mb_strlen($email) > self::EMAIL_MAX_LENGTH
            || filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false
        ) {
            throw new UserException(new Message('"{email}" is not a valid email address.', ['email' => $email]));
        }

        return $email;
    }

    private static function checkName(string $name, string $what): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new UserException(new Message('Enter a {what}.', ['what' => $what]));
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new UserException(new Message('The {what} must be {max} characters or fewer.', ['what' => $what, 'max' => (string) self::NAME_MAX_LENGTH]));
        }

        return $name;
    }

    private static function checkDisplayName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return self::checkName($name, 'display name');
    }

    private static function checkTimezone(string $timezone): string
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new UserException(new Message('"{timezone}" is not a time zone. Use an IANA time zone such as "Europe/London".', ['timezone' => $timezone]));
        }

        return $timezone;
    }

    private static function checkLocale(?string $locale): ?string
    {
        if ($locale !== null && !LocaleCode::isValid($locale)) {
            throw new UserException(new Message('"{locale}" is not a locale code such as "en-GB".', ['locale' => $locale]));
        }

        return $locale;
    }
}
