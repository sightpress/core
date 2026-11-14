<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Form;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Validation;

#[CoversClass(FormValidator::class)]
#[UsesClass(Form::class)]
#[UsesClass(Field::class)]
#[UsesClass(FieldType::class)]
final class FormValidatorTest extends TestCase
{
    public function testAValidSubmissionIsNormalised(): void
    {
        $form = $this->submit([
            'name' => "  Ada Lovelace \r\n",
            'email' => 'ada@example.org',
            'bio' => "Line one\r\nLine two\rthree",
            'website' => 'https://example.org',
            'age' => '36',
            'born' => '1815-12-10',
            'role' => 'editor',
            'topics' => ['a11y', 42],
            'terms' => 'yes',
        ]);

        self::assertTrue($form->isValid(), (string) json_encode($form->errors()));
        self::assertSame('Ada Lovelace', $form->value('name'));
        self::assertSame("Line one\nLine two\nthree", $form->value('bio'));
        self::assertSame(['a11y'], $form->value('topics'));
        self::assertSame('1', $form->value('terms'));
    }

    public function testMissingRequiredFieldsSayWhatToDo(): void
    {
        $form = $this->submit(['name' => '   ', 'email' => ['not', 'a string']]);

        self::assertSame([
            'name' => 'Enter full name',
            'email' => 'Enter email address',
            'role' => 'Select role',
            'terms' => 'Agree to the terms to continue',
        ], $form->errors());
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function wrongValues(): iterable
    {
        yield 'email' => ['email', 'ada@', 'Enter an email address in the right format, like name@example.com'];
        yield 'web address' => ['website', 'example', 'Enter a web address in the right format, like https://example.com'];
        yield 'number' => ['age', 'thirty', 'Enter a number for “Age”'];
        yield 'date' => ['born', '1815-13-40', 'Enter a real date for “Date of birth”'];
        yield 'choice' => ['role', 'owner', 'Select one of the options for “Role”'];
        yield 'choices' => ['topics', ['a11y', 'cooking'], 'Select only the options shown for “Topics”'];
        yield 'own constraint' => ['name', str_repeat('a', 61), 'Enter a name of 60 characters or fewer'];
    }

    #[DataProvider('wrongValues')]
    public function testWrongValuesExplainTheRightFormat(string $field, mixed $value, string $message): void
    {
        $form = $this->submit([$field => $value] + $this->valid());

        self::assertSame([$field => $message], $form->errors());
    }

    /**
     * @param array<mixed> $input
     */
    private function submit(array $input): Form
    {
        $form = Form::create(
            'profile',
            new Field('name', 'Full name', constraints: [new Length(max: 60, maxMessage: 'Enter a name of 60 characters or fewer')]),
            new Field('email', 'Email address', FieldType::Email, autocomplete: 'email'),
            new Field('bio', 'Biography', FieldType::Textarea, required: false),
            new Field('website', 'Website', FieldType::Url, required: false),
            new Field('age', 'Age', FieldType::Number, required: false),
            new Field('born', 'Date of birth', FieldType::Date, required: false),
            new Field('role', 'Role', FieldType::Select, choices: ['editor' => 'Editor', 'admin' => 'Admin']),
            new Field('topics', 'Topics', FieldType::Checkboxes, required: false, choices: ['a11y' => 'Accessibility', 'seo' => 'Search']),
            new Field('terms', 'I agree to the terms', FieldType::Checkbox, requiredMessage: 'Agree to the terms to continue'),
        );

        return new FormValidator(Validation::createValidator())->submit($form, $input);
    }

    /**
     * @return array<string, string>
     */
    private function valid(): array
    {
        return ['name' => 'Ada', 'email' => 'ada@example.org', 'role' => 'editor', 'terms' => '1'];
    }
}
