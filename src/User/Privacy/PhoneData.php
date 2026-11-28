<?php

declare(strict_types=1);

namespace SightPress\Core\User\Privacy;

use Doctrine\DBAL\Connection;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * The mobile number on an account and the person's choices about texts (N-3, ADR-0075): the number, when it was
 * confirmed, its line type, when they agreed to texts or stopped them, and which notifications they chose.
 */
final readonly class PhoneData implements PersonalDataProvider
{
    public const string KEY = 'user.phone';

    private const array COLUMNS = ['phone_e164', 'phone_verified_at', 'phone_lookup', 'sms_consent_at', 'sms_opt_out_at', 'sms_notifications'];

    public function __construct(private Connection $connection)
    {
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Your mobile number'),
            new Message('To sign you in with a code by text, to confirm important changes and to send you the text notifications you chose.'),
            [PersonalDataCategory::Contact, PersonalDataCategory::Consent],
            LawfulBasis::Consent,
            Retention::whileAccountExists(),
            notes: new Message('Only if you added a number. Text notifications are sent only while you agree to them, and you can stop them at any time.'),
        );
    }

    public function export(UserRef $user): iterable
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...self::COLUMNS)
            ->from('users')
            ->where('id = :id')
            ->andWhere('phone_e164 IS NOT NULL OR sms_consent_at IS NOT NULL OR sms_opt_out_at IS NOT NULL')
            ->setParameter('id', $user->userId)
            ->fetchAssociative();
        if ($row === false) {
            return [];
        }

        return [[
            'mobile_number' => $row['phone_e164'],
            'confirmed_at' => Row::date($row['phone_verified_at']),
            'line_type' => $row['phone_lookup'],
            'agreed_to_texts_at' => Row::date($row['sms_consent_at']),
            'stopped_texts_at' => Row::date($row['sms_opt_out_at']),
            'text_notifications' => array_values(array_filter(explode(',', Row::text($row['sms_notifications']) ?? ''), static fn (string $type): bool => $type !== '')),
        ]];
    }

    public function erase(UserRef $user): ErasureResult
    {
        $query = $this->connection->createQueryBuilder()->update('users');
        foreach (self::COLUMNS as $column) {
            $query->set($column, 'NULL');
        }
        $changed = $query
            ->where('id = :id')
            ->andWhere('phone_e164 IS NOT NULL OR phone_verified_at IS NOT NULL OR phone_lookup IS NOT NULL OR sms_consent_at IS NOT NULL OR sms_opt_out_at IS NOT NULL OR sms_notifications IS NOT NULL')
            ->setParameter('id', $user->userId)
            ->executeStatement();

        return new ErasureResult(deleted: (int) $changed);
    }
}
