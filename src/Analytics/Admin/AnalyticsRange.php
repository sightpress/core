<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Admin;

use InvalidArgumentException;
use SightPress\Core\Analytics\Report\DateRange;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Template\Message;

/**
 * The range of days the Analytics screen shows, from its address: a preset (`?range=7|30|90|365`, the last 30 days
 * by default) or the days of the custom range form (`?from=…&to=…`, both included). A custom range that can't be
 * used keeps the default range and puts its errors on the form, for the error summary.
 */
final readonly class AnalyticsRange
{
    /** The presets, in days. */
    public const array PRESETS = ['7', '30', '90', '365'];

    public const string DEFAULT = '30';

    private function __construct(
        public DateRange $range,
        public ?string $preset,
        public Form $form,
    ) {
    }

    /**
     * @param array<mixed> $query the request's query parameters
     * @param string       $today `Y-m-d` in the site's time zone
     */
    public static function fromQuery(array $query, string $today): self
    {
        $text = static fn (string $key): string => is_string($query[$key] ?? null) ? trim($query[$key]) : '';
        $form = self::form();
        $from = $text('from');
        $to = $text('to');
        if ($from === '' && $to === '') {
            $preset = in_array($text('range'), self::PRESETS, true) ? $text('range') : self::DEFAULT;

            return new self(DateRange::last((int) $preset, $today), $preset, $form);
        }
        $form = $form->withValues(['from' => $from, 'to' => $to]);
        $errors = [];
        if (!DateRange::isDay($from)) {
            $errors['from'] = new Message('Enter the first day as a date, for example 2026-11-01');
        }
        if (!DateRange::isDay($to)) {
            $errors['to'] = new Message('Enter the last day as a date, for example 2026-11-30');
        }
        if ($errors === []) {
            try {
                return new self(new DateRange($from, $to), null, $form);
            } catch (InvalidArgumentException) {
                $errors['to'] = $from > $to
                    ? new Message('The last day must be the same as the first day or after it')
                    : new Message('Choose at most {days} days', ['days' => DateRange::MAX_DAYS]);
            }
        }

        return new self(DateRange::last((int) self::DEFAULT, $today), self::DEFAULT, $form->withErrors($errors));
    }

    public static function form(): Form
    {
        $hint = new Message('A day in the site’s time zone, for example 2026-11-01.');

        return Form::create(
            'analytics-range',
            new Field('from', new Message('First day'), FieldType::Date, $hint),
            new Field('to', new Message('Last day'), FieldType::Date, $hint),
        );
    }

    /**
     * The range's name: the preset's ("Last 30 days"), or "The chosen days".
     */
    public function name(): Message
    {
        return $this->preset === null ? new Message('The chosen days') : self::presetName($this->preset);
    }

    public static function presetName(string $preset): Message
    {
        return match ($preset) {
            '7' => new Message('Last 7 days'),
            '90' => new Message('Last 90 days'),
            '365' => new Message('Last 12 months'),
            default => new Message('Last 30 days'),
        };
    }

    /**
     * The query string that gives this range again, for the CSV links.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return $this->preset === null ? ['from' => $this->range->from, 'to' => $this->range->to] : ['range' => $this->preset];
    }
}
