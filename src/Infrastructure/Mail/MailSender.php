<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Mail;

use Symfony\Component\Mime\Email;

/**
 * Sends email. Every email core sends has an HTML part and a plain-text part with the same content.
 */
interface MailSender
{
    public function send(Email $email): void;
}
