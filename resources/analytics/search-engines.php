<?php

/**
 * Referrer host names analytics groups under one name as a source (ADR-0012): search engines, and AI assistants
 * that link to the pages they used. A host matches when it is the name or ends with `.` + the name; `*` stands for
 * any country ending (`google.*` matches `google.com`, `www.google.co.uk` and `google.pt`). AI assistants are
 * checked first, so `gemini.google.com` is Gemini, not Google.
 */

declare(strict_types=1);

return [
    'search' => [
        'Baidu' => ['baidu.com'],
        'Bing' => ['bing.com'],
        'Brave Search' => ['search.brave.com'],
        'DuckDuckGo' => ['duckduckgo.com'],
        'Ecosia' => ['ecosia.org'],
        'Google' => ['google.*'],
        'Kagi' => ['kagi.com'],
        'Mojeek' => ['mojeek.com'],
        'Naver' => ['search.naver.com'],
        'Qwant' => ['qwant.com'],
        'Seznam' => ['search.seznam.cz'],
        'Startpage' => ['startpage.com'],
        'Yahoo' => ['search.yahoo.com', 'yahoo.co.jp'],
        'Yandex' => ['yandex.*', 'ya.ru'],
    ],
    'ai' => [
        'ChatGPT' => ['chatgpt.com', 'chat.openai.com'],
        'Claude' => ['claude.ai'],
        'Copilot' => ['copilot.microsoft.com'],
        'Gemini' => ['gemini.google.com'],
        'Mistral Le Chat' => ['chat.mistral.ai'],
        'Perplexity' => ['perplexity.ai'],
    ],
];
