<?php

/**
 * The AI crawlers robots.txt names, by what they do (D11). Keep each list sorted; add new crawlers as their makers
 * publish them.
 *
 * - `training`: crawlers that collect pages to train AI models. The `search_only` policy blocks these.
 * - `search`: crawlers and agents that fetch pages to answer people's questions in AI search and assistants, with a
 *   link back. Only the `block_all` policy blocks these too.
 */

declare(strict_types=1);

return [
    'training' => [
        'Amazonbot',
        'anthropic-ai',
        'Applebot-Extended',
        'Bytespider',
        'CCBot',
        'ClaudeBot',
        'cohere-training-data-crawler',
        'Diffbot',
        'Google-Extended',
        'GPTBot',
        'Meta-ExternalAgent',
        'Omgilibot',
        'Timpibot',
    ],
    'search' => [
        'ChatGPT-User',
        'Claude-SearchBot',
        'Claude-User',
        'DuckAssistBot',
        'Meta-ExternalFetcher',
        'MistralAI-User',
        'OAI-SearchBot',
        'Perplexity-User',
        'PerplexityBot',
    ],
];
