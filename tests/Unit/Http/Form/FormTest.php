<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Form;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;

#[CoversClass(Form::class)]
#[CoversClass(Field::class)]
#[CoversClass(FieldType::class)]
final class FormTest extends TestCase
{
    public function testAFormHoldsFieldsValuesAndErrors(): void
    {
        $form = Form::create(
            'profile',
            new Field('display_name', 'Display name'),
            new Field('topics', 'Topics', FieldType::Checkboxes, choices: ['a11y' => 'Accessibility', 'seo' => 'Search']),
        )->withValues(['display_name' => 'Ada']);

        self::assertSame('profile', $form->id);
        self::assertCount(2, $form->fields());
        self::assertSame('Ada', $form->value('display_name'));
        self::assertSame(['display_name' => 'Ada', 'topics' => []], $form->values());
        self::assertSame('profile-display-name', $form->fieldId('display_name'));
        self::assertSame('profile-topics', $form->optionId('topics', 0));
        self::assertSame('profile-topics-2', $form->optionId('topics', 1));
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
        self::assertSame(['first' => 'Enter first', 'second' => 'Enter second'], $form->errors());
        self::assertSame([
            ['fieldId' => 'f-first', 'message' => 'Enter first'],
            ['fieldId' => 'f-second', 'message' => 'Enter second'],
        ], $form->errorSummary());
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
        self::assertSame('Enter email address', new Field('email', 'Email address', FieldType::Email)->requiredMessage());
        self::assertSame('Select role', new Field('role', 'Role', FieldType::Select, choices: ['a' => 'A'])->requiredMessage());
        self::assertSame('Agree to the terms', new Field('terms', 'Terms', FieldType::Checkbox, requiredMessage: 'Agree to the terms')->requiredMessage());
        self::assertTrue(FieldType::Radios->isGroup());
        self::assertFalse(FieldType::Select->isGroup());
        self::assertTrue(FieldType::Checkboxes->hasChoices());
        self::assertFalse(FieldType::Text->hasChoices());
        self::assertSame('Select', FieldType::Checkbox->requiredVerb());
    }
}
