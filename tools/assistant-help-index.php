<?php

/**
 * Builds the AI assistant's help index (A-5) from the documentation's Markdown.
 *
 * Usage: php tools/assistant-help-index.php <docs repository>/docs [<output>] [--check]
 *
 * Writes resources/assistant/help-index.json by default. The release workflow runs it on the `docs` repository at the
 * same release, so the assistant's answers point to the documentation of the version people run. The same Markdown
 * always gives the same file, so running it again changes nothing. With --check it changes nothing and fails
 * (exit code 1) when the file is out of date.
 */

declare(strict_types=1);

use SightPress\Core\Assistant\Help\HelpIndexBuilder;

// The release workflow runs this without installing dependencies: the builder needs none.
if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    require dirname(__DIR__) . '/vendor/autoload.php';
} else {
    require dirname(__DIR__) . '/src/Assistant/Help/HelpIndexBuilder.php';
}

$given = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], is_string(...))) : [];
$arguments = array_values(array_filter(array_slice($given, 1), static fn (string $argument): bool => $argument !== '--check'));
$check = in_array('--check', $given, true);
$docs = $arguments[0] ?? null;
$output = $arguments[1] ?? dirname(__DIR__) . '/resources/assistant/help-index.json';
if ($docs === null || !is_dir($docs)) {
    fwrite(STDERR, "Usage: php tools/assistant-help-index.php <docs repository>/docs [<output>] [--check]\n");
    exit(2);
}

$chunks = new HelpIndexBuilder()->build($docs);
$json = HelpIndexBuilder::json($chunks);
if ($check) {
    $same = is_file($output) && file_get_contents($output) === $json;
    fwrite($same ? STDOUT : STDERR, $same ? "The help index is up to date.\n" : "The help index is out of date: run php tools/assistant-help-index.php.\n");
    exit($same ? 0 : 1);
}
file_put_contents($output, $json);
printf("Help index: %d parts of %d pages written to %s.\n", count($chunks), count(array_unique(array_column($chunks, 'page'))), $output);
