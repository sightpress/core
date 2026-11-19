<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Assistant;

/**
 * Stands in for the `seo_meta` task in tests ({@see TestSuggestTask}).
 */
final class TestSeoMetaTask extends TestSuggestTask
{
    public function id(): string
    {
        return 'seo_meta';
    }
}
