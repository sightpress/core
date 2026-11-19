<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * Someone turned an optional module on or off for a site, or deleted its data. Dispatched by the site's own
 * dispatcher before the change takes effect there: the audit log records it, and the roles module gives the admin
 * role the permissions of a module that was turned on.
 */
final readonly class SiteModuleChanged
{
    public const string ENABLED = 'enabled';
    public const string DISABLED = 'disabled';
    public const string DATA_DELETED = 'data_deleted';

    /**
     * @param self::ENABLED|self::DISABLED|self::DATA_DELETED $change
     */
    public function __construct(
        public string $moduleId,
        public string $name,
        public string $change,
        public ?int $actorId,
    ) {
    }
}
