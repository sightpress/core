<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Privacy;

use RuntimeException;
use SightPress\Module\Privacy\ProcessorSource;

/**
 * A processor source that fails, for tests: its third parties are left out.
 */
final class FailingProcessorSource implements ProcessorSource
{
    public function processors(): iterable
    {
        throw new RuntimeException('settings unreadable');
    }
}
