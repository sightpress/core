<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\Tenant\EnabledModules;

/**
 * Empties the site's text message outbox on every `schedule:run` (N-4), and forgets webhook reports older than
 * {@see SmsReports::KEEP_DAYS} and messages older than {@see SmsLog::KEEP_DAYS} days.
 */
final readonly class SmsOutboxJob
{
    public function __invoke(ScheduleDue $event): void
    {
        // `schedule:run` asks every site's jobs; a site with text messages off has no outbox.
        if (!Services::get($event->services, EnabledModules::class)->has(SmsModule::ID)) {
            return;
        }
        ['sent' => $sent, 'failed' => $failed, 'interrupted' => $interrupted] = Services::get($event->services, SmsOutbox::class)->run($event->now);
        Services::get($event->services, SmsReports::class)->forgetBefore($event->now->modify(sprintf('-%d days', SmsReports::KEEP_DAYS)));
        Services::get($event->services, SmsLog::class)->forgetBefore($event->now->modify(sprintf('-%d days', SmsLog::KEEP_DAYS)));
        if ($sent + $failed + $interrupted > 0) {
            $event->report(sprintf('Text messages: sent %d, failed %d, %d left by an interrupted run marked failed.', $sent, $failed, $interrupted));
        }
    }
}
