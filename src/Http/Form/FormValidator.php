<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Form;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Reads a submitted form: normalises each value (trimmed text, Unix line endings, lists for checkboxes), checks
 * required fields, the built-in rules for the field type and the field's own constraints, and keeps the first
 * error of each field. Messages say what to do, in plain en-GB.
 */
final readonly class FormValidator
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * @param array<mixed> $input the parsed request body
     */
    public function submit(Form $form, array $input): Form
    {
        $values = [];
        $errors = [];
        foreach ($form->fields() as $field) {
            $value = $this->normalise($field, $input[$field->name] ?? null);
            $values[$field->name] = $value;

            if ($value === '' || $value === []) {
                if ($field->required) {
                    $errors[$field->name] = $field->requiredMessage();
                }
                continue;
            }

            $violations = $this->validator->validate($value, [...$this->builtIn($field), ...$field->constraints]);
            if (count($violations) > 0) {
                $errors[$field->name] = (string) $violations->get(0)->getMessage();
            }
        }

        return $form->submitted($values, $errors);
    }

    /**
     * @return string|list<string>
     */
    private function normalise(Field $field, mixed $raw): string|array
    {
        if ($field->type === FieldType::Checkboxes) {
            return is_array($raw) ? array_values(array_filter($raw, is_string(...))) : [];
        }
        if ($field->type === FieldType::Checkbox) {
            return is_string($raw) && $raw !== '' ? '1' : '';
        }
        if (!is_string($raw)) {
            return '';
        }

        return trim(str_replace(["\r\n", "\r"], "\n", $raw));
    }

    /**
     * @return list<Constraint>
     */
    private function builtIn(Field $field): array
    {
        $label = $field->label;

        return match ($field->type) {
            FieldType::Email => [new Email(message: 'Enter an email address in the right format, like name@example.com')],
            FieldType::Url => [new Url(
                message: 'Enter a web address in the right format, like https://example.com',
                requireTld: true,
            )],
            FieldType::Number => [new Type('numeric', message: sprintf('Enter a number for “%s”', $label))],
            FieldType::Date => [new Date(message: sprintf('Enter a real date for “%s”', $label))],
            FieldType::Select, FieldType::Radios => [new Choice(
                choices: array_map(strval(...), array_keys($field->choices)),
                message: sprintf('Select one of the options for “%s”', $label),
            )],
            FieldType::Checkboxes => [new Choice(
                choices: array_map(strval(...), array_keys($field->choices)),
                multiple: true,
                multipleMessage: sprintf('Select only the options shown for “%s”', $label),
            )],
            default => [],
        };
    }
}
