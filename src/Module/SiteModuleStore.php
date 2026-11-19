<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Crypto\SecretBoxException;

/**
 * A site's own choices of optional modules, in its `site_module` table (one row per module it chose for: on or
 * off, when and by whom), through the DBAL query builder. Read once per site container: changing a choice evicts
 * the container ({@see \SightPress\Core\Tenant\SiteContainerFactory::evict()}).
 */
final class SiteModuleStore
{
    public const string TABLE = 'site_module';

    /** @var array<string, bool>|null */
    private ?array $choices = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Module id => on. Empty while the site's database doesn't have the table yet (a site being created, or one
     * not migrated since the update that added it) or can't be opened (no `APP_SECRET`): every module then follows
     * its default, and whatever really needs the database reports the problem.
     *
     * @return array<string, bool>
     */
    public function choices(): array
    {
        if ($this->choices !== null) {
            return $this->choices;
        }

        try {
            $rows = $this->connection->createQueryBuilder()
                ->select('module_id', 'enabled')
                ->from(self::TABLE)
                ->fetchAllAssociative();
        } catch (DbalException | SecretBoxException) {
            return [];
        }

        $choices = [];
        foreach ($rows as $row) {
            if (is_string($row['module_id'])) {
                $choices[$row['module_id']] = (bool) $row['enabled'];
            }
        }

        return $this->choices = $choices;
    }

    /**
     * Stores the site's choice for a module.
     */
    public function save(string $id, bool $on, DateTimeImmutable $at, ?int $by): void
    {
        $values = ['enabled' => $on, 'changed_at' => $at, 'changed_by' => $by];
        $types = ['enabled' => Types::BOOLEAN, 'changed_at' => Types::DATETIME_IMMUTABLE, 'changed_by' => Types::INTEGER];
        $exists = $this->connection->createQueryBuilder()
            ->select('module_id')
            ->from(self::TABLE)
            ->where('module_id = :id')
            ->setParameter('id', $id)
            ->fetchOne();
        if ($exists === false) {
            $this->connection->insert(self::TABLE, ['module_id' => $id] + $values, ['module_id' => Types::STRING] + $types);
        } else {
            $this->connection->update(self::TABLE, $values, ['module_id' => $id], $types);
        }

        if ($this->choices !== null) {
            $this->choices[$id] = $on;
        }
    }
}
