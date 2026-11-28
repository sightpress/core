<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Privacy;

use Doctrine\DBAL\Connection;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Core\Sms\SmsLog;
use SightPress\Core\Sms\SmsNumberHash;
use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * Text messages sent to the person (`sms_message`, N-2, ADR-0075), by account and by a keyed hash of their number.
 * A number's STOP (`sms_opt_out`) is kept on purpose: it is only a hash, and it is what stops new texts to it.
 */
final readonly class SmsLogData implements PersonalDataProvider
{
    public const string KEY = 'sms.log';

    public function __construct(
        private Connection $connection,
        private SmsNumberHash $hashes,
    ) {
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Text messages we sent you'),
            new Message('To send texts, try again when sending fails and show that they arrived.'),
            [PersonalDataCategory::Contact, PersonalDataCategory::Communications],
            LawfulBasis::Contract,
            Retention::days(SmsLog::KEEP_DAYS),
            notes: new Message('Kept for 90 days. If you replied STOP, a scrambled form of the number is kept so it gets no more texts.'),
        );
    }

    public function export(UserRef $user): iterable
    {
        $rows = $this->query($user)
            ->select('created_at', 'purpose', 'kind', 'to_masked', 'status', 'parts', 'body', 'sent_at')
            ->orderBy('created_at')
            ->addOrderBy('id')
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'created_at' => Row::date($row['created_at']),
            'purpose' => $row['purpose'],
            'kind' => $row['kind'],
            'to' => $row['to_masked'],
            'status' => $row['status'],
            'parts' => is_numeric($row['parts']) ? Row::int($row['parts']) : null,
            'text' => $row['body'],
            'sent_at' => Row::date($row['sent_at']),
        ], $rows);
    }

    public function erase(UserRef $user): ErasureResult
    {
        $ids = $this->query($user)->select('id')->fetchFirstColumn();
        $deleted = 0;
        foreach ($ids as $id) {
            $deleted += (int) $this->connection->delete(SmsLog::TABLE, ['id' => $id]);
        }

        return new ErasureResult(deleted: $deleted);
    }

    private function query(UserRef $user): \Doctrine\DBAL\Query\QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()
            ->from(SmsLog::TABLE)
            ->where('user_id = :id')
            ->setParameter('id', $user->userId);
        if ($user->phone !== null) {
            $query->orWhere('to_hash = :hash')->setParameter('hash', $this->hashes->of($user->phone));
        }

        return $query;
    }
}
