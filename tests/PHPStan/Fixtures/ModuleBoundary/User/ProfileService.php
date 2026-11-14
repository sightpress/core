<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\PHPStan\Fixtures\ModuleBoundary\User;

use SightPress\Core\Http\Error\ErrorPage;
use SightPress\Core\Tests\PHPStan\Fixtures\ModuleBoundary\Module\ModuleRegistry;
use SightPress\Core\Tests\PHPStan\Fixtures\ModuleBoundary\Page\Contract\PageFinder;
use SightPress\Core\Tests\PHPStan\Fixtures\ModuleBoundary\Page\Internal\PageRepository;
use SightPress\Module\ModuleInterface;

final class ProfileService
{
    public function __construct(
        private PageFinder $finder,
        private ?ModuleInterface $module = null,
        private ?ErrorPage $page = null,
    ) {
    }

    public function pages(): PageRepository
    {
        $first = new PageRepository();
        $second = new \SightPress\Core\Tests\PHPStan\Fixtures\ModuleBoundary\Page\Internal\PageRepository();

        return $first instanceof PageRepository ? $first : $second;
    }

    public function registry(): ?ModuleRegistry
    {
        return null;
    }

    public function self(): self
    {
        return new UserModule() instanceof UserModule ? $this : new self($this->finder);
    }

    public function module(): \SightPress\Core\Tests\PHPStan\Fixtures\ModuleBoundary\Page\PageModule
    {
        return new \SightPress\Core\Tests\PHPStan\Fixtures\ModuleBoundary\Page\PageModule();
    }
}
