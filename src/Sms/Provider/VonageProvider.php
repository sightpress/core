<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Provider;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;
use SightPress\Core\Sms\Http\SmsHttp;
use SightPress\Module\Exception\SmsException;
use SightPress\Module\Sms\PhoneLineType;
use SightPress\Module\Sms\PhoneLookupResult;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsCapabilities;
use SightPress\Module\Sms\SmsConnection;
use SightPress\Module\Sms\SmsDeliveryStatus;
use SightPress\Module\Sms\SmsEncoding;
use SightPress\Module\Sms\SmsFailure;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsOptChange;
use SightPress\Module\Sms\SmsProvider;
use SightPress\Module\Sms\SmsReceipt;
use SightPress\Module\Sms\SmsResult;
use SightPress\Module\Sms\SmsWebhooks;

/**
 * Vonage (N-2, D29): messages through the SMS API, numbers through Number Insight Standard, and delivery receipts and
 * STOP / START replies through webhooks signed with the account's signature secret (HMAC-SHA256, `sig` and
 * `timestamp`; requests older than {@see MAX_AGE} seconds are refused as replays). The connection's account is the API
 * key; its key is the API secret, then `|` and the signature secret (`secret|signature-secret`); its sender a number
 * or a name.
 */
final readonly class VonageProvider implements SmsProvider, SmsWebhooks
{
    public const string ID = 'vonage';

    public const string SMS_API = 'https://rest.nexmo.com/sms/json';

    public const string INSIGHT = 'https://api.nexmo.com/ni/standard/json';

    /** Seconds a signed webhook request stays valid. */
    public const int MAX_AGE = 300;

    private const array STOP = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];
    private const array START = ['START', 'YES', 'UNSTOP'];

    public function __construct(private SmsHttp $http, private ClockInterface $clock)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Vonage';
    }

    public function description(): string
    {
        return 'Vonage (Vonage Holdings, with offices in the UK and the EU) sends the messages and checks numbers. It processes numbers and texts in the United States or the EU, as your account is set up, and keeps message records for up to 13 months.';
    }

    public function capabilities(): SmsCapabilities
    {
        return new SmsCapabilities(lookup: true, deliveryReceipts: true, optOutReplies: true, alphanumericSender: true);
    }

    public function send(SmsMessage $message, SmsConnection $connection): SmsResult
    {
        [$key, $secret] = $this->credentials($connection);
        $sender = $connection->sender ?? throw new SmsException(SmsFailure::InvalidKey, 'Vonage needs a sender: a number or a name.');
        $body = [
            'api_key' => $key,
            'api_secret' => $secret,
            'from' => ltrim($sender, '+'),
            'to' => ltrim($message->to->e164, '+'),
            'text' => $message->text,
        ];
        if ($message->encoding() === SmsEncoding::Ucs2) {
            $body['type'] = 'unicode';
        }
        if ($message->reference !== null) {
            $body['client-ref'] = $message->reference;
        }
        [$status, $data] = $this->http->request('Vonage', 'POST', self::SMS_API, ['body' => $body]);
        if ($status >= 400) {
            throw new SmsException(SmsHttp::failureOf($status), sprintf('Vonage answered HTTP %d.', $status));
        }
        $parts = is_array($data['messages'] ?? null) ? array_values(array_filter($data['messages'], is_array(...))) : [];
        $first = $parts[0] ?? [];
        $code = is_scalar($first['status'] ?? null) ? (string) $first['status'] : '';
        if ($code !== '0') {
            throw new SmsException(self::failure($code), sprintf('Vonage refused the message (status %s).', $code === '' ? 'missing' : $code));
        }
        $id = is_string($first['message-id'] ?? null) ? $first['message-id'] : '';
        if ($id === '') {
            throw new SmsException(SmsFailure::Unavailable, 'Vonage answered without a message id.');
        }

        return new SmsResult($id, max(1, count($parts)));
    }

    public function lookup(PhoneNumber $number, SmsConnection $connection): PhoneLookupResult
    {
        [$key, $secret] = $this->credentials($connection);
        [$status, $data] = $this->http->request('Vonage', 'GET', self::INSIGHT, [
            'query' => ['api_key' => $key, 'api_secret' => $secret, 'number' => ltrim($number->e164, '+')],
        ]);
        if ($status >= 400) {
            throw new SmsException(SmsHttp::failureOf($status), sprintf('Vonage answered HTTP %d.', $status));
        }
        $code = is_scalar($data['status'] ?? null) ? (string) $data['status'] : '';
        if ($code === '3') {
            return new PhoneLookupResult(false);
        }
        if ($code !== '0') {
            throw new SmsException(self::failure($code), sprintf('Vonage couldn\'t check the number (status %s).', $code === '' ? 'missing' : $code));
        }
        $carrier = is_array($data['current_carrier'] ?? null) ? $data['current_carrier'] : [];
        $country = is_string($data['country_code'] ?? null) && preg_match('/^[A-Z]{2}$/', $data['country_code']) === 1 ? $data['country_code'] : null;

        return new PhoneLookupResult(true, match ($carrier['network_type'] ?? null) {
            'mobile' => PhoneLineType::Mobile,
            'landline' => PhoneLineType::Landline,
            'landline_premium' => PhoneLineType::PremiumRate,
            'landline_tollfree' => PhoneLineType::TollFree,
            'virtual' => PhoneLineType::Voip,
            default => PhoneLineType::Unknown,
        }, $country);
    }

    public function readWebhook(ServerRequestInterface $request, SmsConnection $connection): array
    {
        $body = $request->getParsedBody();
        $params = array_merge($request->getQueryParams(), is_array($body) ? $body : []);
        $params = array_filter($params, static fn (mixed $value, mixed $key): bool => is_string($key) && (is_string($value) || is_int($value)), ARRAY_FILTER_USE_BOTH);
        /** @var array<string, string|int> $params */
        $signature = is_string($params['sig'] ?? null) ? $params['sig'] : '';
        unset($params['sig']);
        $signatureSecret = $this->credentials($connection)[2];
        $timestamp = is_numeric($params['timestamp'] ?? null) ? (int) $params['timestamp'] : 0;
        $now = $this->clock->now();
        ksort($params, SORT_STRING);
        $signed = '';
        foreach ($params as $key => $value) {
            $signed .= '&' . $key . '=' . str_replace(['&', '='], '_', (string) $value);
        }
        if (
            $signatureSecret === null
            || $signature === ''
            || !hash_equals(strtoupper(hash_hmac('sha256', $signed, $signatureSecret)), strtoupper($signature))
            || abs($now->getTimestamp() - $timestamp) > self::MAX_AGE
            || (string) ($params['api-key'] ?? $connection->account) !== $connection->account
        ) {
            throw new SmsException(SmsFailure::InvalidSignature, 'The Vonage webhook signature is missing, wrong or too old.');
        }
        $text = static fn (string $name): string => is_scalar($params[$name] ?? null) ? (string) $params[$name] : '';
        if ($text('messageId') !== '' && $text('status') !== '') {
            return match ($text('status')) {
                'delivered' => [new SmsReceipt($text('messageId'), SmsDeliveryStatus::Delivered, $now)],
                'failed', 'rejected', 'expired' => [new SmsReceipt($text('messageId'), SmsDeliveryStatus::Failed, $now, 'vonage_' . ($text('err-code') !== '' ? (string) preg_replace('/\D/', '', $text('err-code')) : $text('status')))],
                default => [],
            };
        }

        $first = strtok(trim($text('text')), " \n");

        return self::reply('+' . ltrim($text('msisdn'), '+'), strtoupper($text('keyword') !== '' ? $text('keyword') : ($first === false ? '' : $first)), $now);
    }

    /**
     * @return list<SmsOptChange>
     */
    private static function reply(string $from, string $word, DateTimeImmutable $at): array
    {
        if (preg_match('/^\+[1-9]\d{6,14}$/', $from) !== 1) {
            return [];
        }

        return match (true) {
            in_array($word, self::STOP, true) => [new SmsOptChange(new PhoneNumber($from), true, $at)],
            in_array($word, self::START, true) => [new SmsOptChange(new PhoneNumber($from), false, $at)],
            default => [],
        };
    }

    /**
     * The API key, the API secret and the signature secret (null when the key has none).
     *
     * @return array{string, string, string|null}
     */
    private function credentials(#[SensitiveParameter] SmsConnection $connection): array
    {
        $account = $connection->account;
        if ($account === null || preg_match('/^[A-Za-z0-9]{6,32}$/', $account) !== 1) {
            throw new SmsException(SmsFailure::InvalidKey, 'Vonage needs the API key as the account.');
        }
        [$secret, $signature] = array_pad(explode('|', $connection->key, 2), 2, '');

        return [$account, $secret, $signature === '' ? null : $signature];
    }

    private static function failure(string $code): SmsFailure
    {
        return match ($code) {
            '1' => SmsFailure::RateLimited,
            '3', '6' => SmsFailure::InvalidNumber,
            '4', '8', '14', '15' => SmsFailure::InvalidKey,
            '7' => SmsFailure::OptedOut,
            '9' => SmsFailure::NoCredit,
            '29' => SmsFailure::UnsupportedCountry,
            default => SmsFailure::Unavailable,
        };
    }
}
