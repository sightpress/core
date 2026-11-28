<?php

declare(strict_types=1);

namespace SightPress\Core\User\Privacy;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Core\User\UserStatus;
use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\ErasureRule;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * Accounts and profiles (`users`, ADR-0075): the person's email address, names, password, status, time zone,
 * language and admin display choices. Erasing keeps the row as the *Former user* tombstone (D46), so pages and the
 * audit log still point somewhere, with every personal value cleared.
 */
final readonly class AccountData implements PersonalDataProvider
{
    public const string KEY = 'user.account';

    /** The domain of a tombstone's address: `.invalid` can never receive email (RFC 2606). */
    public const string TOMBSTONE_DOMAIN = 'former-user.invalid';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The address a tombstone gets in place of the person's own: unique, and never a real mailbox.
     */
    public static function tombstoneEmail(int $userId): string
    {
        return sprintf('erased-%d@%s', $userId, self::TOMBSTONE_DOMAIN);
    }

    public static function isTombstoneEmail(string $email): bool
    {
        return str_ends_with($email, '@' . self::TOMBSTONE_DOMAIN);
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Your account'),
            new Message('To let you sign in and work on the site, and to show your name next to what you do in the admin.'),
            [PersonalDataCategory::Identity, PersonalDataCategory::Contact, PersonalDataCategory::Account],
            LawfulBasis::Contract,
            Retention::whileAccountExists(),
            ErasureRule::Pseudonymise,
            new Message('When an account is deleted, its email address, names, password, time zone and language are removed. What the person wrote stays on the site under “Former user”. Accounts that were never confirmed are removed after 7 days.'),
        );
    }

    public function export(UserRef $user): iterable
    {
        $row = $this->connection->createQueryBuilder()
            ->select('email', 'first_name', 'last_name', 'display_name', 'status', 'timezone', 'locale', 'admin_reduced_motion', 'admin_larger_text', 'password_hash', 'created_at', 'updated_at')
            ->from('users')
            ->where('id = :id')
            ->setParameter('id', $user->userId)
            ->fetchAssociative();
        if ($row === false) {
            return [];
        }

        return [[
            'email' => $row['email'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'display_name' => $row['display_name'],
            'status' => $row['status'],
            'time_zone' => $row['timezone'],
            'admin_language' => $row['locale'],
            'admin_reduced_motion' => (bool) $row['admin_reduced_motion'],
            'admin_larger_text' => (bool) $row['admin_larger_text'],
            'has_password' => $row['password_hash'] !== null,
            'created_at' => Row::date($row['created_at']),
            'updated_at' => Row::date($row['updated_at']),
        ]];
    }

    public function erase(UserRef $user): ErasureResult
    {
        $changed = $this->connection->createQueryBuilder()
            ->update('users')
            ->set('email', ':email')
            ->set('first_name', "''")
            ->set('last_name', "''")
            ->set('display_name', 'NULL')
            ->set('password_hash', 'NULL')
            ->set('status', ':status')
            ->set('timezone', "'UTC'")
            ->set('locale', 'NULL')
            ->set('admin_reduced_motion', ':off')
            ->set('admin_larger_text', ':off')
            ->set('updated_at', ':now')
            ->where('id = :id')
            ->andWhere('email <> :email')
            ->setParameter('id', $user->userId)
            ->setParameter('email', self::tombstoneEmail($user->userId))
            ->setParameter('status', UserStatus::Deactivated->value)
            ->setParameter('off', false, 'boolean')
            ->setParameter('now', $this->clock->now(), 'datetime_immutable')
            ->executeStatement();

        return new ErasureResult(pseudonymised: (int) $changed);
    }
}
