<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Infrastructure\Sms\PhoneNumbers;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Sms\Contract\SmsRefused;
use SightPress\Core\Sms\Contract\TextMessageTested;
use SightPress\Core\Sms\SmsLog;
use SightPress\Core\Sms\SmsModule;
use SightPress\Core\Sms\SmsSender;
use SightPress\Core\Sms\SmsSetup;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Sms\SmsMessage;
use SightPress\Module\Sms\SmsPurpose;
use Slim\Exception\HttpForbiddenException;

/**
 * *Text messages* (`/admin/sms`, N-4), for people with `sms.manage`: whether the site can send and with which
 * provider, the budget used this month (a warning from 80 %), the latest messages (when, why, the number masked, what
 * happened) and "Send a test message". Setting up the provider stays with the operator (`site:sms:set`).
 *
 * POST sends the test message (audited, number masked) and comes back.
 */
final readonly class TextMessagesHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/sms';

    /** How many messages the log shows. */
    public const int LOG_SIZE = 50;

    public function __construct(
        private AdminScreens $screens,
        private SmsSetup $setup,
        private SmsSender $sender,
        private SmsLog $log,
        private PhoneNumbers $numbers,
        private EventDispatcherInterface $events,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(SmsModule::MANAGE)) {
            throw new HttpForbiddenException($request);
        }
        $form = self::form();
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $input = is_array($body) && is_string($body['to'] ?? null) ? trim($body['to']) : '';
            $to = $input === '' ? null : $this->numbers->parse($input, $this->numbers->regionOf($this->site->defaultLocale));
            if ($to === null) {
                $form = $form->submitted(['to' => $input], ['to' => new Message('Enter a mobile number, like 07700 900123, or with the country code, like +44 7700 900123')]);
            } else {
                $provider = $this->setup->configuration()->provider?->id() ?? '';
                try {
                    $this->sender->send(new SmsMessage($to, sprintf('Test message from %s: text messages work.', $this->site->name), SmsPurpose::Notification), $actor->userId);
                    $this->events->dispatch(new TextMessageTested($actor->userId, $to->masked(), $provider, true));

                    return $this->screens->redirect($request, self::PATH, new Message('The test message is on its way to {phone}.', ['phone' => $to->masked()]));
                } catch (SmsRefused $refused) {
                    $this->events->dispatch(new TextMessageTested($actor->userId, $to->masked(), $provider, false, $refused->failure->value ?? 'refused'));
                    $form = $form->submitted(['to' => $input], ['to' => $refused->text()]);
                }
            }
        }
        $configuration = $this->setup->configuration();
        $utc = new DateTimeZone('UTC');

        return $this->screens->render($request, '@sms/text-messages.html.twig', [
            'action' => self::PATH,
            'ready' => $configuration->isReady(),
            'problem' => $configuration->problem,
            'provider' => $configuration->provider?->label(),
            'from_organisation' => $configuration->source === 'tenant',
            'budget' => $this->sender->budget(),
            'log' => array_map(static fn (array $row): array => $row + ['at' => new DateTimeImmutable($row['created_at'], $utc)], $this->log->recent(self::LOG_SIZE)),
            'form' => $form,
        ], $form->isValid() ? 200 : 422);
    }

    public static function form(): Form
    {
        return Form::create(
            'sms-test',
            new Field('to', 'Mobile number', FieldType::Tel, hint: 'For example 07700 900123, or with the country code. The test message counts towards the budget.', autocomplete: 'tel', inputmode: 'tel'),
        );
    }
}
