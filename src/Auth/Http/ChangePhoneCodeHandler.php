<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\Account\PhoneChange;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Contract\VerificationTarget;
use SightPress\Core\Auth\EvenTiming;
use SightPress\Core\Auth\PhonePrompt;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Infrastructure\Sms\TextMessages;
use SightPress\Core\Sms\Contract\SmsRefused;
use SightPress\Core\Template\Message;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Module\Event\AccountChanged;
use SightPress\Module\Sms\PhoneNumber;
use Slim\Exception\HttpNotFoundException;

/**
 * "Confirm your mobile number" (/account/phone/code, N-3): the 6-digit code sent by text, like the other code pages,
 * with "Send a new code". Only then is the number the account's.
 */
final readonly class ChangePhoneCodeHandler implements RequestHandlerInterface
{
    public const string PATH = '/account/phone/code';

    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private Accounts $accounts,
        private TextMessages $texts,
        private PhoneChange $change,
        private EvenTiming $timing,
        private EventDispatcherInterface $events,
    ) {
    }

    public static function form(string $e164): Form
    {
        return Form::create('phone-code', CodeEntry::field(new Message('We sent it by text message to {phone}. You can type it with or without spaces.', ['phone' => VerificationTarget::mask(VerificationChannel::Sms, $e164)])));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $account = AuthPages::signedIn($request, $this->accounts);
        if ($account === null) {
            return $this->pages->signInFirst(ChangePhoneHandler::PATH);
        }
        if (!$this->texts->isOn()) {
            throw new HttpNotFoundException($request);
        }
        $session = Session::fromRequest($request);
        $e164 = CodeSession::pending($session, VerificationPurpose::PhoneVerify);
        if ($e164 === null) {
            return $this->pages->redirect(ChangePhoneHandler::PATH);
        }
        $number = new PhoneNumber($e164);
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, self::form($e164));
        }

        $body = AuthPages::body($request);
        if (($body['resend'] ?? null) !== null) {
            $failed = $this->timing->run(function () use ($request, $account, $number): TooManyCodes|SmsRefused|null {
                try {
                    $this->change->request($request, $account, $number);

                    return null;
                } catch (TooManyCodes|SmsRefused $exception) {
                    return $exception;
                }
            });
            if ($failed !== null) {
                return $this->show($request, self::form($e164)->withErrors([CodeEntry::FIELD => match (true) {
                    $failed instanceof SmsRefused => $failed->text(),
                    $failed->tooSoon => new Message('Wait {retry_after}, then send a new code. The code we sent before still works.', ['retry_after' => CodeEntry::wait($failed->retryAfter)]),
                    default => AuthPages::tooManyAttempts('asked for a code', $failed->retryAfter),
                }]), $failed instanceof TooManyCodes ? 429 : 422);
            }
            Flash::add($session, new Message('We have sent a new code to {phone}. The code we sent before no longer works.', ['phone' => VerificationTarget::mask(VerificationChannel::Sms, $e164)]));

            return $this->pages->redirect(self::PATH);
        }

        $form = $this->validator->submit(self::form($e164), $body);
        if (!$form->isValid()) {
            return $this->show($request, $form, 422);
        }
        $lookup = $session->get(ChangePhoneHandler::LOOKUP);
        try {
            $changed = $this->change->confirm($request, $account, $number, is_string($lookup) ? $lookup : null, $form->text(CodeEntry::FIELD));
        } catch (TooManyCodes $exception) {
            return $this->show($request, $form->withErrors([CodeEntry::FIELD => AuthPages::tooManyAttempts('tried a code', $exception->retryAfter)]), 429);
        }
        if (!$changed) {
            return $this->show($request, $form->withErrors([CodeEntry::FIELD => ChangeEmailCodeHandler::WRONG]), 422);
        }

        CodeSession::clear($session);
        $session->remove(ChangePhoneHandler::LOOKUP);
        $id = (int) $account->id();
        $this->events->dispatch(new AccountChanged($id, $id, ['phone']));
        Flash::add($session, new Message('Your mobile number is now {phone}.', ['phone' => $this->texts->national($number)]));
        $return = PhonePrompt::done($session);

        return $this->pages->redirect($return ?? ChangePhoneHandler::PATH);
    }

    private function show(ServerRequestInterface $request, Form $form, int $status = 200): ResponseInterface
    {
        return $this->pages->page($request, 'account-phone-code.html.twig', [
            'title' => new Message('Confirm your mobile number'),
            'form' => $form,
            'action' => self::PATH,
            'valid_for' => CodeEntry::duration(VerificationPurpose::PhoneVerify->lifetime(VerificationChannel::Sms)),
        ], $status);
    }
}
