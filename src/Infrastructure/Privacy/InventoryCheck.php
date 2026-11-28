<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Privacy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\Mapping\Column;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Privacy\PersonalData;
use SightPress\Module\Privacy\PersonalDataProvider;
use Throwable;

/**
 * Keeps the personal data inventory and the code in step (GD-1, ADR-0075), for `composer check`:
 *
 * - every `#[PersonalData]` field names a data set that one of the modules' providers describes;
 * - every entity column that looks like it holds a person (a user id, `*_by`, an email address, a phone number) is
 *   marked with `#[PersonalData]`;
 * - every module whose migrations add such a column registers at least one personal data provider.
 *
 * Providers are asked for {@see PersonalDataProvider::describe()} without being built, so `describe()` must not use
 * the services the provider gets.
 */
final readonly class InventoryCheck
{
    /** Column names that hold a person, or a way to reach one. */
    public const string PERSON_COLUMN = '/^(?:[a-z0-9_]*_)?user_id$|^(?:actor|author|owner|account|uploader)_id$|_by$|(?:^|_)(?:email|phone|phone_e164|ip_address)$/';

    private Connection $connection;

    public function __construct(private ModuleRegistry $registry)
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    /**
     * @param list<class-string> $entities classes to look for `#[PersonalData]` and person columns in
     *
     * @return list<string> what is wrong, in plain words; empty when the inventory is complete
     */
    public function problems(array $entities): array
    {
        [$keys, $problems] = $this->dataSets();
        foreach ($entities as $class) {
            foreach (self::fields($class) as [$property, $column, $dataSet]) {
                $name = sprintf('%s::$%s', $class, $property);
                if ($dataSet === null && $column !== null && preg_match(self::PERSON_COLUMN, $column) === 1) {
                    $problems[] = sprintf('%s (column %s) looks like it holds a person, but isn\'t marked with #[PersonalData].', $name, $column);
                } elseif ($dataSet !== null && !isset($keys[$dataSet])) {
                    $problems[] = sprintf('%s is personal data of data set "%s", which no module\'s personal data provider describes.', $name, $dataSet);
                }
            }
        }
        $withProviders = array_flip(array_values($keys));
        foreach ($this->personColumns() as $module => $columns) {
            if (!isset($withProviders[$module])) {
                $problems[] = sprintf('Module "%s" keeps people in %s, but registers no personal data provider. Add one with $registrar->privacy()->addProvider().', $module, implode(', ', $columns));
            }
        }

        return $problems;
    }

    /**
     * @return array{array<string, string>, list<string>} data set key => module id, and the problems found
     */
    private function dataSets(): array
    {
        $keys = [];
        $problems = [];
        foreach ($this->registry->personalDataProviders() as $registered) {
            try {
                $key = new ReflectionClass($registered->item)->newInstanceWithoutConstructor()->describe()->key;
            } catch (Throwable $exception) {
                $problems[] = sprintf('Personal data provider %s of module "%s" can\'t describe its data set without its services: %s', $registered->item, $registered->module, $exception->getMessage());
                continue;
            }
            if (isset($keys[$key])) {
                $problems[] = sprintf('Data set "%s" is described twice (module "%s" and module "%s").', $key, $keys[$key], $registered->module);
            }
            $keys[$key] = $registered->module;
        }

        return [$keys, $problems];
    }

    /**
     * @param class-string $class
     *
     * @return list<array{string, string|null, string|null}> property, column (null when not mapped) and data set
     */
    private static function fields(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $fields = [];
        foreach ($reflection->getProperties() as $property) {
            $fields[] = [$property->getName(), self::column($property), self::dataSet($property)];
        }

        return $fields;
    }

    private static function column(ReflectionProperty $property): ?string
    {
        foreach ($property->getAttributes(Column::class) as $attribute) {
            $column = $attribute->newInstance();

            return $column->name ?? strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property->getName()));
        }

        return null;
    }

    private static function dataSet(ReflectionProperty $property): ?string
    {
        // On a promoted constructor parameter, PHP gives the attribute to the property too.
        foreach ($property->getAttributes(PersonalData::class) as $attribute) {
            return $attribute->newInstance()->dataSet;
        }

        return null;
    }

    /**
     * The person columns each module's migrations add, found by running every migration, in version order, on an
     * empty schema (without a database).
     *
     * @return array<string, list<string>> module id => `table.column`
     */
    private function personColumns(): array
    {
        $migrations = [];
        foreach ($this->registry->migrations() as $registered) {
            $provider = $registered->item;
            $files = glob($provider->directory() . '/Version*.php');
            foreach ($files === false ? [] : $files as $file) {
                $class = $provider->namespace() . '\\' . basename($file, '.php');
                if (is_subclass_of($class, AbstractMigration::class)) {
                    $migrations[basename($file, '.php') . '|' . $class] = [$class, $registered->module];
                }
            }
        }
        ksort($migrations);
        $schema = new Schema();
        $found = [];
        foreach ($migrations as [$class, $module]) {
            $before = self::columns($schema);
            new $class($this->connection, new NullLogger())->up($schema);
            foreach (array_diff(self::columns($schema), $before) as $column) {
                if (preg_match(self::PERSON_COLUMN, substr($column, (int) strpos($column, '.') + 1)) === 1) {
                    $found[$module][] = $column;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string> `table.column`
     */
    private static function columns(Schema $schema): array
    {
        $columns = [];
        foreach ($schema->getTables() as $table) {
            foreach ($table->getColumns() as $column) {
                $columns[] = $table->getObjectName()->toString() . '.' . $column->getObjectName()->toString();
            }
        }

        return $columns;
    }
}
