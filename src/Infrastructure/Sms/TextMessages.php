<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Sms;

use Psr\Container\ContainerInterface;
use SensitiveParameter;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Sms\Contract\SmsRefused;
use SightPress\Core\Sms\SmsModule;
use SightPress\Core\Sms\SmsSender;
use SightPress\Core\Sms\SmsSetup;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\Viewer;
use SightPress\Core\User\Contract\AccountPhones;
use SightPress\Core\User\Contract\AccountPreferences;
use SightPress\Module\Notification\NotificationChannel;
use SightPress\Module\Sms\PhoneLookupResult;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsPurpose;

/**
 * Text messages for the rest of core (N-3 to N-6, ADR-0070): whether they are on for the site, reading the numbers
 * people type, checking a number and sending a security code, all through the SMS module's guards. While text messages
 * are off (or not set up), {@see isOn()} is false and nothing is sent.
 */
final readonly class TextMessages
{
    /** Notifications people may choose to get by text (N-4 sends them). */
    public const array NOTIFICATIONS = ['review_request'];

    public function __construct(
        private ContainerInterface $container,
        private ModuleRegistry $registry,
        private PhoneNumbers $numbers,
        private Site $site,
        private TenantConfig $config,
    ) {
    }

    /**
     * The notifications people may choose to get by text, with what each one is.
     *
     * @return array<string, Message>
     */
    public static function notifications(): array
    {
        return ['review_request' => new Message('When a page is sent to you for review')];
    }

    /**
     * Text messages are on for the site and its provider is set up.
     */
    public function isOn(): bool
    {
        return $this->registry->has(SmsModule::ID) && Services::get($this->container, SmsSetup::class)->configuration()->isReady();
    }

    /**
     * Whether people who already have an account must add a number at their next sign-in (`sms.require_for_existing`).
     */
    public function requiredForExisting(): bool
    {
        return $this->config->sms->requireForExisting;
    }

    /**
     * The countries numbers may be in, the site's own first.
     *
     * @return list<string>
     */
    public function countries(): array
    {
        return $this->registry->has(SmsModule::ID) ? Services::get($this->container, SmsSender::class)->countries() : [];
    }

    /**
     * The country a number without `+` is read in: the site's, else the first allowed one.
     */
    public function defaultCountry(): ?string
    {
        $countries = $this->countries();
        $own = $this->numbers->regionOf($this->site->defaultLocale);

        return $own !== null && in_array($own, $countries, true) ? $own : ($countries[0] ?? $own);
    }

    /**
     * What someone typed, as a number of the chosen country (or with its own `+` code), or null when it can't be one.
     */
    public function parse(#[SensitiveParameter] string $input, ?string $country = null): ?PhoneNumber
    {
        return $this->numbers->parse($input, $country ?? $this->defaultCountry());
    }

    public function callingCode(string $country): int
    {
        return $this->numbers->callingCodes()[strtoupper($country)] ?? 0;
    }

    /**
     * How the number is written in its country, for its owner (`07700 900123`).
     */
    public function national(PhoneNumber $number): string
    {
        return $this->numbers->national($number);
    }

    /**
     * Checks that the number is a mobile number in an allowed country, with the provider's lookup.
     *
     * @throws SmsRefused
     */
    public function check(PhoneNumber $number, ?string $client): PhoneLookupResult
    {
        return $this->sender()->check($number, $client);
    }

    /**
     * Sends a security code right away (it can't wait for the next scheduled run). The text holds the code and the
     * site's name, never a link.
     *
     * @throws SmsRefused
     */
    public function sendCode(PhoneNumber $to, string $text, ?int $accountId, ?string $client): void
    {
        $this->sender()->send(new SmsMessage($to, $text, SmsPurpose::SecurityCode), $accountId, $client);
    }

    /**
     * How a notification reaches a person: always by email, and by text too while text messages are on and they chose
     * this one, have a confirmed number and haven't replied STOP (N-4).
     *
     * @return list<NotificationChannel>
     */
    public function channelsFor(int $accountId, string $notification): array
    {
        return $this->textNumber($accountId, $notification) === null ? [NotificationChannel::Email] : [NotificationChannel::Email, NotificationChannel::Sms];
    }

    /**
     * Puts a text notification in the outbox, in the person's language, when {@see channelsFor()} includes text. The
     * text must hold no page content and no secret: say what happened and that the admin panel has the rest.
     *
     * @return bool whether it was queued
     */
    public function notify(int $accountId, string $notification, Message $text): bool
    {
        $number = $this->textNumber($accountId, $notification);
        if ($number === null || $notification === '') {
            return false;
        }
        $viewer = Viewer::recipient(Services::get($this->container, AccountPreferences::class)->preferencesOf($accountId), $this->site);
        try {
            $this->sender()->queue(new SmsMessage(new PhoneNumber($number), Services::get($this->container, Translations::class)->translate($text, [], $viewer->locale)), $accountId, $notification);
        } catch (SmsRefused) {
            return false;
        }

        return true;
    }

    /**
     * The person's confirmed number when this notification goes to them by text, else null.
     */
    private function textNumber(int $accountId, string $notification): ?string
    {
        $phone = $this->isOn() ? Services::get($this->container, AccountPhones::class)->phoneOf($accountId) : null;

        return $phone !== null && $phone->wants($notification) ? $phone->e164 : null;
    }

    /**
     * @throws SmsRefused
     */
    private function sender(): SmsSender
    {
        if (!$this->isOn()) {
            throw new SmsRefused(new Message('Text messages aren’t set up for this site.'));
        }

        return Services::get($this->container, SmsSender::class);
    }
}
