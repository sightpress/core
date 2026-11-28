<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Privacy;

use Doctrine\DBAL\Connection;
use SightPress\Core\Auth\RememberTokens;
use SightPress\Core\Infrastructure\Privacy\ExportedDate;
use SightPress\Module\Privacy\CookieDeclaration;
use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * "Keep me signed in" (`auth_remember_tokens`, ADR-0075): one row per device the person chose, with a hash of the
 * secret in the cookie and when it ends.
 */
final readonly class RememberTokenData implements PersonalDataProvider
{
    public const string KEY = 'auth.remember';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * The cookie, under both its names (the `__Host-` one over HTTPS).
     *
     * @return list<CookieDeclaration>
     */
    public static function cookies(): array
    {
        $purpose = new Message('Keeps you signed in on this device, only if you ticked “Keep me signed in”.');

        return [
            new CookieDeclaration(RememberTokens::SECURE_COOKIE, $purpose, RememberTokens::LIFETIME, true),
            new CookieDeclaration(RememberTokens::COOKIE, $purpose, RememberTokens::LIFETIME, true),
        ];
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Devices that keep you signed in'),
            new Message('To sign you in again on a device where you ticked “Keep me signed in”.'),
            [PersonalDataCategory::Account, PersonalDataCategory::Technical],
            LawfulBasis::Contract,
            Retention::days(intdiv(RememberTokens::LIFETIME, 86400)),
            notes: new Message('They end after 30 days, when you sign out, or when your password changes.'),
        );
    }

    public function export(UserRef $user): iterable
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('expires_at')
            ->from(RememberTokens::TABLE)
            ->where('user_id = :id')
            ->orderBy('expires_at')
            ->setParameter('id', $user->userId)
            ->fetchFirstColumn();

        return array_map(static fn (mixed $expires): array => ['ends_at' => ExportedDate::of(is_scalar($expires) ? (string) $expires : null)], $rows);
    }

    public function erase(UserRef $user): ErasureResult
    {
        return new ErasureResult(deleted: (int) $this->connection->delete(RememberTokens::TABLE, ['user_id' => $user->userId]));
    }
}
