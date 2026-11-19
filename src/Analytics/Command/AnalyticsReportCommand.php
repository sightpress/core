<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Command;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use SightPress\Core\Analytics\AnalyticsRollup;
use SightPress\Core\Analytics\Report\AnalyticsReport;
use SightPress\Core\Analytics\Report\AnalyticsReports;
use SightPress\Core\Analytics\Report\DateRange;
use SightPress\Core\Analytics\Report\ReportTable;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:analytics:report <slug> [--from= --to=] [--report=] [--format=table|csv|json]`: a site's analytics for a
 * range of days (the last 30 by default, in the site's time zone), from the same reports as the admin. Warns when
 * the hourly roll-up hasn't run for over two hours, which means `schedule:run` isn't running.
 */
final readonly class AnalyticsReportCommand extends SiteAnalyticsCommand
{
    public const int DEFAULT_DAYS = 30;

    /** Seconds after which a roll-up that hasn't run is worth a warning. */
    public const int STALE = 7200;

    public function name(): string
    {
        return 'site:analytics:report';
    }

    public function description(): string
    {
        return 'Shows a site\'s analytics for a range of days: visitors, pages, sources, languages, devices, searches, pages not found and bots.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('from', 'First day, as YYYY-MM-DD in the site\'s time zone (default: 30 days ago).', CommandParameterType::Option),
            new CommandParameter('to', 'Last day, as YYYY-MM-DD (default: today).', CommandParameterType::Option),
            new CommandParameter('report', sprintf('Which report: %s, or all (default: summary).', implode(', ', AnalyticsReport::TABLES)), CommandParameterType::Option),
            new CommandParameter('format', 'table, csv or json (default: table).', CommandParameterType::Option),
        ];
    }

    protected function work(ContainerInterface $services, CommandInput $input, CommandOutput $output): int
    {
        $report = Services::get($services, AnalyticsReports::class)->open();
        $format = trim((string) $input->option('format'));
        $format = $format === '' ? 'table' : $format;
        $name = trim((string) $input->option('report'));
        $name = $name === '' ? 'summary' : $name;
        if (!in_array($format, ['table', 'csv', 'json'], true)) {
            $output->error(sprintf('Use --format=table, csv or json, not "%s".', $format));

            return self::FAILURE;
        }
        if ($name !== 'all' && !in_array($name, AnalyticsReport::TABLES, true)) {
            $output->error(sprintf('There is no "%s" report. The reports are: %s, or all.', $name, implode(', ', AnalyticsReport::TABLES)));

            return self::FAILURE;
        }
        if ($name === 'all' && $format === 'csv') {
            $output->error('A CSV holds one report: choose it with --report.');

            return self::FAILURE;
        }
        try {
            $range = $this->range($report, $input);
        } catch (InvalidArgumentException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->warnIfStale($services, $output);

        $tables = array_map(
            static fn (string $table): ReportTable => $report->table($table, $range),
            $name === 'all' ? AnalyticsReport::TABLES : [$name],
        );
        match ($format) {
            'json' => $output->line((string) json_encode([
                'site_time_zone' => $report->timeZone(),
                'from' => $range->from,
                'to' => $range->to,
                'reports' => array_map(static fn (ReportTable $table): array => $table->toArray()['rows'], array_combine(array_map(static fn (ReportTable $table): string => $table->name, $tables), $tables)),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)),
            'csv' => $output->line(rtrim($tables[0]->csv(), "\r\n")),
            default => $this->tables($tables, $report, $range, $output),
        };

        return self::SUCCESS;
    }

    private function range(AnalyticsReport $report, CommandInput $input): DateRange
    {
        $default = $report->lastDays(self::DEFAULT_DAYS);
        $from = trim((string) $input->option('from'));
        $to = trim((string) $input->option('to'));

        return new DateRange($from === '' ? $default->from : $from, $to === '' ? $default->to : $to);
    }

    private function warnIfStale(ContainerInterface $services, CommandOutput $output): void
    {
        $last = Services::get($services, AnalyticsRollup::class)->lastRun();
        $now = Services::get($services, ClockInterface::class)->now();
        if ($last === null || $now->getTimestamp() - $last->getTimestamp() > self::STALE) {
            $output->error(sprintf(
                'Warning: analytics were last rolled up %s. Check that bin/sightpress schedule:run runs every minute.',
                $last === null ? 'never' : $last->format('Y-m-d H:i') . ' UTC',
            ));
        }
    }

    /**
     * @param list<ReportTable> $tables
     */
    private function tables(array $tables, AnalyticsReport $report, DateRange $range, CommandOutput $output): void
    {
        $output->line(sprintf('From %s to %s (days in %s).', $range->from, $range->to, $report->timeZone()));
        foreach ($tables as $table) {
            $output->line();
            $output->line($table->name);
            if ($table->rows === []) {
                $output->line('Nothing counted.');

                continue;
            }
            $cells = [$table->columns];
            foreach ($table->rows as $row) {
                $cells[] = array_map(static fn (string $column): string => self::cell($row[$column] ?? null), $table->columns);
            }
            $widths = [];
            foreach ($cells as $line) {
                foreach ($line as $index => $cell) {
                    $widths[$index] = max($widths[$index] ?? 0, mb_strwidth($cell));
                }
            }
            foreach ($cells as $line) {
                $output->line(rtrim(implode('  ', array_map(
                    static fn (int $index, string $cell): string => $cell . str_repeat(' ', $widths[$index] - mb_strwidth($cell)),
                    array_keys($line),
                    $line,
                ))));
            }
        }
    }

    private static function cell(string|int|float|null $value): string
    {
        return $value === null ? '–' : (string) $value;
    }
}
