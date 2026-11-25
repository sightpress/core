<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\ModuleTurnOnCheck;

/**
 * Lets a test make "acme.calendar" refuse to be turned on, as text messages do without a provider.
 */
final class CalendarTurnOnCheck implements ModuleTurnOnCheck
{
    public static ?string $problem = null;

    public function problem(): ?Message
    {
        return self::$problem === null || self::$problem === '' ? null : new Message(self::$problem);
    }
}
