<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Http;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Sms\Contract\TextMessageOptChanged;
use SightPress\Core\Sms\SmsLog;
use SightPress\Core\Sms\SmsNumberHash;
use SightPress\Core\Sms\SmsOptOuts;
use SightPress\Core\Sms\SmsReports;
use SightPress\Core\Sms\SmsSetup;
use SightPress\Module\Exception\SmsException;
use SightPress\Module\Sms\SmsOptChange;
use SightPress\Module\Sms\SmsWebhooks;

/**
 * `POST /_sms/webhook` (N-2): delivery receipts and STOP / START replies from the site's text message provider. It
 * answers 404 when the provider has no webhooks and 403 when the signature is missing or wrong, and changes nothing
 * for a request it has already handled (a replay). It needs no session and no CSRF token: the provider's signature is
 * the proof.
 */
final readonly class SmsWebhookHandler implements RequestHandlerInterface
{
    public const string PATH = '/_sms/webhook';

    public function __construct(
        private SmsSetup $setup,
        private SmsLog $log,
        private SmsOptOuts $optOuts,
        private SmsReports $reports,
        private SmsNumberHash $hash,
        private EventDispatcherInterface $events,
        private ResponseFactoryInterface $responses,
        private ClockInterface $clock,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $configuration = $this->setup->configuration();
        $provider = $configuration->provider;
        $connection = $configuration->connection;
        if (!$provider instanceof SmsWebhooks || $connection === null) {
            return $this->answer(404, 'There is no text message webhook here.');
        }
        try {
            $reports = $provider->readWebhook($request, $connection);
        } catch (SmsException) {
            return $this->answer(403, 'The signature is missing or wrong.');
        }
        $body = $request->getBody();
        $body->rewind();
        $digest = hash('sha256', $provider->id() . "\n" . $request->getUri()->getQuery() . "\n" . $body->getContents());
        if (!$this->reports->first($digest, $this->clock->now())) {
            return $this->answer(200, 'Already received.');
        }
        foreach ($reports as $report) {
            if ($report instanceof SmsOptChange) {
                $hash = $this->hash->of($report->from);
                if ($report->stopped) {
                    $this->optOuts->stop($hash, $report->at);
                } else {
                    $this->optOuts->start($hash);
                }
                $this->events->dispatch(new TextMessageOptChanged($report->from->masked(), $hash, $report->stopped));
            } else {
                $this->log->receipt($provider->id(), $report->messageId, $report->status, $report->reason, $report->at);
            }
        }

        return $this->answer(200, 'Received.');
    }

    private function answer(int $status, string $text): ResponseInterface
    {
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
        $response->getBody()->write($text);

        return $response;
    }
}
