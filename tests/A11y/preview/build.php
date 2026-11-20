<?php

/**
 * Writes the static preview of the default theme for theme-preview.spec.ts, as `theme:preview default --out=<folder>`
 * would: `php tests/A11y/preview/build.php <folder>`.
 */

declare(strict_types=1);

use SightPress\Core\Tests\Support\ArrayCommandInput;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Core\Theme\Command\PreviewThemeCommand;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$out = $argv[1] ?? throw new InvalidArgumentException('Name the folder to write the preview to.');
$output = new RecordingOutput();
$status = new PreviewThemeCommand()->run(new ArrayCommandInput(['theme' => 'default'], ['out' => $out]), $output);
fwrite($status === 0 ? STDOUT : STDERR, implode("\n", [...$output->lines, ...$output->errors]) . "\n");
exit($status);
