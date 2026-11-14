<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use DateTimeInterface;
use IntlDateFormatter;
use InvalidArgumentException;

/**
 * Shows a UTC date in the viewer's time zone and language (`intl`), for example "14 Nov 2026, 17:05:00" in en-GB.
 * An unknown locale falls back to ICU's root formats; an unknown time zone is an error.
 */
final readonly class DateFormatter
{
    private const array STYLES = [
        'short' => IntlDateFormatter::SHORT,
        'medium' => IntlDateFormatter::MEDIUM,
        'long' => IntlDateFormatter::LONG,
        'full' => IntlDateFormatter::FULL,
    ];

    /**
     * @param string $style short, medium, long or full
     */
    public function dateTime(?DateTimeInterface $value, Viewer $viewer, string $style = 'medium'): string
    {
        return $this->format($value, $viewer, $this->style($style), $this->style($style));
    }

    /**
     * @param string $style short, medium, long or full
     */
    public function date(?DateTimeInterface $value, Viewer $viewer, string $style = 'long'): string
    {
        return $this->format($value, $viewer, $this->style($style), IntlDateFormatter::NONE);
    }

    private function format(?DateTimeInterface $value, Viewer $viewer, int $dateType, int $timeType): string
    {
        if ($value === null) {
            return '';
        }

        $formatter = new IntlDateFormatter(
            str_replace('-', '_', $viewer->locale),
            $dateType,
            $timeType,
            $viewer->timezone,
            IntlDateFormatter::GREGORIAN,
        );
        $text = $formatter->format($value);

        return is_string($text) ? $text : '';
    }

    private function style(string $style): int
    {
        return self::STYLES[$style] ?? throw new InvalidArgumentException(sprintf(
            'Date style "%s" is not valid: use short, medium, long or full.',
            $style,
        ));
    }
}
