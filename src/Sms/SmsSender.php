<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use Psr\Clock\ClockInterface;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Infrastructure\Sms\PhoneNumbers;
use SightPress\Core\Sms\Contract\SmsRefused;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Exception\SmsException;
use SightPress\Module\Sms\PhoneLineType;
use SightPress\Module\Sms\PhoneLookupResult;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsFailure;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsConnection;
use SightPress\Module\Sms\SmsProvider;
use SightPress\Module\Sms\SmsPurpose;
use SightPress\Module\Sms\SmsResult;

/**
 * Sends the site's text messages and checks numbers (N-2, ADR-0069), with every guard against misuse in one place
 * (gap 6, SMS pumping): only mobile numbers in the allowed countries, no notifications to numbers that replied STOP,
 * limits per number, client address, account and site, a daily cap on paid lookups, and the monthly budget, of which
 * a reserved part is kept for security codes (gap 10). Every message is logged in {@see SmsLog} with the number masked.
 */
final readonly class SmsSender
{
    public function __construct(
        private SmsSetup $setup,
        private SmsLog $log,
        private SmsOptOuts $optOuts,
        private SmsNumberHash $hash,
        private PhoneNumbers $numbers,
        private RateLimiters $limiters,
        private ClockInterface $clock,
        private TenantConfig $config,
        private Site $site,
    ) {
    }

    /**
     * Sends one message now. Security codes are counted per number, client address and account; every message per
     * site.
     *
     * @param int|null    $userId whose account it is for, when there is one
     * @param string|null $client the client address that asked for it, for a code someone asked for on a page
     *
     * @throws SmsRefused
     */
    public function send(SmsMessage $message, ?int $userId = null, ?string $client = null): SmsResult
    {
        [$provider, $connection] = $this->ready();
        $this->checkLocally($message->to, $provider);
        $code = $message->purpose === SmsPurpose::SecurityCode;
        $hash = $this->hash->of($message->to);
        if (!$code && $this->optOuts->stopped($hash)) {
            throw new SmsRefused(SmsError::message(SmsFailure::OptedOut), SmsFailure::OptedOut);
        }
        if ($code) {
            $this->limit('sms_client', $client);
            $this->limit('sms_account', $userId === null ? null : (string) $userId);
            $this->limit('sms_number', $hash);
        }
        $this->limit('sms_site', $this->site->id);
        if (!$this->budget()->allows($message->parts(), $code)) {
            throw new SmsRefused(new Message('This site has used its text message budget for this month. Ask whoever runs your sites to raise it.'), SmsFailure::NoCredit);
        }

        $id = $this->log->start($message, $hash, $provider->id(), $userId, $this->clock->now());
        try {
            $result = $provider->send($message, $connection);
        } catch (SmsException $exception) {
            $this->log->failed($id, $exception->failure->value, $this->clock->now());

            throw new SmsRefused(SmsError::message($exception->failure), $exception->failure);
        }
        $this->log->sent($id, $result->messageId, $result->parts, $this->clock->now());

        return $result;
    }

    /**
     * Checks that a number can get the site's text messages before anything is sent to it: the kind of number and its
     * country here, then the provider's lookup (which costs money, so it is capped per site and client).
     *
     * @throws SmsRefused
     */
    public function check(PhoneNumber $number, ?string $client = null): PhoneLookupResult
    {
        [$provider, $connection] = $this->ready();
        $country = $this->checkLocally($number, $provider);
        if (!$provider->capabilities()->lookup) {
            return new PhoneLookupResult(true, PhoneLineType::Unknown, $country);
        }
        $this->limit('sms_client', $client);
        $this->limit('sms_lookup', $this->site->id);
        try {
            $result = $provider->lookup($number, $connection);
        } catch (SmsException $exception) {
            throw new SmsRefused(SmsError::message($exception->failure), $exception->failure);
        }
        if (!$result->valid) {
            throw new SmsRefused(SmsError::message(SmsFailure::InvalidNumber), SmsFailure::InvalidNumber);
        }
        if (!$result->isMobile()) {
            throw new SmsRefused(new Message('This isn’t a mobile number, so it can’t get text messages. Use a mobile number.'), SmsFailure::InvalidNumber);
        }

        return $result;
    }

    public function budget(): SmsBudgetStatus
    {
        $configuration = $this->setup->configuration();

        return new SmsBudgetStatus($this->log->partsIn($this->clock->now()->format('Y-m')), $configuration->budget, min($configuration->reservedForCodes, $configuration->budget));
    }

    /**
     * The countries the site's numbers may be in: the tenant's list, or the country of the site's main language.
     *
     * @return list<string>
     */
    public function countries(): array
    {
        $region = $this->numbers->regionOf($this->site->defaultLocale);

        return $this->config->sms->countries ?? ($region === null ? [] : [$region]);
    }

    /**
     * @return array{SmsProvider, SmsConnection}
     *
     * @throws SmsRefused
     */
    private function ready(): array
    {
        $configuration = $this->setup->configuration();
        if ($configuration->problem !== null || $configuration->provider === null || $configuration->connection === null) {
            throw new SmsRefused(new Message('Text messages aren’t set up for this site.'));
        }

        return [$configuration->provider, $configuration->connection];
    }

    /**
     * @return string the number's country
     *
     * @throws SmsRefused
     */
    private function checkLocally(PhoneNumber $number, SmsProvider $provider): string
    {
        if (!$this->numbers->canReceiveTexts($number)) {
            throw new SmsRefused(new Message('This isn’t a mobile number, so it can’t get text messages. Use a mobile number.'), SmsFailure::InvalidNumber);
        }
        $country = $this->numbers->country($number);
        if ($country === null || !in_array($country, $this->countries(), true) || !$provider->capabilities()->delivers($country)) {
            throw new SmsRefused(SmsError::message(SmsFailure::UnsupportedCountry), SmsFailure::UnsupportedCountry);
        }

        return $country;
    }

    /**
     * @throws SmsRefused
     */
    private function limit(string $policy, ?string $key): void
    {
        if ($key === null || $key === '') {
            return;
        }
        $limit = $this->limiters->limiter($policy, $this->site->id . '|' . $key)->consume();
        if (!$limit->isAccepted()) {
            throw new SmsRefused(new Message('Too many text messages were asked for. Try again in {minutes, plural, one {# minute} other {# minutes}}.', [
                'minutes' => max(1, (int) ceil(RateLimiters::secondsUntil($limit->getRetryAfter()) / 60)),
            ]), SmsFailure::RateLimited);
        }
    }
}
