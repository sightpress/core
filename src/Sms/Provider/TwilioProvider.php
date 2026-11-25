<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Provider;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Sms\Http\SmsHttp;
use SightPress\Module\Exception\SmsException;
use SightPress\Module\Sms\PhoneLineType;
use SightPress\Module\Sms\PhoneLookupResult;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsCapabilities;
use SightPress\Module\Sms\SmsConnection;
use SightPress\Module\Sms\SmsDeliveryStatus;
use SightPress\Module\Sms\SmsFailure;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsOptChange;
use SightPress\Module\Sms\SmsProvider;
use SightPress\Module\Sms\SmsReceipt;
use SightPress\Module\Sms\SmsResult;
use SightPress\Module\Sms\SmsWebhooks;

/**
 * Twilio (N-2, D29): messages through the Messages API, numbers through Lookup v2 with line type intelligence, and
 * status callbacks and STOP / START replies through a webhook signed with the account's auth token
 * (`X-Twilio-Signature`). The connection's account is the account SID (`AC…`), its key the auth token, its sender a
 * number or a name.
 */
final readonly class TwilioProvider implements SmsProvider, SmsWebhooks
{
    public const string ID = 'twilio';

    public const string API = 'https://api.twilio.com/2010-04-01';

    public const string LOOKUP = 'https://lookups.twilio.com/v2/PhoneNumbers/';

    /** Replies that stop or start messages, as carriers and Twilio read them. */
    private const array STOP = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPTOUT', 'REVOKE'];
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
        return 'Twilio';
    }

    public function description(): string
    {
        return 'Twilio, Inc. (United States) sends the messages and checks numbers. It processes numbers and texts in the United States and keeps message records for up to 400 days unless you delete them sooner.';
    }

    public function capabilities(): SmsCapabilities
    {
        return new SmsCapabilities(lookup: true, deliveryReceipts: true, optOutReplies: true, alphanumericSender: true);
    }

    public function send(SmsMessage $message, SmsConnection $connection): SmsResult
    {
        $account = $this->account($connection);
        $sender = $connection->sender ?? throw new SmsException(SmsFailure::InvalidKey, 'Twilio needs a sender: a number or a name.');
        $body = ['To' => $message->to->e164, 'Body' => $message->text, 'From' => $sender];
        [$status, $data] = $this->http->request('Twilio', 'POST', sprintf('%s/Accounts/%s/Messages.json', self::API, rawurlencode($account)), [
            'auth_basic' => [$account, $connection->key],
            'body' => $body,
        ]);
        if ($status >= 400) {
            throw new SmsException(self::failure($status, $data), sprintf('Twilio answered HTTP %d.', $status));
        }
        $sid = is_string($data['sid'] ?? null) ? $data['sid'] : '';
        if ($sid === '') {
            throw new SmsException(SmsFailure::Unavailable, 'Twilio answered without a message id.');
        }

        return new SmsResult($sid, is_numeric($data['num_segments'] ?? null) ? (int) $data['num_segments'] : $message->parts());
    }

    public function lookup(PhoneNumber $number, SmsConnection $connection): PhoneLookupResult
    {
        $account = $this->account($connection);
        [$status, $data] = $this->http->request('Twilio', 'GET', self::LOOKUP . rawurlencode($number->e164), [
            'auth_basic' => [$account, $connection->key],
            'query' => ['Fields' => 'line_type_intelligence'],
        ]);
        if ($status === 404) {
            return new PhoneLookupResult(false);
        }
        if ($status >= 400) {
            throw new SmsException(self::failure($status, $data), sprintf('Twilio answered HTTP %d.', $status));
        }
        $line = is_array($data['line_type_intelligence'] ?? null) ? $data['line_type_intelligence'] : [];
        $country = is_string($data['country_code'] ?? null) && preg_match('/^[A-Z]{2}$/', $data['country_code']) === 1 ? $data['country_code'] : null;

        return new PhoneLookupResult(($data['valid'] ?? false) === true, match ($line['type'] ?? null) {
            'mobile' => PhoneLineType::Mobile,
            'landline' => PhoneLineType::Landline,
            'fixedVoip', 'nonFixedVoip' => PhoneLineType::Voip,
            'tollFree' => PhoneLineType::TollFree,
            'premium', 'sharedCost' => PhoneLineType::PremiumRate,
            default => PhoneLineType::Unknown,
        }, $country);
    }

    public function readWebhook(ServerRequestInterface $request, SmsConnection $connection): array
    {
        $params = $request->getParsedBody();
        $params = is_array($params) ? array_filter($params, static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH) : [];
        /** @var array<string, string> $params */
        ksort($params, SORT_STRING);
        $signed = (string) $request->getUri();
        foreach ($params as $key => $value) {
            $signed .= $key . $value;
        }
        $expected = base64_encode(hash_hmac('sha1', $signed, $connection->key, true));
        if (!hash_equals($expected, $request->getHeaderLine('X-Twilio-Signature')) || ($params['AccountSid'] ?? null) !== $connection->account) {
            throw new SmsException(SmsFailure::InvalidSignature, 'The Twilio webhook signature is missing or wrong.');
        }
        $at = $this->clock->now();
        if (isset($params['MessageStatus'], $params['MessageSid'])) {
            return match ($params['MessageStatus']) {
                'delivered' => [new SmsReceipt($params['MessageSid'], SmsDeliveryStatus::Delivered, $at)],
                'undelivered', 'failed' => [new SmsReceipt($params['MessageSid'], SmsDeliveryStatus::Failed, $at, isset($params['ErrorCode']) ? 'twilio_' . preg_replace('/\D/', '', $params['ErrorCode']) : null)],
                default => [],
            };
        }

        return self::reply($params, $at);
    }

    /**
     * A STOP or START reply (Twilio's `OptOutType`, or the reply's first word).
     *
     * @param array<string, string> $params
     *
     * @return list<SmsOptChange>
     */
    private static function reply(array $params, DateTimeImmutable $at): array
    {
        $first = strtok(trim($params['Body'] ?? ''), " \n");
        $word = strtoupper($params['OptOutType'] ?? ($first === false ? '' : $first));
        $from = $params['From'] ?? '';
        if (preg_match('/^\+[1-9]\d{6,14}$/', $from) !== 1) {
            return [];
        }

        return match (true) {
            in_array($word, self::STOP, true) => [new SmsOptChange(new PhoneNumber($from), true, $at)],
            in_array($word, self::START, true) => [new SmsOptChange(new PhoneNumber($from), false, $at)],
            default => [],
        };
    }

    private function account(SmsConnection $connection): string
    {
        $account = $connection->account;
        if ($account === null || preg_match('/^AC[0-9a-fA-F]{32}$/', $account) !== 1) {
            throw new SmsException(SmsFailure::InvalidKey, 'Twilio needs the account SID, which starts with AC.');
        }

        return $account;
    }

    /**
     * @param array<mixed> $data
     */
    private static function failure(int $status, array $data): SmsFailure
    {
        return match ($data['code'] ?? null) {
            20003, 20404 => SmsFailure::InvalidKey,
            21211, 21614, 21612, 60200 => SmsFailure::InvalidNumber,
            21408 => SmsFailure::UnsupportedCountry,
            21610 => SmsFailure::OptedOut,
            20429, 14107 => SmsFailure::RateLimited,
            30044, 20005 => SmsFailure::NoCredit,
            default => SmsHttp::failureOf($status),
        };
    }
}
