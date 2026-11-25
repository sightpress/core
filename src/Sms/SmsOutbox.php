<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use DateTimeImmutable;
use SightPress\Core\Sms\Contract\SmsRefused;
use SightPress\Module\Exception\InvalidValueException;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsPurpose;

/**
 * Sends the notifications waiting in the outbox (N-4, gap 8), from `schedule:run`: at most {@see BATCH} a run, each
 * taken with {@see SmsLog::claim()} first so it goes at most once, even if two runs overlap or one crashes. Messages a
 * crashed run left half-sent for {@see INTERRUPTED_AFTER} seconds are marked failed and never sent again: better one
 * missed notification than two.
 */
final readonly class SmsOutbox
{
    public const int BATCH = 50;

    public const int INTERRUPTED_AFTER = 3600;

    public function __construct(
        private SmsLog $log,
        private SmsSender $sender,
    ) {
    }

    /**
     * @return array{sent: int, failed: int, interrupted: int}
     */
    public function run(DateTimeImmutable $now): array
    {
        $done = ['sent' => 0, 'failed' => 0, 'interrupted' => $this->log->failInterrupted($now->modify(sprintf('-%d seconds', self::INTERRUPTED_AFTER)), $now)];
        // Another run may take a message between the two queries: claim() says which run sends it.
        $claimed = array_filter($this->log->queued(self::BATCH), fn (array $queued): bool => $this->log->claim($queued['id'], $now));
        foreach ($claimed as $queued) {
            try {
                $message = new SmsMessage(new PhoneNumber($queued['to']), $queued['text'], SmsPurpose::tryFrom($queued['purpose']) ?? SmsPurpose::Notification);
                $this->sender->deliver($queued['id'], $message, $queued['user_id']);
                ++$done['sent'];
            } catch (SmsRefused|InvalidValueException $exception) {
                if ($exception instanceof InvalidValueException) {
                    $this->log->failed($queued['id'], 'invalid', $now);
                }
                ++$done['failed'];
            }
        }

        return $done;
    }
}
