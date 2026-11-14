<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\PHPStan\Fixtures\ModuleBoundary\Http;

use SightPress\Core\Tests\PHPStan\Fixtures\ModuleBoundary\Page\Internal\PageRepository;

final class PageController
{
    public function repository(): PageRepository
    {
        return new PageRepository();
    }
}
