<?php

/**
 * Crawlers and other programs that analytics counts as bots, never as visitors (ADR-0012), by kind:
 *
 * - `ai_training` and `ai_search`: the AI crawlers, from `resources/seo/ai-crawlers.php` (the list robots.txt uses),
 *   so both stay the same;
 * - `search`: search engines' crawlers;
 * - `other`: link previews, SEO tools, monitors and scripts.
 *
 * Each name is matched, ignoring case, anywhere in the `User-Agent` header; the first match wins, in this order, so
 * longer names that contain shorter ones come first. A user agent that matches none but has "bot", "crawl",
 * "spider", "slurp" or "fetch" in it is an unnamed bot (`Other bot`); an empty one is `No user agent`.
 */

declare(strict_types=1);

$ai = require dirname(__DIR__) . '/seo/ai-crawlers.php';

return [
    'ai_search' => $ai['search'],
    'ai_training' => $ai['training'],
    'search' => [
        'AdsBot-Google',
        'Applebot',
        'Baiduspider',
        'bingbot',
        'BingPreview',
        'coccocbot',
        'DuckDuckBot',
        'Googlebot',
        'Google-InspectionTool',
        'MojeekBot',
        'PetalBot',
        'Qwantbot',
        'SeznamBot',
        'Slurp',
        'Sogou',
        'Storebot-Google',
        'YandexBot',
        'Yeti',
    ],
    'other' => [
        'AhrefsBot',
        'curl',
        'Discordbot',
        'DotBot',
        'facebookexternalhit',
        'Go-http-client',
        'HeadlessChrome',
        'LinkedInBot',
        'MJ12bot',
        'Pinterestbot',
        'python-requests',
        'redditbot',
        'SemrushBot',
        'Slackbot',
        'TelegramBot',
        'Twitterbot',
        'UptimeRobot',
        'WhatsApp',
        'Wget',
    ],
];
