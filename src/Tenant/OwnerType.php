<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * Who a site belongs to. It picks the starter pages and the schema.org type of the site's owner.
 */
enum OwnerType: string
{
    case Person = 'person';
    case Organization = 'organization';
}
