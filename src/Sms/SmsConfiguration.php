<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use SightPress\Core\Template\Message;
use SightPress\Module\Sms\SmsConnection;
use SightPress\Module\Sms\SmsProvider;

/**
 * What a site sends text messages with: the provider, the connection (key, account, sender, region), the monthly
 * budget in message parts (0 = no limit) and where it came from (the site's own settings or the tenant's default).
 * {@see $problem} says in plain language what is missing; it is null when the site can send.
 */
final readonly class SmsConfiguration
{
    public const string SITE = 'site';
    public const string TENANT = 'tenant';

    /**
     * @param int<0, max>       $budget
     * @param int<0, max>       $reservedForCodes
     * @param self::SITE|self::TENANT $source
     */
    public function __construct(
        public ?SmsProvider $provider,
        public ?SmsConnection $connection,
        public int $budget,
        public int $reservedForCodes,
        public string $source,
        public ?Message $problem,
    ) {
    }

    public function isReady(): bool
    {
        return $this->problem === null && $this->provider !== null && $this->connection !== null;
    }
}
