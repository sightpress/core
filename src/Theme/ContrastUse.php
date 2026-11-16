<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * What a colour pair is used for, which sets the contrast it needs (WCAG 2.2 AA): text needs 4.5:1
 * (success criterion 1.4.3), user interface parts such as borders, focus rings and icons need 3:1 (1.4.11).
 */
enum ContrastUse: string
{
    case Text = 'text';
    case Ui = 'ui';

    public function minimum(): float
    {
        return match ($this) {
            self::Text => 4.5,
            self::Ui => 3.0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Text => 'text',
            self::Ui => 'interface colour',
        };
    }
}
