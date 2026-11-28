<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Form;

use InvalidArgumentException;
use SightPress\Core\Template\Message;
use Symfony\Component\Validator\Constraint;

/**
 * One form field: what it asks for, how it is checked and what helps people fill it in.
 *
 * Required fields are the norm; optional ones are marked "(optional)" in their label.
 */
final readonly class Field
{
    /**
     * @param string                $name            lower-case letters, digits and `_`
     * @param string|Message        $label           visible label, in plain words (en-GB; shown translated)
     * @param string|Message|null   $hint            short help shown under the label and read with the field
     * @param list<Constraint>      $constraints     extra checks after the built-in ones for the type
     * @param array<array-key, string|Message|array<string, string|Message>> $choices value => label, for select, radios and
     *                                               checkboxes; a select can group them: group label => [value =>
     *                                               label] becomes an `<optgroup>`
     * @param string|null           $autocomplete    `autocomplete` token, for example `email` or `new-password`
     * @param string|Message|null   $requiredMessage message when a required field is empty; by default
     *                                               "Enter …" or "Select …" followed by the label
     * @param string|null           $accept          for a file field: the `accept` list, for example
     *                                               `image/jpeg,image/png`
     * @param string|null           $inputmode       `inputmode` hint for on-screen keyboards, for example `numeric`
     * @param bool                  $localeChoices   whether the choices are languages (values are locale codes, labels
     *                                               in that language): each option gets its `lang`
     * @param int|array<string, int>|null $maxBytes  for a file field: the largest file, checked in the browser before
     *                                               the form is sent; or limits by the start of the file's type
     *                                               (`image`, `video`, `audio`, and `*` for the rest)
     * @param array<string, string|Message> $choiceHints for checkboxes and radios: a short description of an option,
     *                                               value => text, shown under it and read with it
     */
    public function __construct(
        public string $name,
        public string|Message $label,
        public FieldType $type = FieldType::Text,
        public string|Message|null $hint = null,
        public bool $required = true,
        public array $constraints = [],
        public array $choices = [],
        public ?string $autocomplete = null,
        public string|Message|null $requiredMessage = null,
        public ?string $accept = null,
        public ?string $inputmode = null,
        public bool $localeChoices = false,
        public int|array|null $maxBytes = null,
        public array $choiceHints = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Field name "%s" is not valid: use lower-case letters, digits and "_", starting with a letter.',
                $name,
            ));
        }
        if (trim((string) $label) === '') {
            throw new InvalidArgumentException(sprintf('Field "%s" needs a label.', $name));
        }
        if ($type->hasChoices() && $choices === []) {
            throw new InvalidArgumentException(sprintf('Field "%s" needs choices.', $name));
        }
    }

    /**
     * Every value that can be chosen, groups included.
     *
     * @return list<string>
     */
    public function choiceValues(): array
    {
        $values = [];
        foreach ($this->choices as $value => $label) {
            if (is_array($label)) {
                foreach (array_keys($label) as $grouped) {
                    $values[] = (string) $grouped;
                }
            } else {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    public function requiredMessage(): Message
    {
        if ($this->requiredMessage !== null) {
            return Message::of($this->requiredMessage);
        }

        $field = ['field' => Message::of($this->label)->lowerFirst()];

        return match ($this->type->requiredVerb()) {
            'Select' => new Message('Select {field}', $field),
            'Choose' => new Message('Choose {field}', $field),
            default => new Message('Enter {field}', $field),
        };
    }
}
