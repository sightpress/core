<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SightPress\Core\Http\Session\Session;
use SightPress\Core\Infrastructure\Sms\TextMessages;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\AccountPhones;

/**
 * "Add your mobile number" after signing in (N-3, ADR-0070): while text messages are on, everyone without a number is
 * asked once per sign-in, on every way in (sign-up, domain registration, an invitation, the first admin). It can be
 * skipped (gap 7: a number is only needed to get texts or, with two-step confirmation, for important changes), unless
 * the tenant sets `sms.require_for_existing`.
 */
final readonly class PhonePrompt
{
    public const string PATH = '/account/phone';

    private const string RETURN = 'auth.phone.return';

    private const string SKIPPED = 'auth.phone.skipped';

    public function __construct(
        private TextMessages $texts,
        private AccountPhones $phones,
    ) {
    }

    /**
     * Where to send the person after signing in: the prompt when it is due (remembering where they were going), else
     * where they were going.
     */
    public function after(Session $session, Account $account, string $target): string
    {
        $id = $account->id();
        if ($id === null || $session->get(self::SKIPPED) === true || !$this->texts->isOn() || ($this->phones->phoneOf($id)?->hasNumber() ?? true)) {
            return $target;
        }
        $session->set(self::RETURN, $target);

        return self::PATH;
    }

    /**
     * Sends the person back to a page once they have confirmed a number (two-step confirmation without a number, N-6).
     */
    public static function returnAfter(Session $session, string $path): void
    {
        $session->set(self::RETURN, $path);
    }

    /**
     * Where the prompt goes on to, while it is shown after signing in; null when it wasn't.
     */
    public static function returnTo(Session $session): ?string
    {
        $return = $session->get(self::RETURN);

        return is_string($return) ? $return : null;
    }

    /**
     * "Skip for now", or the number was added: not again until the next sign-in. Returns where to go on to.
     */
    public static function done(Session $session): ?string
    {
        $return = self::returnTo($session);
        $session->remove(self::RETURN);
        $session->set(self::SKIPPED, true);

        return $return;
    }

    public function mayBeSkipped(): bool
    {
        return !$this->texts->requiredForExisting();
    }
}
