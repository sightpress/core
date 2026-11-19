<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\Admin\AnalyticsLabels;
use SightPress\Core\Analytics\Admin\AnalyticsRange;

#[CoversClass(AnalyticsRange::class)]
#[CoversClass(AnalyticsLabels::class)]
#[UsesNamespace('SightPress\Core')]
final class AnalyticsRangeTest extends TestCase
{
    public function testPresetsAndTheDefault(): void
    {
        $default = AnalyticsRange::fromQuery([], '2026-11-19');
        self::assertSame(['30', '2026-10-21', '2026-11-19', 'Last 30 days', ['range' => '30']], [$default->preset, $default->range->from, $default->range->to, (string) $default->name(), $default->query()]);
        self::assertSame('2026-11-13', AnalyticsRange::fromQuery(['range' => '7'], '2026-11-19')->range->from);
        self::assertSame('30', AnalyticsRange::fromQuery(['range' => '8'], '2026-11-19')->preset, 'not a preset');
        self::assertSame(['Last 7 days', 'Last 30 days', 'Last 90 days', 'Last 12 months'], array_map(static fn (string $preset): string => (string) AnalyticsRange::presetName($preset), AnalyticsRange::PRESETS));
        self::assertSame([], $default->form->errors());
    }

    public function testACustomRangeOrItsErrors(): void
    {
        $custom = AnalyticsRange::fromQuery(['from' => ' 2026-01-01 ', 'to' => '2026-01-31'], '2026-11-19');
        self::assertSame([null, '2026-01-01', '2026-01-31', 'The chosen days', ['from' => '2026-01-01', 'to' => '2026-01-31']], [$custom->preset, $custom->range->from, $custom->range->to, (string) $custom->name(), $custom->query()]);
        self::assertSame('2026-01-01', $custom->form->value('from'));

        $errors = static fn (array $query): array => array_map('strval', AnalyticsRange::fromQuery($query, '2026-11-19')->form->errors());
        self::assertSame(['from' => 'Enter the first day as a date, for example 2026-11-01'], $errors(['to' => '2026-01-31']));
        self::assertSame(['to' => 'Enter the last day as a date, for example 2026-11-30'], $errors(['from' => '2026-01-01', 'to' => '31/01/2026']));
        self::assertSame(['to' => 'The last day must be the same as the first day or after it'], $errors(['from' => '2026-02-01', 'to' => '2026-01-31']));
        self::assertSame(['to' => 'Choose at most 800 days'], $errors(['from' => '2020-01-01', 'to' => '2026-01-31']));
        $failed = AnalyticsRange::fromQuery(['from' => 'x', 'to' => 'y'], '2026-11-19');
        self::assertSame(['30', 2], [$failed->preset, count($failed->form->errorSummary())], 'the default range, with the errors on the form');
    }

    public function testLabels(): void
    {
        self::assertSame(
            ['Direct or unknown', 'Google (search engine)', 'ChatGPT (AI assistant)', 'newsletter (campaign)', 'example.org'],
            array_map('strval', [AnalyticsLabels::source('direct', ''), AnalyticsLabels::source('search', 'Google'), AnalyticsLabels::source('ai', 'ChatGPT'), AnalyticsLabels::source('campaign', 'newsletter'), AnalyticsLabels::source('site', 'example.org')]),
        );
        self::assertSame(['Phone', 'Tablet', 'Computer', 'Unknown'], array_map(static fn (string $d): string => (string) AnalyticsLabels::device($d), ['phone', 'tablet', 'computer', 'toaster']));
        self::assertSame(['Search engine', 'AI search and assistants', 'AI training', 'Other'], array_map(static fn (string $k): string => (string) AnalyticsLabels::botKind($k), ['search', 'ai_search', 'ai_training', 'other']));
        self::assertSame(['Pages', 'Pages as Markdown', 'Sitemap', 'robots.txt', 'llms.txt', 'llms-full.txt', 'feed'], array_map(static fn (string $r): string => (string) AnalyticsLabels::resource($r), ['page', 'markdown', 'sitemap', 'robots', 'llms', 'llms_full', 'feed']));
        self::assertSame(
            ['none in the 30 days before', 'up 12% on the 30 days before', 'down 5% on the day before', 'the same as the 7 days before'],
            [(string) AnalyticsLabels::change(null, 30, '0%'), (string) AnalyticsLabels::change(12.0, 30, '12%'), (string) AnalyticsLabels::change(-5.0, 1, '5%'), (string) AnalyticsLabels::change(0.0, 7, '0%')],
        );
    }
}
