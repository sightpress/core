<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

use Psr\Clock\ClockInterface;

/**
 * The site's monthly token budget (ADR-0010): how much of it this month (UTC) has used. From {@see WARN_AT} per cent
 * on, people are warned; at 100 % the assistant refuses until the next month or a bigger budget. 0 = no limit.
 */
final readonly class AssistantBudget
{
    public const int WARN_AT = 80;

    public function __construct(
        private AssistantUsageStore $usage,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param int<0, max> $limit
     */
    public function status(int $limit): BudgetStatus
    {
        return new BudgetStatus($this->usage->tokens($this->month()), $limit);
    }

    public function month(): string
    {
        return AssistantUse::monthOf($this->clock->now());
    }
}
