<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Contact;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Contact\ContactForm;
use SightPress\Core\Contact\ContactFormBlock;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Tests\Support\ContactFixture;
use SightPress\Core\Tests\Support\PlainBlockContext;

#[CoversClass(ContactFormBlock::class)]
#[UsesNamespace('SightPress\Core')]
final class ContactFormBlockTest extends TestCase
{
    private ContactFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ContactFixture();
    }

    public function testDescribesItself(): void
    {
        $type = $this->fixture->type;

        self::assertSame(['contact_form', 'Contact form', 1, []], [$type->name(), $type->label(), $type->version(), $type->a11yRules()]);
        self::assertSame(['recipient'], $type->schema()['required']);
        self::assertSame(['recipient' => 'a@example.org'], $type->upgradeData(1, ['recipient' => 'a@example.org']));
        self::assertSame('', $type->renderMarkdown(ContactFixture::FORM, new PlainBlockContext()));
        self::assertNull($type->structuredData(ContactFixture::FORM, new PlainBlockContext()));
    }

    public function testShowsNothingWithoutAVisitor(): void
    {
        self::assertSame('', $this->fixture->type->render(ContactFixture::FORM, new PlainBlockContext()));
        self::assertSame('', $this->fixture->type->render(ContactFixture::FORM, $this->fixture->blocks->context()));
    }

    public function testRendersAnAccessibleFormThatNeverShowsTheRecipient(): void
    {
        $id = ContactForm::id(ContactFixture::FORM);
        $request = $this->fixture->auth->request('GET', 'https://demo.example/contact?x=1');

        $html = $this->fixture->type->render(ContactFixture::FORM, $this->fixture->context($request));

        self::assertStringContainsString('<section class="block-contact-form" id="' . $id . '-block" aria-label="Contact form">', $html);
        self::assertStringContainsString('<form id="' . $id . '" action="/contact-form" method="post" novalidate>', $html);
        self::assertStringContainsString('<input type="hidden" name="form" value="' . $id . '">', $html);
        self::assertStringContainsString('<input type="hidden" name="return" value="/contact">', $html);
        self::assertStringContainsString('<label for="' . $id . '-name">Your name</label>', $html);
        self::assertStringContainsString('autocomplete="email"', $html);
        self::assertStringContainsString('<textarea id="' . $id . '-message"', $html);
        self::assertMatchesRegularExpression('~<div hidden>\s*<label for="' . $id . '-website">Leave this field empty</label>\s*<input type="text" id="' . $id . '-website" name="website" value="" tabindex="-1" autocomplete="off">~', $html);
        self::assertStringContainsString('name="csrf_value"', $html);
        self::assertStringContainsString('<button type="submit">Send message</button>', $html);
        self::assertStringNotContainsString('office@example.org', $html);
        self::assertStringNotContainsString('error-summary', $html);
    }

    public function testShowsTheOutcomeOfTheLastSubmissionOnce(): void
    {
        $data = ContactFixture::FORM + ['submit_text' => 'Ask us', 'success_text' => 'Thanks, we\'ll be in touch.'];
        $id = ContactForm::id($data);
        $session = new Session();
        $request = $this->fixture->auth->request('GET', 'https://demo.example/contact', session: $session);
        $context = $this->fixture->context($request);

        ContactForm::remember($session, $id, ['name' => 'Ada <b>', 'email' => 'nope', 'message' => 'Hi'], ['email' => 'Enter an email address.'], false);
        $html = $this->fixture->type->render($data, $context);
        self::assertStringContainsString('<section class="error-summary" id="' . $id . '-errors"', $html);
        self::assertStringContainsString('<li><a href="#' . $id . '-email">Enter an email address.</a></li>', $html);
        self::assertStringContainsString('value="Ada &lt;b&gt;"', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('<button type="submit">Ask us</button>', $html);

        ContactForm::remember($session, $id, [], [], true);
        $html = $this->fixture->type->render($data, $context);
        self::assertStringContainsString('<div class="alert alert--success" role="status" tabindex="-1" autofocus>', $html);
        self::assertStringContainsString('<p>Thanks, we&#039;ll be in touch.</p>', $html);
        self::assertStringNotContainsString('<form', $html);

        self::assertStringContainsString('<form', $this->fixture->type->render($data, $context));
        ContactForm::remember($session, ContactForm::id(ContactFixture::FORM), [], [], true);
        self::assertStringContainsString('Thank you. Your message has been sent.', $this->fixture->type->render(ContactFixture::FORM, $context));
    }
}
