<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Report;

/**
 * One report as rows and columns, the one shape the CLI (table, CSV, JSON) and the admin's CSV downloads share, so
 * their numbers are always the same.
 */
final readonly class ReportTable
{
    /**
     * @param list<string>                                    $columns
     * @param list<array<string, string|int|float|null>> $rows    column => value
     */
    public function __construct(
        public string $name,
        public DateRange $range,
        public array $columns,
        public array $rows,
    ) {
    }

    /**
     * UTF-8 CSV (RFC 4180) with a header row. Text that a spreadsheet would run as a formula starts with `'`.
     *
     * @param list<string>|null $header the header row; the column names by default
     */
    public function csv(?array $header = null): string
    {
        $csv = self::line($header ?? $this->columns);
        foreach ($this->rows as $row) {
            $csv .= self::line(array_map(static fn (string $column): string => self::cell($row[$column] ?? null), $this->columns));
        }

        return $csv;
    }

    /**
     * @return array{report: string, from: string, to: string, rows: list<array<string, string|int|float|null>>}
     */
    public function toArray(): array
    {
        return ['report' => $this->name, 'from' => $this->range->from, 'to' => $this->range->to, 'rows' => $this->rows];
    }

    /**
     * @param list<string> $fields
     */
    private static function line(array $fields): string
    {
        return implode(',', array_map(
            static fn (string $field): string => preg_match('/[",\s]/', $field) === 1 ? '"' . str_replace('"', '""', $field) . '"' : $field,
            $fields,
        )) . "\r\n";
    }

    private static function cell(string|int|float|null $value): string
    {
        if (!is_string($value)) {
            return $value === null ? '' : (string) $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }
}
