<?php

/**
 * Personal data inventory check (GD-1, ADR-0075).
 *
 * Usage: php tools/privacy-check.php
 *
 * Fails (exit code 1) when an entity field marked `#[PersonalData]` names a data set no provider describes, an entity
 * column that looks like it holds a person isn't marked, or a core module adds such a column to the database without
 * registering a personal data provider. See SightPress\Core\Infrastructure\Privacy\InventoryCheck.
 */

declare(strict_types=1);

use SightPress\Core\Infrastructure\Privacy\InventoryCheck;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;

require dirname(__DIR__) . '/vendor/autoload.php';

// Every core module, the ones that are off by default too.
$candidates = CoreModules::candidates();
$all = array_fill_keys(array_map(static fn ($candidate): string => $candidate->module->manifest()->id, $candidates), true);
$registry = ModuleRegistry::register(new ModuleLoader('0.6.0')->catalogue($candidates)->resolve(new ModuleSelection($all)));
$src = dirname(__DIR__) . '/src';
$entities = [];
foreach (CoreModules::entityPaths() as $path) {
    $files = glob($path . '/*.php');
    foreach ($files === false ? [] : $files as $file) {
        $class = 'SightPress\\Core\\' . str_replace('/', '\\', substr($file, strlen($src) + 1, -4));
        if (class_exists($class)) {
            $entities[] = $class;
        }
    }
}

$problems = new InventoryCheck($registry)->problems($entities);
foreach ($problems as $problem) {
    fwrite(STDERR, '✗ ' . $problem . PHP_EOL);
}
if ($problems !== []) {
    exit(1);
}
printf('Personal data inventory: %d data sets, %d entities checked, nothing missing.%s', count($registry->personalDataProviders()), count($entities), PHP_EOL);
