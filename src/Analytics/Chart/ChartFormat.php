<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Chart;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use NumberFormatter;

/**
 * Numbers and days written the way a locale writes them: `1,349` / `1 349`, `12%` / `12 %`, "Tuesday 4 November" /
 * "terça-feira, 4 de novembro". Days are `Y-m-d` already in the site's time zone, so they are never shifted.
 */
final readonly class ChartFormat
{
    public function __construct(public string $locale)
    {
    }

    public function number(int|float $value): string
    {
        $formatter = new NumberFormatter($this->icu(), NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return (string) $formatter->format($value);
    }

    /**
     * A change in per cent, without its sign (the sentence says up or down): 12.5 → "12.5%".
     */
    public function percent(float $change): string
    {
        $formatter = new NumberFormatter($this->icu(), NumberFormatter::PERCENT);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 1);

        return (string) $formatter->format(abs($change) / 100);
    }

    /**
     * A day in full, with the weekday: "Tuesday 4 November".
     */
    public function day(string $day): string
    {
        return $this->format($day, 'EEEEdMMMM');
    }

    /**
     * A day for an axis: "4 Nov".
     */
    public function shortDay(string $day): string
    {
        return $this->format($day, 'dMMM');
    }

    /**
     * A day with its year, for ranges: "4 November 2026".
     */
    public function longDay(string $day): string
    {
        return $this->format($day, 'dMMMMy');
    }

    private function format(string $day, string $skeleton): string
    {
        $pattern = new IntlDatePatternGenerator($this->icu())->getBestPattern($skeleton);
        $formatter = new IntlDateFormatter($this->icu(), IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, is_string($pattern) ? $pattern : null);

        return (string) $formatter->format(new DateTimeImmutable($day, new DateTimeZone('UTC')));
    }

    private function icu(): string
    {
        return str_replace('-', '_', $this->locale);
    }
}
