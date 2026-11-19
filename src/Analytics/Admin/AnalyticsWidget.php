<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Admin;

use SightPress\Core\Analytics\AnalyticsModule;
use SightPress\Core\Analytics\Chart\ChartFormat;
use SightPress\Core\Analytics\Report\AnalyticsReports;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;
use SightPress\Module\Dashboard\DashboardContext;
use SightPress\Module\Dashboard\DashboardWidget;

/**
 * "Last 7 days" on the admin dashboard: one sentence with the visitors and page views of the last 7 days and the
 * change on the 7 days before, and a link to the Analytics screen. For people with `analytics.view`.
 */
final readonly class AnalyticsWidget implements DashboardWidget
{
    public function __construct(
        private AnalyticsReports $reports,
        private Translations $translations,
    ) {
    }

    public function id(): string
    {
        return 'analytics.summary';
    }

    public function title(): string
    {
        return 'Last 7 days';
    }

    public function permission(): string
    {
        return AnalyticsModule::VIEW;
    }

    public function position(): int
    {
        return 10;
    }

    public function render(DashboardContext $context): string
    {
        $locale = $context->locale();
        $report = $this->reports->open();
        $summary = $report->summary($report->lastDays(7));
        $format = new ChartFormat($locale);
        $sentence = (float) $summary->views->current === 0.0
            ? new Message('Nothing has been counted in the last 7 days yet.')
            : new Message('{visitors} and {views} in the last 7 days; visitors {change}.', [
                'visitors' => new Message('{count, plural, one {# visitor} other {# visitors}}', ['count' => $summary->visitors->current]),
                'views' => new Message('{count, plural, one {# page view} other {# page views}}', ['count' => $summary->views->current]),
                'change' => AnalyticsLabels::change($summary->visitors->change(), 7, $format->percent($summary->visitors->change() ?? 0.0)),
            ]);

        return sprintf(
            '<p>%s</p><p><a href="%s">%s</a></p>',
            self::e($this->translations->translate($sentence, [], $locale)),
            self::e($context->url('analytics.overview')),
            self::e($this->translations->translate(new Message('See all analytics'), [], $locale)),
        );
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
