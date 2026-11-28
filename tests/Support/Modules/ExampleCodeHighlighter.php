<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Block\CodeHighlighter;
use SightPress\Module\Block\CodeToken;

/**
 * A module's code highlighter, for registry tests: the whole code as one plain token.
 */
final class ExampleCodeHighlighter implements CodeHighlighter
{
    public function highlight(string $code, string $language): iterable
    {
        if ($code !== '') {
            yield new CodeToken($code);
        }
    }
}
