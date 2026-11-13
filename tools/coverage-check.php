<?php

/**
 * Coverage gate for the unit test suite.
 *
 * Usage: php tools/coverage-check.php [--no-run] [<clover.xml>] [<source dir>]
 *
 * By default it first runs the unit suite with coverage (PCOV, or Xdebug in coverage mode) and writes the Clover
 * report plus an HTML report to build/coverage/. With --no-run it only reads an existing Clover report.
 * While the source directory has no PHP files yet there is nothing to measure, so the gate passes.
 *
 * Fails (exit code 1) when:
 *  - the unit suite fails;
 *  - the Clover report is missing or unreadable;
 *  - line or method coverage of the source directory is below 100%;
 *  - any file in the source directory contains a codeCoverageIgnore annotation.
 *
 * Every uncovered file, method and line is listed so the gap is easy to find.
 */

declare(strict_types=1);

/**
 * @return list<string> one message per file and line that uses a coverage ignore annotation
 */
function findIgnoreAnnotations(string $sourceDir): array
{
    if (!is_dir($sourceDir)) {
        return [];
    }

    $found = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $lines = file($file->getPathname());
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $index => $line) {
            if (stripos($line, 'codeCoverageIgnore') !== false) {
                $found[] = sprintf('%s:%d', $file->getPathname(), $index + 1);
            }
        }
    }

    sort($found);

    return $found;
}

function hasPhpFiles(string $sourceDir): bool
{
    if (!is_dir($sourceDir)) {
        return false;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            return true;
        }
    }

    return false;
}

function runUnitSuite(string $cloverFile): int
{
    $command = sprintf(
        '%s vendor/bin/phpunit --testsuite unit --coverage-clover %s --coverage-html %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($cloverFile),
        escapeshellarg(dirname($cloverFile) . '/html'),
    );

    if (is_file($cloverFile)) {
        unlink($cloverFile);
    }

    putenv('XDEBUG_MODE=coverage');
    passthru($command, $exitCode);

    return $exitCode;
}

/**
 * @return array{statements: int, coveredStatements: int, methods: int, coveredMethods: int, problems: list<string>}
 */
function analyseClover(SimpleXMLElement $clover, string $baseDir): array
{
    $result = ['statements' => 0, 'coveredStatements' => 0, 'methods' => 0, 'coveredMethods' => 0, 'problems' => []];

    $files = $clover->xpath('//file');
    foreach (is_array($files) ? $files : [] as $file) {
        $name = (string) $file['name'];
        if (str_starts_with($name, $baseDir . DIRECTORY_SEPARATOR)) {
            $name = substr($name, strlen($baseDir) + 1);
        }

        /** @var list<array{line: int, name: string}> $methods */
        $methods = [];
        /** @var array<int, int> $statements line number => execution count */
        $statements = [];

        foreach ($file->line as $line) {
            $number = (int) $line['num'];
            if ((string) $line['type'] === 'method') {
                $methods[] = ['line' => $number, 'name' => (string) $line['name']];
            } elseif ((string) $line['type'] === 'stmt') {
                $statements[$number] = (int) $line['count'];
            }
        }

        usort($methods, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        $uncoveredLines = array_keys(array_filter($statements, static fn (int $count): bool => $count === 0));
        $result['statements'] += count($statements);
        $result['coveredStatements'] += count($statements) - count($uncoveredLines);

        $uncoveredMethods = [];
        foreach ($methods as $index => $method) {
            $end = isset($methods[$index + 1]) ? $methods[$index + 1]['line'] : PHP_INT_MAX;
            $missed = array_filter($uncoveredLines, static fn (int $line): bool => $line >= $method['line'] && $line < $end);
            if ($missed !== []) {
                $uncoveredMethods[] = sprintf('%s() (line %d)', $method['name'], $method['line']);
            }
        }

        $result['methods'] += count($methods);
        $result['coveredMethods'] += count($methods) - count($uncoveredMethods);

        if ($uncoveredLines === []) {
            continue;
        }

        $problem = sprintf('  %s', $name);
        foreach ($uncoveredMethods as $method) {
            $problem .= sprintf("\n    method %s", $method);
        }
        $problem .= sprintf("\n    uncovered lines: %s", implode(', ', $uncoveredLines));
        $result['problems'][] = $problem;
    }

    return $result;
}

function percentage(int $covered, int $total): string
{
    return $total === 0 ? '100.00' : sprintf('%.2f', floor($covered / $total * 10000) / 100);
}

/**
 * @param list<string> $argv
 */
function main(array $argv): int
{
    $run = !in_array('--no-run', $argv, true);
    $arguments = array_values(array_filter(
        array_slice($argv, 1),
        static fn (string $argument): bool => $argument !== '--no-run',
    ));
    $cloverFile = $arguments[0] ?? 'build/coverage/clover.xml';
    $sourceDir = rtrim($arguments[1] ?? 'src', DIRECTORY_SEPARATOR);
    $baseDir = (string) getcwd();
    $failed = false;

    if (!hasPhpFiles($sourceDir)) {
        printf("%s has no PHP files yet, so there is no coverage to measure. Coverage gate passed.\n", $sourceDir);

        return 0;
    }

    if ($run && runUnitSuite($cloverFile) !== 0) {
        fwrite(STDERR, "The unit suite failed. Coverage gate FAILED.\n");

        return 1;
    }

    $ignores = findIgnoreAnnotations($sourceDir);
    if ($ignores !== []) {
        $failed = true;
        fwrite(STDERR, "codeCoverageIgnore annotations are not allowed (exclude code in phpunit.xml.dist instead):\n");
        foreach ($ignores as $ignore) {
            fwrite(STDERR, sprintf("  %s\n", $ignore));
        }
    }

    if (!is_file($cloverFile)) {
        fwrite(STDERR, sprintf("Coverage report not found: %s (run `composer test:coverage`).\n", $cloverFile));

        return 1;
    }

    libxml_use_internal_errors(true);
    $clover = simplexml_load_file($cloverFile);
    if ($clover === false) {
        fwrite(STDERR, sprintf("Coverage report is not valid XML: %s\n", $cloverFile));

        return 1;
    }

    $result = analyseClover($clover, $baseDir);

    printf(
        "Unit coverage: lines %s%% (%d/%d), methods %s%% (%d/%d)\n",
        percentage($result['coveredStatements'], $result['statements']),
        $result['coveredStatements'],
        $result['statements'],
        percentage($result['coveredMethods'], $result['methods']),
        $result['coveredMethods'],
        $result['methods'],
    );

    if ($result['coveredStatements'] < $result['statements'] || $result['coveredMethods'] < $result['methods']) {
        $failed = true;
        fwrite(STDERR, "Unit coverage is below 100%. Uncovered code:\n");
        foreach ($result['problems'] as $problem) {
            fwrite(STDERR, $problem . "\n");
        }
    }

    if ($failed) {
        fwrite(STDERR, "Coverage gate FAILED.\n");

        return 1;
    }

    echo "Coverage gate passed.\n";

    return 0;
}

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

exit(main($arguments));
