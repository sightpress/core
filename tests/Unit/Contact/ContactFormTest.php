<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Contact;

use SightPress\Core\Tests\Support\Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Contact\ContactForm;
use SightPress\Core\Http\Session\Session;

#[CoversClass(ContactForm::class)]
#[UsesNamespace('SightPress\Core\Http')]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
final class ContactFormTest extends TestCase
{
    public function testIdComesFromTheBlockData(): void
    {
        $id = ContactForm::id(['recipient' => 'a@example.org', 'subject' => 'Hi']);

        self::assertMatchesRegularExpression('/^contact-[0-9a-f]{12}$/', $id);
        self::assertSame($id, ContactForm::id(['subject' => 'Hi', 'recipient' => 'a@example.org']));
        self::assertNotSame($id, ContactForm::id(['recipient' => 'b@example.org', 'subject' => 'Hi']));
    }

    public function testHasNameEmailAndMessage(): void
    {
        $form = ContactForm::form('contact-abc');

        self::assertSame(['name', 'email', 'message'], Text::of(array_map(static fn ($field): string => $field->name, $form->fields())));
        self::assertSame(['name', 'email'], Text::of([$form->field('name')->autocomplete, $form->field('email')->autocomplete]));
    }

    public function testRemembersOneSubmissionUntilThePageShowsIt(): void
    {
        $session = new Session();

        $empty = ContactForm::recall($session, 'contact-a');
        self::assertFalse($empty['sent']);
        self::assertSame([], Text::of($empty['form']->errors()));

        ContactForm::remember($session, 'contact-a', ['name' => 'Ada', 'message' => ''], ['message' => 'Enter your message.'], false);
        ContactForm::remember($session, 'contact-b', ['name' => 'Bob'], [], true);
        $a = ContactForm::recall($session, 'contact-a');
        self::assertSame(['message' => 'Enter your message.'], Text::of($a['form']->errors()));
        self::assertSame('Ada', Text::of($a['form']->value('name')));
        self::assertSame([], Text::of(ContactForm::recall($session, 'contact-a')['form']->errors()));

        $b = ContactForm::recall($session, 'contact-b');
        self::assertTrue($b['sent']);
        self::assertSame('', Text::of($b['form']->value('name')));
    }

    public function testIgnoresBrokenSessionData(): void
    {
        $session = new Session();
        $bag = &$session->bag('contact_form');
        $bag['contact-a'] = ['values' => ['name' => ['x'], 3 => 'y', 'email' => 'a@example.org'], 'errors' => 'nope', 'sent' => 'yes'];
        $bag['contact-b'] = 'nope';

        $a = ContactForm::recall($session, 'contact-a');
        self::assertFalse($a['sent']);
        self::assertSame('a@example.org', Text::of($a['form']->value('email')));
        self::assertSame('', Text::of($a['form']->value('name')));
        self::assertFalse(ContactForm::recall($session, 'contact-b')['sent']);
    }
}
