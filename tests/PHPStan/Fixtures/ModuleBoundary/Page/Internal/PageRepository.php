<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\PHPStan\Fixtures\ModuleBoundary\Page\Internal;

use SightPress\Core\Tests\PHPStan\Fixtures\ModuleBoundary\Page\PageModule;

final class PageRepository
{
    public function module(): PageModule
    {
        return new PageModule();
    }
}
