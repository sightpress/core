<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Form;

use SightPress\Core\Template\Message;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintViolationInterface;
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
            if ($field->type === FieldType::File) {
                // Files come with the uploaded files, not the body: the handler checks them.
                continue;
            }
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
                $message = self::message($violations->get(0));
                // The built-in checks name the field: “{field}” is its label.
                $errors[$field->name] = str_contains($message->text, '{field}')
                    ? new Message($message->text, [...$message->parameters, 'field' => Message::of($field->label)])
                    : $message;
            }
        }

        return $form->submitted($values, $errors);
    }

    /**
     * The violation's message as written in the constraint, with the validator's values (`{{ limit }}`), so it can be
     * translated. A message without a template is kept as it is.
     */
    public static function message(ConstraintViolationInterface $violation): Message
    {
        $template = $violation->getMessageTemplate();
        $parameters = [];
        foreach ($violation->getParameters() as $name => $value) {
            $parameters[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        return $template === '' ? Message::of((string) $violation->getMessage()) : new Message($template, $parameters);
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
        if ($field->type === FieldType::Password) {
            // Spaces are part of a password: keep it exactly as typed.
            return $raw;
        }

        return trim(str_replace(["\r\n", "\r"], "\n", $raw));
    }

    /**
     * @return list<Constraint>
     */
    private function builtIn(Field $field): array
    {

        return match ($field->type) {
            FieldType::Email => [new Email(message: 'Enter an email address in the right format, like name@example.com')],
            FieldType::Url => [new Url(
                message: 'Enter a web address in the right format, like https://example.com',
                requireTld: true,
            )],
            FieldType::Number => [new Type('numeric', message: 'Enter a number for “{field}”')],
            FieldType::Date => [new Date(message: 'Enter a real date for “{field}”')],
            FieldType::Select, FieldType::Radios => [new Choice(
                choices: $field->choiceValues(),
                message: 'Select one of the options for “{field}”',
            )],
            FieldType::Checkboxes => [new Choice(
                choices: $field->choiceValues(),
                multiple: true,
                multipleMessage: 'Select only the options shown for “{field}”',
            )],
            default => [],
        };
    }
}
