<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use SightPress\Core\Page\PageException;
use SightPress\Core\Template\Message;

/**
 * A date and time as people type it in a form (`2026-12-01T09:00`, as a `datetime-local` field sends it, or
 * `2026-12-01 09:00`), in their own time zone, turned into UTC for storing, and back.
 */
final class LocalTime
{
    public const string FORMAT = 'Y-m-d\TH:i';

    /**
     * @param string $timezone IANA time zone of the person, for example `Europe/London`
     *
     * @return DateTimeImmutable|null in UTC; null for an empty field
     *
     * @throws PageException when it isn't a date and time, or the time doesn't exist there (the clocks go forward)
     */
    public static function parse(string $value, string $timezone, Message $field): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::\d{2})?$/', $value, $parts) !== 1) {
            throw new PageException(new Message('{field}: enter a date and time, for example 2026-12-01 09:00.', ['field' => $field]));
        }
        $text = $parts[1] . 'T' . $parts[2];
        $local = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $text, new DateTimeZone($timezone));
        $errors = DateTimeImmutable::getLastErrors();
        if ($local === false || ($errors !== false && $errors['warning_count'] > 0)) {
            throw new PageException(new Message('{field}: enter a date and time, for example 2026-12-01 09:00.', ['field' => $field]));
        }
        if ($local->format(self::FORMAT) !== $text) {
            throw new PageException(new Message('{field}: that time doesn’t exist in your time zone, because the clocks go forward then. Choose another time.', ['field' => $field]));
        }

        return $local->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The UTC time as a form field shows it in the person's time zone, or an empty field.
     */
    public static function field(?DateTimeImmutable $utc, string $timezone): string
    {
        return $utc === null ? '' : $utc->setTimezone(new DateTimeZone($timezone))->format(self::FORMAT);
    }
}
