<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\StepUp;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\CodeStatus;
use SightPress\Core\Auth\Contract\StepUpChecked;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Contract\VerificationTarget;
use SightPress\Core\Auth\Http\AuthPages;
use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Auth\PhonePrompt;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Infrastructure\Sms\TextMessages;
use SightPress\Core\Sms\Contract\SmsRefused;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\AccountPhones;
use SightPress\Core\User\Contract\AccountPreferences;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Module\Sms\PhoneNumber;

/**
 * *Confirm it's you* (`/confirm`, N-6): a held critical change waits here. Without a confirmed number, the person adds
 * one first (D37); there is no email fallback (D35). Otherwise "Send me a code" texts a 6-digit code (no link) to
 * their number, and the right code confirms this session for 10 minutes and goes on to {@see ContinueHandler}. When no
 * code can be sent (provider down, budget used up), the page says so and who can help (gap 10).
 *
 * POST `op=send`, or the code.
 */
final readonly class ConfirmHandler implements RequestHandlerInterface
{
    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private Accounts $accounts,
        private AccountPhones $phones,
        private VerificationCodes $codes,
        private TextMessages $texts,
        private Translations $translations,
        private AccountPreferences $preferences,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private Site $site,
    ) {
    }

    public static function form(string $e164): Form
    {
        return Form::create('confirm-code', CodeEntry::field(new Message('We sent it by text message to {phone}. You can type it with or without spaces.', ['phone' => VerificationTarget::mask(VerificationChannel::Sms, $e164)])));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $account = AuthPages::signedIn($request, $this->accounts);
        if ($account === null) {
            return $this->pages->signInFirst(ConfirmPaths::CONFIRM);
        }
        $session = Session::fromRequest($request);
        $held = StepUpSession::held($session);
        if ($held === null) {
            return $this->pages->redirect('/account');
        }
        $id = (int) $account->id();
        $e164 = $this->phones->phoneOf($id)?->e164;
        if ($e164 === null || !$this->texts->isOn()) {
            PhonePrompt::returnAfter($session, ConfirmPaths::CONFIRM);

            return $this->show($request, null, null, ['add_number' => $this->texts->isOn()]);
        }
        $number = new PhoneNumber($e164);
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, $e164, null);
        }
        $body = AuthPages::body($request);
        if (($body['op'] ?? null) === 'send') {
            return $this->send($request, $id, $number);
        }

        $form = $this->validator->submit(self::form($e164), $body);
        if (!$form->isValid()) {
            return $this->show($request, $e164, $form, status: 422);
        }
        $check = $this->codes->check(VerificationPurpose::StepUp, VerificationChannel::Sms, $e164, $form->text(CodeEntry::FIELD), PasswordReset::client($request));
        if ($check->status === CodeStatus::Limited) {
            return $this->show($request, $e164, $form->withErrors([CodeEntry::FIELD => AuthPages::tooManyAttempts('tried a code', $check->retryAfter ?? 1)]), status: 429);
        }
        if (!$check->isValid() || $check->accountId !== $id) {
            $this->events->dispatch(new StepUpChecked($id, $number->masked(), false));

            return $this->show($request, $e164, $form->withErrors([CodeEntry::FIELD => 'That code is not right, or it no longer works. Check it and try again, or send a new code.']), status: 422);
        }
        StepUpSession::confirm($session, $this->clock->now()->getTimestamp());
        $this->events->dispatch(new StepUpChecked($id, $number->masked(), true));

        return $this->pages->redirect(ConfirmPaths::CONTINUE);
    }

    private function send(ServerRequestInterface $request, int $id, PhoneNumber $number): ResponseInterface
    {
        try {
            $code = $this->codes->request(VerificationPurpose::StepUp, VerificationChannel::Sms, $number->e164, $id, PasswordReset::client($request));
        } catch (TooManyCodes $limited) {
            return $this->show($request, $number->e164, self::form($number->e164)->withErrors([CodeEntry::FIELD => $limited->tooSoon
                ? new Message('Wait {retry_after}, then send a new code. The code we sent before still works.', ['retry_after' => CodeEntry::wait($limited->retryAfter)])
                : AuthPages::tooManyAttempts('asked for a code', $limited->retryAfter)]), sent: true, status: 429);
        }
        $viewer = Viewer::recipient($this->preferences->preferencesOf($id), $this->site);
        try {
            $this->texts->sendCode($number, $this->translations->translate(new Message('{code} is your code to confirm a change on {site}. It works for {duration}. Never share it.', [
                'code' => $code->readable(),
                'site' => $this->site->name,
                'duration' => CodeEntry::duration(VerificationPurpose::StepUp->lifetime(VerificationChannel::Sms)),
            ]), [], $viewer->locale), $id, PasswordReset::client($request));
        } catch (SmsRefused $refused) {
            return $this->show($request, $number->e164, null, ['unavailable' => $refused->text()], status: 503);
        }

        return $this->show($request, $number->e164, null, sent: true);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function show(ServerRequestInterface $request, ?string $e164, ?Form $form, array $extra = [], bool $sent = false, int $status = 200): ResponseInterface
    {
        return $this->pages->page($request, 'confirm.html.twig', $extra + [
            'title' => new Message('Confirm it’s you'),
            'phone' => $e164 === null ? null : VerificationTarget::mask(VerificationChannel::Sms, $e164),
            'form' => $form ?? ($e164 === null ? null : self::form($e164)),
            'sent' => $sent || $form !== null,
            'action' => ConfirmPaths::CONFIRM,
            'valid_for' => CodeEntry::duration(VerificationPurpose::StepUp->lifetime(VerificationChannel::Sms)),
            'add_number' => false,
            'unavailable' => null,
        ], $status);
    }
}
