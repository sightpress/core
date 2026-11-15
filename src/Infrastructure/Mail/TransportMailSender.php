<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Mail;

use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends email through the transport `MAILER_DSN` names, from the tenant's sender address unless the email has one.
 */
final readonly class TransportMailSender implements MailSender
{
    public function __construct(
        private TransportInterface $transport,
        private string $from,
        private string $fromName,
    ) {
    }

    public function send(Email $email): void
    {
        if ($email->getFrom() === []) {
            $email = $email->from(new Address($this->from, $this->fromName));
        }

        $this->transport->send($email);
    }
}
