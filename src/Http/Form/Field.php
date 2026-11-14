<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Form;

use InvalidArgumentException;
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
     * @param string                $label           visible label, in plain words
     * @param string|null           $hint            short help shown under the label and read with the field
     * @param list<Constraint>      $constraints     extra checks after the built-in ones for the type
     * @param array<string, string> $choices         value => label, for select, radios and checkboxes
     * @param string|null           $autocomplete    `autocomplete` token, for example `email` or `new-password`
     * @param string|null           $requiredMessage message when a required field is empty; by default
     *                                               "Enter …" or "Select …" followed by the label
     */
    public function __construct(
        public string $name,
        public string $label,
        public FieldType $type = FieldType::Text,
        public ?string $hint = null,
        public bool $required = true,
        public array $constraints = [],
        public array $choices = [],
        public ?string $autocomplete = null,
        public ?string $requiredMessage = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Field name "%s" is not valid: use lower-case letters, digits and "_", starting with a letter.',
                $name,
            ));
        }
        if (trim($label) === '') {
            throw new InvalidArgumentException(sprintf('Field "%s" needs a label.', $name));
        }
        if ($type->hasChoices() && $choices === []) {
            throw new InvalidArgumentException(sprintf('Field "%s" needs choices.', $name));
        }
    }

    public function requiredMessage(): string
    {
        return $this->requiredMessage ?? $this->type->requiredVerb() . ' ' . lcfirst($this->label);
    }
}
