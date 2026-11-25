<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Sms;

use DateTimeImmutable;
use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Sms\TextMessages;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\Workflow\ReviewRequestMailer;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\Sms\SmsLog;
use SightPress\Core\Sms\SmsNumberHash;
use SightPress\Core\Sms\SmsOptOuts;
use SightPress\Core\Sms\SmsOutbox;
use SightPress\Core\Sms\SmsOutboxJob;
use SightPress\Core\Sms\SmsReports;
use SightPress\Core\Sms\SmsSender;
use SightPress\Core\Sms\SmsSettings;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use SightPress\Module\Event\PageTransitioned;
use SightPress\Module\Notification\NotificationChannel;
use SightPress\Module\Sms\PhoneNumber;
use SightPress\Module\Sms\SmsMessage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Text notifications (N-4): who gets them, the outbox that `schedule:run` empties at most once per message, and
 * texts short enough in every language.
 */
#[CoversClass(TextMessages::class)]
#[CoversClass(SmsOutbox::class)]
#[CoversClass(SmsOutboxJob::class)]
#[CoversClass(SmsLog::class)]
#[CoversClass(SmsSender::class)]
#[CoversClass(ReviewRequestMailer::class)]
#[UsesNamespace('SightPress\Core')]
final class TextNotificationsTest extends TestCase
{
    private const string ACCOUNT = 'AC' . '0123456789abcdef0123456789abcdef';

    private KernelSite $site;

    /** @var list<array{to: string, text: string}> */
    private array $texts = [];

    /** @var list<MockResponse> */
    private array $answers = [];

    protected function setUp(): void
    {
        $this->site = new KernelSite('text_notifications', tenant: ['sms' => ['provider' => 'twilio', 'account' => self::ACCOUNT, 'sender' => 'SightPress']], variables: ['SMS_API_KEY' => 'canary5f2b9c1d7e4a']);
        $tenant = $this->site->kernel->container();
        self::assertInstanceOf(Container::class, $tenant);
        $tenant->set(HttpClientInterface::class, new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            parse_str(is_string($options['body'] ?? null) ? $options['body'] : '', $form);
            $this->texts[] = ['to' => is_string($form['To'] ?? null) ? $form['To'] : '', 'text' => is_string($form['Body'] ?? null) ? $form['Body'] : ''];

            return array_shift($this->answers) ?? new MockResponse('{"sid":"SM' . count($this->texts) . '"}', ['http_code' => 201]);
        }));
        Services::get($tenant, SiteModules::class)->turnOn($this->site->site(), 'sms', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAnOptedInReviewerGetsATextAndTheOthersOnlyAnEmail(): void
    {
        $contributor = $this->person('cleo@example.org', 'contributor');
        $bea = $this->person('bea@example.org', 'editor', '+447400123456', true);
        $eve = $this->person('eve@example.org', 'editor', '+447400123457', false);
        $stopped = $this->person('sam@example.org', 'editor', '+447400123458', true);
        $phones = Services::get($this->site->container(), UserRepository::class);
        $phones->textReply('+447400123458', true);
        $texts = Services::get($this->site->container(), TextMessages::class);
        self::assertSame([NotificationChannel::Email, NotificationChannel::Sms], $texts->channelsFor($bea, 'review_request'));
        self::assertSame([NotificationChannel::Email], $texts->channelsFor($eve, 'review_request'));
        self::assertSame([NotificationChannel::Email], $texts->channelsFor($stopped, 'review_request'));
        self::assertSame([NotificationChannel::Email], $texts->channelsFor($bea, 'something_else'));

        $page = Services::get($this->site->container(), PageEditor::class)->create('en-GB', new PageContent('Secret opening plans'), authorId: $contributor);
        Services::get($this->site->container(), ReviewRequestMailer::class)(new PageTransitioned((int) $page->id(), PageTransition::Submit->value, 'draft', 'in_review', $contributor));
        self::assertSame([], $this->texts, 'nothing is sent until schedule:run');

        $event = new ScheduleDue($this->site->site(), $this->site->container(), new DateTimeImmutable());
        new SmsOutboxJob()($event);
        self::assertSame([['to' => '+447400123456', 'text' => 'Demo: a page is waiting for your review. Sign in to the admin panel to see it.']], $this->texts);
        self::assertStringNotContainsString('Secret', implode(' ', array_column($this->texts, 'text')), 'no page content');
        self::assertSame(['Text messages: sent 1, failed 0, 0 left by an interrupted run marked failed.'], $event->lines());

        $again = new ScheduleDue($this->site->site(), $this->site->container(), new DateTimeImmutable());
        new SmsOutboxJob()($again);
        self::assertCount(1, $this->texts, 'sent once');
        self::assertSame([], $again->lines());
        $log = Services::get($this->site->container(), SmsLog::class)->recent(5);
        self::assertSame(['sent', 'notification', $bea], [$log[0]['status'], $log[0]['purpose'], $log[0]['user_id']]);
    }

    public function testACrashMidRunNeverSendsTwice(): void
    {
        $log = Services::get($this->site->container(), SmsLog::class);
        $outbox = Services::get($this->site->container(), SmsOutbox::class);
        $sender = Services::get($this->site->container(), SmsSender::class);
        $first = $sender->queue(new SmsMessage(new PhoneNumber('+447400123456'), 'One'), null, 'review_request');
        $second = $sender->queue(new SmsMessage(new PhoneNumber('+447400123457'), 'Two'), null, 'review_request');
        $now = new DateTimeImmutable();

        // A run took the first message and crashed before sending it.
        self::assertTrue($log->claim($first, $now));
        self::assertFalse($log->claim($first, $now), 'only one run takes it');
        self::assertSame(['sent' => 1, 'failed' => 0, 'interrupted' => 0], $outbox->run($now));
        self::assertSame(['+447400123457'], array_column($this->texts, 'to'));
        self::assertSame(['sent' => 0, 'failed' => 0, 'interrupted' => 1], $outbox->run($now->modify('+2 hours')));
        self::assertSame(['failed', 'interrupted'], [$log->recent(2)[1]['status'], $log->recent(2)[1]['failure']]);
        self::assertSame([], $log->queued(10));
        self::assertCount(1, $this->texts, 'the interrupted message is never sent');
        self::assertSame($second, $log->recent(1)[0]['id']);
    }

    public function testEveryCheckRunsAgainWhenTheMessageGoes(): void
    {
        $log = Services::get($this->site->container(), SmsLog::class);
        $sender = Services::get($this->site->container(), SmsSender::class);
        $outbox = Services::get($this->site->container(), SmsOutbox::class);
        $number = new PhoneNumber('+447400123456');
        $sender->queue(new SmsMessage($number, 'After STOP'), null, 'review_request');
        Services::get($this->site->container(), SmsOptOuts::class)->stop(Services::get($this->site->container(), SmsNumberHash::class)->of($number), new DateTimeImmutable());
        self::assertSame(['sent' => 0, 'failed' => 1, 'interrupted' => 0], $outbox->run(new DateTimeImmutable()));
        self::assertSame(['failed', 'opted_out'], [$log->recent(1)[0]['status'], $log->recent(1)[0]['failure']]);
        try {
            $sender->queue(new SmsMessage($number, 'Refused at once'), null, 'review_request');
            self::fail('Queued for a number that replied STOP.');
        } catch (\SightPress\Core\Sms\Contract\SmsRefused) {
        }

        $sender->queue(new SmsMessage(new PhoneNumber('+447400123457'), 'Provider down'), null, 'review_request');
        $this->answers = [new MockResponse('', ['http_code' => 503])];
        self::assertSame(['sent' => 0, 'failed' => 1, 'interrupted' => 0], $outbox->run(new DateTimeImmutable()));
        self::assertSame('unavailable', $log->recent(1)[0]['failure']);

        Services::get($this->site->container(), \Doctrine\DBAL\Connection::class)->insert(SmsLog::TABLE, ['created_at' => new DateTimeImmutable(), 'month' => gmdate('Y-m'), 'purpose' => 'notification', 'to_masked' => '+44', 'to_hash' => 'x', 'provider' => 'twilio', 'parts' => 1, 'status' => SmsLog::QUEUED, 'body' => 'Broken', 'to_e164' => '+1'], ['created_at' => \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE]);
        self::assertSame(['sent' => 0, 'failed' => 1, 'interrupted' => 0], $outbox->run(new DateTimeImmutable()), 'a broken row fails');
        self::assertSame('invalid', $log->recent(1)[0]['failure']);

        Services::get($this->site->container(), SmsSettings::class)->connect('acme', null, null, null, null, null);
        $sender = Services::get($this->site->container(), SmsSender::class);
        Services::get($this->site->container(), \Doctrine\DBAL\Connection::class)->insert(SmsLog::TABLE, ['created_at' => new DateTimeImmutable(), 'month' => gmdate('Y-m'), 'purpose' => 'notification', 'to_masked' => '+44', 'to_hash' => 'y', 'provider' => 'twilio', 'parts' => 1, 'status' => SmsLog::QUEUED, 'body' => 'No provider', 'to_e164' => '+447400123459'], ['created_at' => \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE]);
        self::assertSame(['sent' => 0, 'failed' => 1, 'interrupted' => 0], $outbox->run(new DateTimeImmutable()), 'the provider went away');
        self::assertSame('refused', $log->recent(1)[0]['failure']);
        self::assertFalse(Services::get($this->site->container(), TextMessages::class)->notify(1, 'review_request', new Message('Hello')));
    }

    public function testANotificationThatCannotBeQueuedIsSkipped(): void
    {
        $bea = $this->person('bea@example.org', 'editor', '+447400123456', true);
        Services::get($this->site->container(), SmsOptOuts::class)->stop(Services::get($this->site->container(), SmsNumberHash::class)->of(new PhoneNumber('+447400123456')), new DateTimeImmutable());
        $texts = Services::get($this->site->container(), TextMessages::class);
        self::assertFalse($texts->notify($bea, 'review_request', new Message('Hello')), 'the number replied STOP before the account knew');
        self::assertFalse($texts->notify($bea, '', new Message('Hello')));
        self::assertSame([], Services::get($this->site->container(), SmsLog::class)->queued(5));
    }

    public function testSitesWithTextMessagesOffHaveNoOutbox(): void
    {
        $tenant = $this->site->kernel->container();
        Services::get($tenant, SiteModules::class)->turnOff($this->site->site(), 'sms', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
        $event = new ScheduleDue($this->site->site(), $this->site->container(), new DateTimeImmutable());
        new SmsOutboxJob()($event);
        self::assertSame([], $event->lines());
    }

    public function testOldMessagesAndReportsAreForgotten(): void
    {
        $log = Services::get($this->site->container(), SmsLog::class);
        $sender = Services::get($this->site->container(), SmsSender::class);
        $sender->send(new SmsMessage(new PhoneNumber('+447400123456'), 'Old'));
        $sender->queue(new SmsMessage(new PhoneNumber('+447400123457'), 'Waiting'), null, 'review_request');
        Services::get($this->site->container(), SmsReports::class)->first('old', new DateTimeImmutable('-40 days'));
        self::assertSame(1, $log->forgetBefore(new DateTimeImmutable('+1 day')), 'a waiting message stays');
        new SmsOutboxJob()(new ScheduleDue($this->site->site(), $this->site->container(), new DateTimeImmutable('+100 days')));
        self::assertSame([], $log->recent(10), 'sent, then forgotten after 90 days');
        self::assertTrue(Services::get($this->site->container(), SmsReports::class)->first('old', new DateTimeImmutable()));
    }

    public function testNotificationTextsFitInEveryLanguage(): void
    {
        $translations = Services::get($this->site->container(), Translations::class);
        $site = 'Riverside Community Library';
        foreach (['en-GB', 'pt-PT', 'es'] as $locale) {
            foreach ([
                new Message('{site}: a page is waiting for your review. Sign in to the admin panel to see it.', ['site' => $site]),
                new Message('{code} is your code for {site}. It works for {duration}. Never share it.', ['code' => '123 456', 'site' => $site, 'duration' => new Message('{count, plural, one {# minute} other {# minutes}}', ['count' => 10])]),
            ] as $text) {
                $message = new SmsMessage(new PhoneNumber('+447400123456'), $translations->translate($text, [], $locale));
                self::assertLessThanOrEqual(2, $message->parts(), $locale . ': ' . $message->text);
                self::assertNotSame($text->text, $locale === 'en-GB' ? '' : $message->text, $locale);
            }
        }
    }

    /**
     * @return int the account id
     */
    private function person(string $email, string $role, ?string $phone = null, bool $texts = false): int
    {
        $users = Services::get($this->site->container(), UserRepository::class);
        $user = new User($email, ucfirst(explode('@', $email)[0]), 'Test', 'UTC', new DateTimeImmutable());
        $user->setPasswordHash('hash', new DateTimeImmutable());
        $user->activate(new DateTimeImmutable());
        $users->save($user);
        $id = (int) $user->id();
        $roles = Services::get($this->site->container(), RoleRepository::class);
        $roles->assign($id, $roles->findRole($role) ?? throw new \LogicException($role));
        if ($phone !== null) {
            $users->confirmPhone($id, $phone, 'mobile GB');
            $users->chooseTexts($id, $texts, ['review_request']);
        }

        return $id;
    }
}
