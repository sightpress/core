<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Html;
use SightPress\Core\Block\Rule\ImageAltRule;
use SightPress\Module\Block\A11yIssue;
use SightPress\Module\Block\A11ySeverity;

#[CoversClass(ImageAltRule::class)]
#[UsesClass(Html::class)]
final class ImageAltRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function images(): iterable
    {
        yield 'described' => [['alt' => 'A red lighthouse on a cliff'], []];
        yield 'decorative' => [['decorative' => true], []];
        yield 'decorative with alt' => [['decorative' => true, 'alt' => 'IMG_1234.jpg'], []];
        yield 'missing' => [[], ['image.alt_missing']];
        yield 'blank' => [['alt' => '   ', 'decorative' => false], ['image.alt_missing']];
        yield 'file name' => [['alt' => 'lighthouse-final.JPG'], ['image.alt_file_name']];
        yield 'camera name' => [['alt' => 'DSC_0042'], ['image.alt_file_name']];
        yield 'redundant' => [['alt' => 'Picture of a lighthouse'], ['image.alt_redundant']];
        yield 'redundant article' => [['alt' => 'An image of the harbour'], ['image.alt_redundant']];
        yield 'long' => [['alt' => str_repeat('a', 251)], ['image.alt_long']];
        yield 'exactly long enough' => [['alt' => str_repeat('a', 250)], []];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $rules
     */
    #[DataProvider('images')]
    public function testChecksAltText(array $data, array $rules): void
    {
        $issues = new ImageAltRule()->check($data);

        self::assertSame($rules, array_map(static fn (A11yIssue $issue): string => $issue->rule, $issues));
        foreach ($issues as $issue) {
            self::assertSame('/alt', $issue->field);
            self::assertSame(['1.1.1'], $issue->wcag);
            self::assertSame($issue->rule === 'image.alt_missing' ? A11ySeverity::Error : A11ySeverity::Warning, $issue->severity);
        }
    }
}
