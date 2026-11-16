<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Contact;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Contact\ContactForm;
use SightPress\Core\Contact\ContactMailer;
use SightPress\Core\Contact\Http\ContactFormHandler;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Tests\Support\ContactFixture;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\Mime\Address;

#[CoversClass(ContactFormHandler::class)]
#[CoversClass(ContactMailer::class)]
#[UsesNamespace('SightPress\Core')]
final class ContactFormHandlerTest extends TestCase
{
    private ContactFixture $fixture;

    private string $id;

    protected function setUp(): void
    {
        $this->fixture = new ContactFixture();
        $this->fixture->page();
        $this->id = ContactForm::id(ContactFixture::FORM);
    }

    public function testEmailsTheMessageToTheFormsRecipient(): void
    {
        $session = new Session();

        $response = $this->fixture->handler()->handle($this->fixture->post($this->body(), $session));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/contact', $response->getHeaderLine('Location'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertCount(1, $this->fixture->auth->sent);
        $email = $this->fixture->auth->sent[0];
        self::assertEquals([new Address('office@example.org')], $email->getTo());
        self::assertEquals([new Address('ada@example.org', 'Ada Lovelace')], $email->getReplyTo());
        self::assertSame('Website message', $email->getSubject());
        self::assertStringContainsString('Ada Lovelace sent a message with the contact form on Contact (https://demo.example/contact).', (string) $email->getTextBody());
        self::assertStringContainsString("Hello,\nI have a question & <b>a tip</b>.", (string) $email->getTextBody(), 'plain text is not escaped');
        self::assertStringContainsString('<p>Hello,<br />' . "\n" . 'I have a question &amp; &lt;b&gt;a tip&lt;/b&gt;.</p>', (string) $email->getHtmlBody());
        self::assertStringContainsString('<a href="https://demo.example/contact"', (string) $email->getHtmlBody());
        self::assertTrue(ContactForm::recall($session, $this->id)['sent']);
    }

    public function testUsesADefaultSubjectAndKeepsThePortInTheLink(): void
    {
        $fixture = new ContactFixture();
        $fixture->page('Talk to us', ['recipient' => 'office@example.org']);
        $request = $fixture->auth->request('POST', 'https://demo.example:8443/contact-form', $this->body('/talk-to-us', ContactForm::id(['recipient' => 'office@example.org'])));

        $fixture->handler()->handle($request);

        self::assertSame('Message from the contact form on Demo', $fixture->auth->sent[0]->getSubject());
        self::assertStringContainsString('(https://demo.example:8443/talk-to-us)', (string) $fixture->auth->sent[0]->getTextBody());
    }

    public function testSendsTheFormBackWithErrors(): void
    {
        $session = new Session();

        $response = $this->fixture->handler()->handle($this->fixture->post(['email' => 'not an address', 'message' => ''] + $this->body(), $session));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame([], $this->fixture->auth->sent);
        $state = ContactForm::recall($session, $this->id);
        self::assertFalse($state['sent']);
        self::assertSame(['email', 'message'], array_keys($state['form']->errors()));
        self::assertSame('Ada Lovelace', $state['form']->value('name'));
    }

    public function testBotsThatFillTheHiddenFieldSeeSuccessButNothingIsSent(): void
    {
        $session = new Session();

        $this->fixture->handler()->handle($this->fixture->post([ContactForm::HONEYPOT => 'https://spam.example'] + $this->body(), $session));

        self::assertSame([], $this->fixture->auth->sent);
        self::assertTrue(ContactForm::recall($session, $this->id)['sent']);
    }

    public function testLimitsMessagesPerVisitor(): void
    {
        $handler = $this->fixture->handler();
        for ($i = 0; $i < 5; ++$i) {
            $handler->handle($this->fixture->post($this->body()));
        }
        $session = new Session();

        $handler->handle($this->fixture->post($this->body(), $session));

        self::assertCount(5, $this->fixture->auth->sent);
        $state = ContactForm::recall($session, $this->id);
        self::assertMatchesRegularExpression('/^You have sent a lot of messages\. Try again in (1 minute|\d+ minutes)\.$/', (string) $state['form']->error('message'));
        self::assertStringEndsWith('I have a question & <b>a tip</b>.', $state['form']->text('message'));
    }

    public function testRefusesFormsThatAreNotOnAPublicPage(): void
    {
        $draft = $this->fixture->pages->editor()->create('en-GB', new PageContent('Draft', null, null, null, [['type' => 'contact_form', 'version' => 1, 'data' => ContactFixture::FORM]]));
        self::assertNotNull($draft->id());

        foreach ([$this->body('/draft'), $this->body('/missing'), $this->body('/contact', 'contact-000000000000'), ['form' => 5, 'return' => ['x']]] as $body) {
            try {
                $this->fixture->handler()->handle($this->fixture->post($body));
                self::fail('Expected a 404.');
            } catch (HttpNotFoundException $exception) {
                self::assertStringStartsWith('This form isn\'t on the page any more.', $exception->getMessage());
            }
        }
        self::assertSame([], $this->fixture->auth->sent);
    }

    public function testReadsNoBodyAsEmpty(): void
    {
        $this->expectException(HttpNotFoundException::class);

        $this->fixture->handler()->handle($this->fixture->auth->request('POST', 'https://demo.example/contact-form'));
    }

    /**
     * @return array<string, string>
     */
    private function body(string $return = '/contact', ?string $id = null): array
    {
        return [
            'form' => $id ?? $this->id,
            'return' => $return,
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.org',
            'message' => "Hello,\r\nI have a question & <b>a tip</b>.",
            ContactForm::HONEYPOT => '',
        ];
    }
}
