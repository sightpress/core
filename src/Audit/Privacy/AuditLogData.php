<?php

declare(strict_types=1);

namespace SightPress\Core\Audit\Privacy;

use Doctrine\DBAL\Connection;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Infrastructure\Privacy\Row;
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
 * The audit log (`audit_log`, D45, ADR-0075): what the person did, and what was done to their account. Erasing keeps
 * the history but pseudonymises it: the entries still point at the account, which is now the *Former user*
 * tombstone, and the person's names, email address and number in summaries and details become {@see ERASED}.
 *
 * Names are read from the account when erasing, so the privacy tools erase the audit log before the account itself.
 */
final readonly class AuditLogData implements PersonalDataProvider
{
    public const string KEY = 'audit.log';

    public const string ERASED = '[erased]';

    /** Shorter names would match inside other words. */
    private const int MIN_NAME_LENGTH = 2;

    public function __construct(private Connection $connection)
    {
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('The audit log'),
            new Message('To show who changed what on the site, for its security and so changes can be explained.'),
            [PersonalDataCategory::Account, PersonalDataCategory::Usage],
            LawfulBasis::LegitimateInterests,
            Retention::days(730),
            ErasureRule::Pseudonymise,
            new Message('When an account is deleted, its entries stay under “Former user”, without the person’s name or contact details.'),
        );
    }

    public function export(UserRef $user): iterable
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('occurred_at', 'actor_id', 'event_action', 'entity_type', 'entity_id', 'summary')
            ->from(AuditLog::TABLE)
            ->where('actor_id = :id')
            ->orWhere("entity_type = 'user' AND entity_id = :entity")
            ->orderBy('occurred_at')
            ->addOrderBy('id')
            ->setParameter('id', $user->userId)
            ->setParameter('entity', (string) $user->userId)
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'occurred_at' => Row::date($row['occurred_at']),
            'by_you' => Row::int($row['actor_id']) === $user->userId,
            'action' => $row['event_action'],
            'summary' => $row['summary'],
        ], $rows);
    }

    public function erase(UserRef $user): ErasureResult
    {
        $patterns = $this->patterns($user);
        $query = $this->connection->createQueryBuilder()
            ->select('id', 'summary', 'details')
            ->from(AuditLog::TABLE)
            ->where('actor_id = :id')
            ->orWhere("entity_type = 'user' AND entity_id = :entity")
            ->setParameter('id', $user->userId)
            ->setParameter('entity', (string) $user->userId);
        foreach ($patterns as $index => [$plain]) {
            $query->orWhere(sprintf('summary LIKE :like%1$d OR details LIKE :like%1$d', $index))
                ->setParameter('like' . $index, '%' . addcslashes($plain, '%_\\') . '%');
        }
        $changed = 0;
        foreach ($query->fetchAllAssociative() as $row) {
            $summary = self::redact((Row::text($row['summary']) ?? ''), $patterns);
            $details = is_string($row['details']) ? self::redact($row['details'], $patterns) : null;
            if ($summary === $row['summary'] && $details === $row['details']) {
                continue;
            }
            $this->connection->update(AuditLog::TABLE, ['summary' => $summary, 'details' => $details], ['id' => $row['id']]);
            ++$changed;
        }

        return new ErasureResult(pseudonymised: $changed);
    }

    /**
     * What to look for: the person's email address and number, and every name on the account (longest first, so a
     * full name goes before the first name inside it).
     *
     * @return list<array{string, string}> the text and a regular expression that finds it as a whole word
     */
    private function patterns(UserRef $user): array
    {
        $texts = [$user->email];
        if ($user->phone !== null) {
            $texts[] = $user->phone->e164;
        }
        $row = $this->connection->createQueryBuilder()
            ->select('first_name', 'last_name', 'display_name')
            ->from('users')
            ->where('id = :id')
            ->setParameter('id', $user->userId)
            ->fetchAssociative();
        if ($row !== false) {
            $first = trim((Row::text($row['first_name']) ?? ''));
            $last = trim((Row::text($row['last_name']) ?? ''));
            $texts[] = trim($first . ' ' . $last);
            $texts[] = trim((Row::text($row['display_name']) ?? ''));
            $texts[] = $first;
            $texts[] = $last;
        }
        $texts = array_values(array_unique(array_filter($texts, static fn (string $text): bool => mb_strlen($text) >= self::MIN_NAME_LENGTH)));
        usort($texts, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_map(static fn (string $text): array => [$text, '/(?<![\pL\pN])' . preg_quote($text, '/') . '(?![\pL\pN])/iu'], $texts);
    }

    /**
     * @param list<array{string, string}> $patterns
     */
    private static function redact(string $text, array $patterns): string
    {
        foreach ($patterns as [, $pattern]) {
            $text = (string) preg_replace($pattern, self::ERASED, $text);
        }

        return $text;
    }
}
