<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use SightPress\Core\Tenant\TenantConfig;

/**
 * Builds the tenant's logger: one channel per area (`app`, `sites`, `security` …), all written to the log file or
 * stream from the tenant config (`log.path`, for example `php://stderr` in containers), with timestamps in UTC.
 *
 * Never log secrets: passwords, keys, tokens or database credentials.
 */
final readonly class LoggerFactory
{
    public function __construct(private TenantConfig $config)
    {
    }

    public function create(string $channel = 'app'): Logger
    {
        $logger = new Logger($channel);
        $logger->setTimezone(new \DateTimeZone('UTC'));
        $logger->pushHandler(new StreamHandler($this->config->logPath, $this->config->logLevel));
        $logger->pushProcessor(new PsrLogMessageProcessor(removeUsedContextFields: true));

        return $logger;
    }
}
