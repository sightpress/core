<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Form;

use InvalidArgumentException;

/**
 * A form: its fields, the values to show and, after it is sent, one error message per field that failed.
 *
 * Immutable: {@see FormValidator::submit()} returns a new form with the submitted values and errors. The core form
 * templates (`form/macros.html.twig`) render it accessibly: labels, hints and errors linked with
 * `aria-describedby`, `aria-invalid`, and an error summary at the top that links to each field and gets focus.
 */
final readonly class Form
{
    /**
     * @param list<Field>                         $fields
     * @param array<string, string|list<string>>  $values
     * @param array<string, string>               $errors field name => message
     */
    private function __construct(
        public string $id,
        private array $fields,
        private array $values,
        private array $errors,
        public bool $submitted,
    ) {
    }

    /**
     * @param string $id lower-case letters, digits and `-`; prefixes every field id
     */
    public static function create(string $id, Field ...$fields): self
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('Form id "%s" is not valid: use lower-case letters, digits and "-".', $id));
        }

        $names = [];
        foreach ($fields as $field) {
            if (isset($names[$field->name])) {
                throw new InvalidArgumentException(sprintf('Form "%s" has two fields named "%s".', $id, $field->name));
            }
            $names[$field->name] = true;
        }

        return new self($id, array_values($fields), [], [], false);
    }

    /**
     * The form filled in with existing values, for example when editing something.
     *
     * @param array<string, string|list<string>> $values
     */
    public function withValues(array $values): self
    {
        return new self($this->id, $this->fields, $values + $this->values, $this->errors, $this->submitted);
    }

    /**
     * The form with extra errors found after validation, for example "That email address is already used".
     *
     * @param array<string, string> $errors field name => message
     */
    public function withErrors(array $errors): self
    {
        foreach (array_keys($errors) as $name) {
            $this->field($name);
        }

        return new self($this->id, $this->fields, $this->values, $errors + $this->errors, true);
    }

    /**
     * @internal used by {@see FormValidator}
     *
     * @param array<string, string|list<string>> $values
     * @param array<string, string>              $errors
     */
    public function submitted(array $values, array $errors): self
    {
        return new self($this->id, $this->fields, $values, $errors, true);
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $name): Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        throw new InvalidArgumentException(sprintf('Form "%s" has no field named "%s".', $this->id, $name));
    }

    /**
     * @return string|list<string>
     */
    public function value(string $name): string|array
    {
        return $this->values[$name] ?? ($this->field($name)->type === FieldType::Checkboxes ? [] : '');
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function values(): array
    {
        $values = [];
        foreach ($this->fields as $field) {
            $values[$field->name] = $this->value($field->name);
        }

        return $values;
    }

    public function error(string $name): ?string
    {
        return $this->errors[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function isValid(): bool
    {
        return $this->submitted && $this->errors === [];
    }

    /**
     * The `id` of the field's control; for a group, of its first option (where the error summary links to).
     */
    public function fieldId(string $name): string
    {
        return $this->id . '-' . str_replace('_', '-', $this->field($name)->name);
    }

    /**
     * The `id` of option number `$index` (from 0) of a group; the first one is {@see fieldId()}.
     */
    public function optionId(string $name, int $index): string
    {
        return $index === 0 ? $this->fieldId($name) : $this->fieldId($name) . '-' . ($index + 1);
    }

    /**
     * The errors in field order, for the error summary.
     *
     * @return list<array{fieldId: string, message: string}>
     */
    public function errorSummary(): array
    {
        $summary = [];
        foreach ($this->fields as $field) {
            if (isset($this->errors[$field->name])) {
                $summary[] = ['fieldId' => $this->fieldId($field->name), 'message' => $this->errors[$field->name]];
            }
        }

        return $summary;
    }
}
