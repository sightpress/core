<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Counts, per day, the requests the web server refused for their size, for the admin notice. It stores only a date
 * and a number, nothing about the person. Counting never gets in the way of the "too large" page.
 */
final readonly class UploadRefusals
{
    public const string TABLE = 'upload_refusal';

    private const int KEEP_DAYS = 30;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function record(): void
    {
        $day = $this->day(0);
        try {
            if ($this->increment($day) === 0) {
                try {
                    $this->connection->insert(self::TABLE, ['refused_on' => $day, 'refused_count' => 1]);
                    $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE refused_on < ?', [$this->day(self::KEEP_DAYS)]);
                } catch (UniqueConstraintViolationException) {
                    $this->increment($day);
                }
            }
        } catch (DbalException) {
            return;
        }
    }

    /**
     * Requests refused today and in the six days before it; 0 when the count can't be read.
     */
    public function lastSevenDays(): int
    {
        try {
            $total = $this->connection->fetchOne('SELECT SUM(refused_count) FROM ' . self::TABLE . ' WHERE refused_on >= ?', [$this->day(6)]);

            return is_numeric($total) ? (int) $total : 0;
        } catch (DbalException) {
            return 0;
        }
    }

    private function increment(string $day): int
    {
        return (int) $this->connection->executeStatement('UPDATE ' . self::TABLE . ' SET refused_count = refused_count + 1 WHERE refused_on = ?', [$day]);
    }

    private function day(int $daysAgo): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify(sprintf('-%d days', $daysAgo))->format('Y-m-d');
    }
}
