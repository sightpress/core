<?php

declare(strict_types=1);

namespace SightPress\Core\Site;

use Doctrine\DBAL\Connection;
use SightPress\Core\Site\Contract\SiteSettings;

/**
 * Site settings in the site's `site_settings` table, through the DBAL query builder.
 */
final class DbalSiteSettings implements SiteSettings
{
    public const string TABLE = 'site_settings';

    /** @var array<string, string|null> */
    private array $loaded = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function get(string $key): ?string
    {
        if (array_key_exists($key, $this->loaded)) {
            return $this->loaded[$key];
        }

        $value = $this->connection->createQueryBuilder()
            ->select('value')
            ->from(self::TABLE)
            ->where('setting_key = :key')
            ->setParameter('key', $key)
            ->fetchOne();

        return $this->loaded[$key] = is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        if ($this->get($key) === null) {
            $this->connection->insert(self::TABLE, ['setting_key' => $key, 'value' => $value]);
        } else {
            $this->connection->update(self::TABLE, ['value' => $value], ['setting_key' => $key]);
        }

        $this->loaded[$key] = $value;
    }
}
