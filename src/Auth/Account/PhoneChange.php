<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Account;

use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\CodeStatus;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Infrastructure\Sms\TextMessages;
use SightPress\Core\Sms\Contract\SmsRefused;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\AccountException;
use SightPress\Core\User\Contract\AccountPhones;
use SightPress\Core\User\Contract\AccountPreferences;
use SightPress\Module\Sms\PhoneNumber;

/**
 * Adding or changing the mobile number of an account (N-3, ADR-0070, D30): the number is checked (a mobile number in
 * an allowed country, with the provider's lookup), then a 6-digit code goes to it by text, and it becomes the
 * account's only once the code is typed. A number another account of the site has gets the same answer and no text
 * (no account enumeration), like an email address that is taken.
 */
final readonly class PhoneChange
{
    public function __construct(
        private AccountPhones $phones,
        private VerificationCodes $codes,
        private TextMessages $texts,
        private Translations $translations,
        private AccountPreferences $preferences,
        private Site $site,
    ) {
    }

    /**
     * What is wrong with the number before anything is sent, in plain words, or null.
     */
    public function problem(Account $account, PhoneNumber $number): ?Message
    {
        return $this->phones->accountWithPhone($number->e164) === $account->id() && $account->id() !== null
            ? new Message('That is already your mobile number.')
            : null;
    }

    /**
     * Checks the number with the provider, and returns what its lookup said (`mobile GB`), for {@see confirm()}.
     *
     * @throws SmsRefused when it can't get the site's texts (a landline, abroad, the lookup failed)
     */
    public function check(ServerRequestInterface $request, PhoneNumber $number): string
    {
        $lookup = $this->texts->check($number, PasswordReset::client($request));

        return trim($lookup->lineType->value . ' ' . ($lookup->country ?? ''));
    }

    /**
     * Makes a code for the number and texts it, unless another account has the number.
     *
     * @throws TooManyCodes
     * @throws SmsRefused
     */
    public function request(ServerRequestInterface $request, Account $account, PhoneNumber $number): void
    {
        $client = PasswordReset::client($request);
        $code = $this->codes->request(VerificationPurpose::PhoneVerify, VerificationChannel::Sms, $number->e164, $account->id(), $client);
        if ($this->phones->accountWithPhone($number->e164) !== null) {
            return;
        }
        $viewer = Viewer::recipient($account->id() === null ? null : $this->preferences->preferencesOf($account->id()), $this->site);
        $this->texts->sendCode($number, $this->translations->translate(new Message('{code} is your code for {site}. It works for {duration}. Never share it.', [
            'code' => $code->readable(),
            'site' => $this->site->name,
            'duration' => CodeEntry::duration(VerificationPurpose::PhoneVerify->lifetime(VerificationChannel::Sms)),
        ]), [], $viewer->locale), $account->id(), $client);
    }

    /**
     * Checks the code and, when it is right, for this account and the number is still free, makes the number the
     * account's (the old one stops working at once). Returns whether it changed.
     *
     * @throws TooManyCodes when this client tried too many codes
     */
    public function confirm(ServerRequestInterface $request, Account $account, PhoneNumber $number, ?string $lookup, string $code): bool
    {
        $check = $this->codes->check(VerificationPurpose::PhoneVerify, VerificationChannel::Sms, $number->e164, $code, PasswordReset::client($request));
        if ($check->status === CodeStatus::Limited) {
            throw new TooManyCodes($check->retryAfter ?? 1);
        }
        $id = $account->id();
        if (!$check->isValid() || $id === null || $check->accountId !== $id) {
            return false;
        }
        try {
            $this->phones->confirmPhone($id, $number->e164, $lookup);
        } catch (AccountException) {
            return false;
        }

        return true;
    }
}
