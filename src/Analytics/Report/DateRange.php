<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Report;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Days from {@see $from} to {@see $to}, both included, as `Y-m-d` in the site's time zone (reports bucket days in
 * the site's zone, so the numbers are the same for every viewer).
 */
final readonly class DateRange
{
    /** The longest range a report covers, in days (a little over the longest retention). */
    public const int MAX_DAYS = 800;

    public function __construct(
        public string $from,
        public string $to,
    ) {
        foreach ([$from, $to] as $day) {
            if (!self::isDay($day)) {
                throw new InvalidArgumentException(sprintf('"%s" isn\'t a date written as YYYY-MM-DD.', $day));
            }
        }
        if ($from > $to) {
            throw new InvalidArgumentException('The start of the range must not be after its end.');
        }
        if ($this->days() > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('A range can be at most %d days long.', self::MAX_DAYS));
        }
    }

    /**
     * The last `$days` days, ending with `$today`.
     */
    public static function last(int $days, string $today): self
    {
        return new self(new DateTimeImmutable($today)->modify(sprintf('-%d days', max(1, $days) - 1))->format('Y-m-d'), $today);
    }

    public static function isDay(string $day): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return $date !== false && $date->format('Y-m-d') === $day;
    }

    /**
     * The same number of days just before this range, for "compared with the previous period".
     */
    public function previous(): self
    {
        $days = $this->days();
        $to = new DateTimeImmutable($this->from)->modify('-1 day');

        return new self($to->modify(sprintf('-%d days', $days - 1))->format('Y-m-d'), $to->format('Y-m-d'));
    }

    public function days(): int
    {
        return (int) new DateTimeImmutable($this->from)->diff(new DateTimeImmutable($this->to))->days + 1;
    }

    public function contains(string $day): bool
    {
        return $day >= $this->from && $day <= $this->to;
    }

    /**
     * @return list<string> every day of the range, in order
     */
    public function each(): array
    {
        $days = [];
        for ($day = new DateTimeImmutable($this->from); $day->format('Y-m-d') <= $this->to; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }
}
