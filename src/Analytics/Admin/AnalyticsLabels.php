<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Admin;

use SightPress\Core\Analytics\Classification\Bot;
use SightPress\Core\Analytics\Classification\DeviceClassifier;
use SightPress\Core\Template\Message;

/**
 * The words the Analytics screen uses for what the reports store as keys: sources, devices, kinds of bots and what
 * they read. Names of sites, engines and bots stay as they are.
 */
final class AnalyticsLabels
{
    public static function source(string $type, string $source): Message
    {
        return match ($type) {
            'direct' => new Message('Direct or unknown'),
            'search' => new Message('{name} (search engine)', ['name' => $source]),
            'ai' => new Message('{name} (AI assistant)', ['name' => $source]),
            'campaign' => new Message('{name} (campaign)', ['name' => $source]),
            default => new Message('{name}', ['name' => $source]),
        };
    }

    public static function device(string $device): Message
    {
        return match ($device) {
            DeviceClassifier::PHONE => new Message('Phone'),
            DeviceClassifier::TABLET => new Message('Tablet'),
            DeviceClassifier::COMPUTER => new Message('Computer'),
            default => new Message('Unknown'),
        };
    }

    public static function botKind(string $kind): Message
    {
        return match ($kind) {
            Bot::SEARCH => new Message('Search engine'),
            Bot::AI_SEARCH => new Message('AI search and assistants'),
            Bot::AI_TRAINING => new Message('AI training'),
            default => new Message('Other'),
        };
    }

    public static function resource(string $resource): Message
    {
        return match ($resource) {
            'page' => new Message('Pages'),
            'markdown' => new Message('Pages as Markdown'),
            'sitemap' => new Message('Sitemap'),
            'robots' => new Message('robots.txt'),
            'llms' => new Message('llms.txt'),
            'llms_full' => new Message('llms-full.txt'),
            default => new Message('{name}', ['name' => $resource]),
        };
    }

    /**
     * "up 12% on the previous 30 days", "down 5% …", "the same as …", "none in …" (the figure's change, in words).
     */
    public static function change(?float $change, int $days, string $percent): Message
    {
        return match (true) {
            $change === null => new Message('none in the {days, plural, one {day before} other {# days before}}', ['days' => $days]),
            $change > 0 => new Message('up {change} on the {days, plural, one {day before} other {# days before}}', ['change' => $percent, 'days' => $days]),
            $change < 0 => new Message('down {change} on the {days, plural, one {day before} other {# days before}}', ['change' => $percent, 'days' => $days]),
            default => new Message('the same as the {days, plural, one {day before} other {# days before}}', ['days' => $days]),
        };
    }
}
