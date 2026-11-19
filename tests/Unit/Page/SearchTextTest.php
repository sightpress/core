<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\SearchText;

#[CoversClass(SearchText::class)]
final class SearchTextTest extends TestCase
{
    public function testKeepsTheTextOfMarkdown(): void
    {
        $markdown = "# Our *story*\n\n> A quote\n\n- item **bold** and snake_case\n1. first\n\n"
            . "Read [our news](https://example.org/news) and ![A harbour at dawn](https://example.org/a.jpg)\n\n"
            . "_The harbour_\n\n[Video: The tour](https://example.org/tour.mp4)\n\nTranscript:\n\nHello there.\n\n**Audio: Talk**\n\n"
            . "| Day | Hours |\n|---|:---:|\n| Mon | 9–5 |\n\n---\n\nPrice \\*not\\* 5\\_000 \\[sic\\] <https://example.org> [Opening hours (PDF, 2 MB)](https://example.org/h.pdf)";

        self::assertSame(
            'Our story A quote item bold and snake_case first Read our news and A harbour at dawn The harbour The tour Hello there. Talk Day Hours Mon 9–5 Price *not* 5_000 [sic] Opening hours (PDF, 2 MB)',
            SearchText::fromMarkdown($markdown),
        );
        self::assertSame('', SearchText::fromMarkdown(" \n "));
    }
}
