<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Mail\TransportMailSender;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

#[CoversClass(TransportMailSender::class)]
final class TransportMailSenderTest extends TestCase
{
    public function testSendsFromTheTenantAddressUnlessTheEmailHasOne(): void
    {
        $transport = new class () implements TransportInterface {
            /** @var list<RawMessage> */
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->sent[] = $message;

                return null;
            }

            public function __toString(): string
            {
                return 'recording://';
            }
        };
        $sender = new TransportMailSender($transport, 'no-reply@example.org', 'Example Sites');

        $sender->send(new Email()->to('ada@example.org')->subject('Hello')->text('Hi'));
        $sender->send(new Email()->from(new Address('team@example.org', 'Team'))->to('ada@example.org')->text('Hi'));

        self::assertCount(2, $transport->sent);
        self::assertInstanceOf(Email::class, $transport->sent[0]);
        self::assertSame('"Example Sites" <no-reply@example.org>', $transport->sent[0]->getFrom()[0]->toString());
        self::assertInstanceOf(Email::class, $transport->sent[1]);
        self::assertSame('"Team" <team@example.org>', $transport->sent[1]->getFrom()[0]->toString());
    }
}
