<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

use SightPress\Core\Template\Message;
use SightPress\Module\Assistant\AssistantConnection;
use SightPress\Module\Assistant\AssistantProvider;

/**
 * What the assistant of a site uses right now ({@see AssistantSetup}): the provider and model, the connection (key
 * and base URL), the monthly budget, and whether that is the site's own choice or the tenant's default. When it
 * can't be used yet, {@see $problem} says why.
 */
final readonly class AssistantConfiguration
{
    public const string SITE = 'site';
    public const string TENANT = 'tenant';

    /**
     * @param non-empty-string|null $model
     * @param int<0, max>          $budget tokens a month; 0 = no limit
     * @param self::SITE|self::TENANT $source
     */
    public function __construct(
        public ?AssistantProvider $provider,
        public ?string $model,
        public ?AssistantConnection $connection,
        public int $budget,
        public string $source,
        public ?Message $problem = null,
    ) {
    }

    public function isReady(): bool
    {
        return $this->problem === null && $this->provider !== null && $this->model !== null && $this->connection !== null;
    }
}
