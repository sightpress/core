<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps what was logged, with the placeholders filled in, for tests.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string}> level and message */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }
        $this->records[] = [is_string($level) ? $level : 'unknown', strtr((string) $message, $replace)];
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(static fn (array $record): string => $record[1], $this->records);
    }
}
