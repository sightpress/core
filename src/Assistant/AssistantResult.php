<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\TokenUsage;

/**
 * A suggestion for a person to insert, copy or discard, with what it cost and how much of the budget is used now.
 */
final readonly class AssistantResult
{
    public function __construct(
        public AssistantSuggestion $suggestion,
        public TokenUsage $usage,
        public bool $truncated,
        public BudgetStatus $budget,
    ) {
    }
}
