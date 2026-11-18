<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Form;

use SightPress\Core\Tests\Support\Text;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;

#[CoversClass(Form::class)]
#[CoversClass(Field::class)]
#[CoversClass(FieldType::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
final class FormTest extends TestCase
{
    public function testAFormHoldsFieldsValuesAndErrors(): void
    {
        $form = Form::create(
            'profile',
            new Field('display_name', 'Display name'),
            new Field('topics', 'Topics', FieldType::Checkboxes, choices: ['a11y' => 'Accessibility', 'seo' => 'Search']),
        )->withValues(['display_name' => 'Ada']);

        self::assertSame('profile', Text::of($form->id));
        self::assertCount(2, $form->fields());
        self::assertTrue($form->has('topics'));
        self::assertFalse($form->has('email'));
        self::assertSame('Ada', Text::of($form->value('display_name')));
        self::assertSame('Ada', Text::of($form->text('display_name')));
        self::assertSame('', Text::of($form->text('topics')));
        self::assertSame(['display_name' => 'Ada', 'topics' => []], Text::of($form->values()));
        self::assertSame('profile-display-name', Text::of($form->fieldId('display_name')));
        self::assertSame('profile-topics', Text::of($form->optionId('topics', 0)));
        self::assertSame('profile-topics-2', Text::of($form->optionId('topics', 1)));
        self::assertFalse($form->submitted);
        self::assertFalse($form->isValid());
        self::assertNull($form->error('display_name'));
    }

    public function testErrorsAreSummarisedInFieldOrder(): void
    {
        $form = Form::create('f', new Field('first', 'First'), new Field('second', 'Second'))
            ->submitted(['first' => '', 'second' => ''], ['second' => 'Enter second'])
            ->withErrors(['first' => 'Enter first']);

        self::assertTrue($form->submitted);
        self::assertFalse($form->isValid());
        self::assertSame(['first' => 'Enter first', 'second' => 'Enter second'], Text::of($form->errors()));
        self::assertSame([
            ['fieldId' => 'f-first', 'message' => 'Enter first'],
            ['fieldId' => 'f-second', 'message' => 'Enter second'],
        ], Text::of($form->errorSummary()));
        self::assertTrue(Form::create('f', new Field('first', 'First'))->submitted(['first' => 'x'], [])->isValid());
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function mistakes(): iterable
    {
        yield 'form id' => [static fn () => Form::create('Profile'), 'Form id "Profile" is not valid'];
        yield 'duplicate field' => [
            static fn () => Form::create('f', new Field('a', 'A'), new Field('a', 'A again')),
            'Form "f" has two fields named "a".',
        ];
        yield 'unknown field' => [static fn () => Form::create('f')->field('x'), 'Form "f" has no field named "x".'];
        yield 'error for unknown field' => [static fn () => Form::create('f')->withErrors(['x' => 'No']), 'Form "f" has no field named "x".'];
        yield 'field name' => [static fn () => new Field('Email', 'Email'), 'Field name "Email" is not valid'];
        yield 'label' => [static fn () => new Field('email', ' '), 'Field "email" needs a label.'];
        yield 'choices' => [static fn () => new Field('role', 'Role', FieldType::Radios), 'Field "role" needs choices.'];
    }

    #[DataProvider('mistakes')]
    public function testMistakesInAFormDefinitionAreClear(\Closure $define, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $define();
    }

    public function testRequiredMessagesStartWithWhatToDo(): void
    {
        self::assertSame('Enter email address', Text::of(new Field('email', 'Email address', FieldType::Email)->requiredMessage()));
        self::assertSame('Select role', Text::of(new Field('role', 'Role', FieldType::Select, choices: ['a' => 'A'])->requiredMessage()));
        self::assertSame('Agree to the terms', Text::of(new Field('terms', 'Terms', FieldType::Checkbox, requiredMessage: 'Agree to the terms')->requiredMessage()));
        self::assertTrue(FieldType::Radios->isGroup());
        self::assertFalse(FieldType::Select->isGroup());
        self::assertTrue(FieldType::Checkboxes->hasChoices());
        self::assertFalse(FieldType::Text->hasChoices());
        self::assertSame('Select', Text::of(FieldType::Checkbox->requiredVerb()));
        self::assertSame('Choose picture', Text::of(new Field('picture', 'Picture', FieldType::File, accept: 'image/png')->requiredMessage()));
    }

    public function testGroupedChoicesListEveryValue(): void
    {
        $field = new Field('timezone', 'Time zone', FieldType::Select, choices: [
            'UTC' => ['UTC' => 'UTC'],
            'Europe' => ['Europe/Lisbon' => 'Lisbon', 'Europe/London' => 'London'],
            'none' => 'None',
        ]);

        self::assertSame(['UTC', 'Europe/Lisbon', 'Europe/London', 'none'], Text::of($field->choiceValues()));
    }
}
