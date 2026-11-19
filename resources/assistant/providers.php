<?php

declare(strict_types=1);

/*
 * Models offered for each AI assistant provider core ships (ADR-0010, D15). Maintained by hand: when a provider adds
 * or retires a model, change this file (a `fix:` release), not the code. None is preselected; the admin chooses.
 *
 * Each model: id => [label, vision (whether it can describe pictures, for alt text suggestions)].
 * "openai_compatible" has no list: its admin types the model their server offers.
 */

return [
    'anthropic' => [
        'claude-opus-5-5' => ['Claude Opus 5.5: very capable, good for most writing', true],
        'claude-sonnet-5-5' => ['Claude Sonnet 5.5: fast and capable', true],
        'claude-haiku-5-5' => ['Claude Haiku 5.5: fastest and cheapest', true],
        'claude-fable-5-2' => ['Claude Fable 5.2: most capable, slower and dearer', true],
    ],
    'openai' => [
        'gpt-5' => ['GPT-5', true],
        'gpt-5-mini' => ['GPT-5 mini: faster and cheaper', true],
    ],
    'mistral' => [
        'mistral-large-latest' => ['Mistral Large', false],
        'mistral-medium-latest' => ['Mistral Medium', true],
        'mistral-small-latest' => ['Mistral Small: faster and cheaper', true],
    ],
];
