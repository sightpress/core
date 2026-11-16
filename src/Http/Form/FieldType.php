<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Form;

/**
 * The kind of control a {@see Field} renders as.
 */
enum FieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case Password = 'password';
    case Url = 'url';
    case Number = 'number';
    case Tel = 'tel';
    case Date = 'date';
    case Textarea = 'textarea';
    case Select = 'select';
    case Radios = 'radios';
    case Checkbox = 'checkbox';
    case Checkboxes = 'checkboxes';

    /** A file upload; the handler reads and checks the uploaded file itself. */
    case File = 'file';

    /**
     * Whether the person picks from {@see Field::$choices}.
     */
    public function hasChoices(): bool
    {
        return in_array($this, [self::Select, self::Radios, self::Checkboxes], true);
    }

    /**
     * Whether the field is a group of controls in a `<fieldset>` with a `<legend>`.
     */
    public function isGroup(): bool
    {
        return $this === self::Radios || $this === self::Checkboxes;
    }

    /**
     * The verb that starts the "required" message: you enter text but select an option.
     */
    public function requiredVerb(): string
    {
        return match ($this) {
            self::Select, self::Radios, self::Checkboxes, self::Checkbox => 'Select',
            self::File => 'Choose',
            default => 'Enter',
        };
    }
}
