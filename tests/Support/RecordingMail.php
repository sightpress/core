<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use SightPress\Core\Infrastructure\Mail\MailSender;
use Symfony\Component\Mime\Email;

/**
 * Keeps every email instead of sending it.
 */
final class RecordingMail implements MailSender
{
    /** @var list<Email> */
    public array $sent = [];

    public function send(Email $email): void
    {
        $this->sent[] = $email;
    }

    /**
     * @return list<string> subjects of the emails to that address
     */
    public function subjectsTo(string $address): array
    {
        $subjects = [];
        foreach ($this->sent as $email) {
            if (($email->getTo()[0] ?? null)?->getAddress() === $address) {
                $subjects[] = (string) $email->getSubject();
            }
        }

        return $subjects;
    }
}
