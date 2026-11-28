<?php

declare(strict_types=1);

namespace SightPress\Core\Privacy;

use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Privacy\ProcessorDeclaration;
use SightPress\Module\Privacy\ProcessorSource;
use SightPress\Module\Text\Message;

/**
 * The mail server that sends the site's email (`MAILER_DSN`), when it is someone else's (ADR-0075). Nothing is
 * declared while email isn't sent (`null://`), goes through the server's own `sendmail`, or a mail server on the
 * operator's network (a host without a domain, such as `mailpit`). The mailer doesn't use the outgoing HTTP client.
 */
final readonly class MailerProcessors implements ProcessorSource
{
    public const string ID = 'privacy.mailer';

    private const string UNKNOWN_COUNTRY = 'ZZ';

    public function __construct(private TenantConfig $config)
    {
    }

    public function processors(): iterable
    {
        $parts = parse_url($this->config->mailerDsn);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (in_array($scheme, ['', 'null', 'sendmail', 'native'], true) || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }
        return [new ProcessorDeclaration(
            self::ID,
            $host,
            new Message('Email addresses and the text of the emails the site sends: codes, invitations, notifications and contact form messages.'),
            [self::UNKNOWN_COUNTRY],
        )];
    }
}
