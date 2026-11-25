<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Locale;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\Account\PhoneChange;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\EvenTiming;
use SightPress\Core\Auth\PhonePrompt;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Infrastructure\Sms\TextMessages;
use SightPress\Core\Sms\Contract\SmsRefused;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\AccountPhones;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Module\Event\AccountChanged;
use SightPress\Module\Sms\PhoneNumber;
use Slim\Exception\HttpNotFoundException;

/**
 * "Your mobile number" (/account/phone, N-3), while text messages are on: the number (a country select and a
 * `type=tel` field; a code goes to it by text and it changes only once the code is typed on the next page), whether
 * to get text messages and which (unticked until the person ticks them), and "Remove my number". After signing in
 * without a number it is also the "Add your mobile number" step, with "Skip for now" ({@see PhonePrompt}).
 *
 * POST `op=number|texts|remove|skip`.
 */
final readonly class ChangePhoneHandler implements RequestHandlerInterface
{
    public const string PATH = PhonePrompt::PATH;

    /** Session key of the provider's lookup of the number waiting for its code. */
    public const string LOOKUP = 'auth.phone.lookup';

    public function __construct(
        private AuthPages $pages,
        private Accounts $accounts,
        private AccountPhones $phones,
        private TextMessages $texts,
        private PhoneChange $change,
        private PhonePrompt $prompt,
        private EvenTiming $timing,
        private EventDispatcherInterface $events,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $account = AuthPages::signedIn($request, $this->accounts);
        if ($account === null) {
            return $this->pages->signInFirst(self::PATH);
        }
        if (!$this->texts->isOn()) {
            throw new HttpNotFoundException($request);
        }
        $session = Session::fromRequest($request);
        $id = (int) $account->id();
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, $account, $this->numberForm(), $this->textsForm($id));
        }

        $body = AuthPages::body($request);
        $op = $body['op'] ?? null;
        if ($op === 'skip' && $this->prompt->mayBeSkipped()) {
            return $this->pages->redirect(PhonePrompt::done($session) ?? '/account');
        }
        if ($op === 'remove') {
            if ($this->phones->removePhone($id)) {
                $this->events->dispatch(new AccountChanged($id, $id, ['phone']));
                Flash::add($session, 'Your mobile number is removed. You get no more text messages from this site.');
            }

            return $this->pages->redirect('/account');
        }
        if ($op === 'texts') {
            return $this->saveTexts($request, $account, $body);
        }

        return $this->sendCode($request, $account, $body);
    }

    /**
     * @param array<mixed> $body
     */
    private function sendCode(ServerRequestInterface $request, Account $account, array $body): ResponseInterface
    {
        $id = (int) $account->id();
        $values = [
            'country' => is_string($body['country'] ?? null) ? strtoupper(trim($body['country'])) : '',
            'phone' => is_string($body['phone'] ?? null) ? trim($body['phone']) : '',
        ];
        $form = $this->numberForm();
        $countries = array_map(strval(...), array_keys($form->field('country')->choices));
        $country = in_array($values['country'], $countries, true) ? $values['country'] : $this->texts->defaultCountry();
        $number = $values['phone'] === '' ? null : $this->texts->parse($values['phone'], $country);
        $problem = match (true) {
            $values['phone'] === '' => new Message('Enter your mobile number'),
            $number === null => new Message('Enter a mobile number, like 07700 900123, or with the country code, like +44 7700 900123'),
            default => $this->change->problem($account, $number),
        };
        $lookup = null;
        if ($problem === null && $number !== null) {
            try {
                $lookup = $this->change->check($request, $number);
            } catch (SmsRefused $refused) {
                $problem = $refused->text();
            }
        }
        if ($problem !== null) {
            return $this->show($request, $account, $form->submitted($values, ['phone' => $problem]), $this->textsForm($id), 422);
        }

        $failed = $this->timing->run(function () use ($request, $account, $number): TooManyCodes|SmsRefused|null {
            try {
                $this->change->request($request, $account, $number);

                return null;
            } catch (TooManyCodes|SmsRefused $exception) {
                return $exception;
            }
        });
        if ($failed !== null) {
            $error = match (true) {
                $failed instanceof SmsRefused => $failed->text(),
                $failed->tooSoon => new Message('Wait {retry_after}, then ask again. The code we sent before still works.', ['retry_after' => CodeEntry::wait($failed->retryAfter)]),
                default => AuthPages::tooManyAttempts('asked for a code', $failed->retryAfter),
            };

            return $this->show($request, $account, $form->submitted($values, ['phone' => $error]), $this->textsForm($id), $failed instanceof TooManyCodes ? 429 : 422);
        }
        $session = Session::fromRequest($request);
        CodeSession::startPending($session, VerificationPurpose::PhoneVerify, $number->e164);
        $session->set(self::LOOKUP, $lookup);

        return $this->pages->redirect(ChangePhoneCodeHandler::PATH);
    }

    /**
     * @param array<mixed> $body
     */
    private function saveTexts(ServerRequestInterface $request, Account $account, array $body): ResponseInterface
    {
        $id = (int) $account->id();
        $phone = $this->phones->phoneOf($id);
        $wanted = ($body['texts'] ?? null) === '1';
        $chosen = is_array($body['notifications'] ?? null) ? array_values(array_filter($body['notifications'], is_string(...))) : [];
        $notifications = array_values(array_intersect(TextMessages::NOTIFICATIONS, $chosen));
        if ($wanted && !($phone?->hasNumber() ?? false)) {
            $form = $this->textsForm($id)->submitted(['texts' => '1', 'notifications' => $notifications], ['texts' => new Message('Add your mobile number first, then choose the text messages you want.')]);

            return $this->show($request, $account, $this->numberForm(), $form, 422);
        }
        $this->phones->chooseTexts($id, $wanted, $notifications);
        $this->events->dispatch(new AccountChanged($id, $id, ['text_messages']));
        Flash::add(Session::fromRequest($request), $wanted ? 'You will get the text messages you chose.' : 'You will get no text messages from this site.');

        return $this->pages->redirect(self::PATH);
    }

    private function numberForm(): Form
    {
        $countries = [];
        $locale = str_replace('-', '_', $this->site->defaultLocale);
        foreach ($this->texts->countries() as $country) {
            $countries[$country] = sprintf('%s (+%d)', Locale::getDisplayRegion('-' . $country, $locale), $this->texts->callingCode($country));
        }

        return Form::create(
            'phone',
            new Field('country', 'Country', FieldType::Select, hint: 'Where the number is from. A number that starts with + and the country code works for any of them.', choices: $countries),
            new Field('phone', 'Mobile number', FieldType::Tel, hint: 'For example 07700 900123. We send a 6-digit code to it by text message to check it is yours.', autocomplete: 'tel-national', inputmode: 'tel'),
        )->withValues(['country' => $this->texts->defaultCountry() ?? '']);
    }

    private function textsForm(int $id): Form
    {
        $phone = $this->phones->phoneOf($id);
        $choices = TextMessages::notifications();

        return Form::create(
            'texts',
            new Field('texts', 'Send me text messages', FieldType::Checkbox, hint: 'Only the ones you choose below. Each one costs this site money, so we keep them short. You can stop them here at any time, or by replying STOP.', required: false),
            new Field('notifications', 'Which text messages', FieldType::Checkboxes, required: false, choices: $choices),
        )->withValues([
            'texts' => $phone !== null && $phone->wantsTexts ? '1' : '',
            'notifications' => $phone === null ? [] : $phone->notifications,
        ]);
    }

    private function show(ServerRequestInterface $request, Account $account, Form $number, Form $texts, int $status = 200): ResponseInterface
    {
        $phone = $this->phones->phoneOf((int) $account->id());
        $current = $phone?->e164 === null ? null : $this->texts->national(new PhoneNumber($phone->e164));
        $prompt = PhonePrompt::returnTo(Session::fromRequest($request)) !== null && $current === null;
        $failed = !$number->isValid() ? $number : (!$texts->isValid() ? $texts : null);

        return $this->pages->page($request, 'account-phone.html.twig', [
            'title' => match (true) {
                $prompt => new Message('Add your mobile number'),
                $current === null => new Message('Add a mobile number'),
                default => new Message('Your mobile number'),
            },
            'prompt' => $prompt,
            'may_skip' => $prompt && $this->prompt->mayBeSkipped(),
            'current' => $current,
            'number' => $number,
            'texts' => $texts,
            'failed' => $failed,
            'action' => self::PATH,
        ], $status);
    }

}
