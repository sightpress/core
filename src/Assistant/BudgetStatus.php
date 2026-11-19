<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

/**
 * Tokens used this month against the budget (0 = no limit).
 */
final readonly class BudgetStatus
{
    /**
     * @param int<0, max> $used
     * @param int<0, max> $limit
     */
    public function __construct(public int $used, public int $limit)
    {
    }

    public function isLimited(): bool
    {
        return $this->limit > 0;
    }

    /**
     * Per cent of the budget used, rounded down; 0 without a limit.
     */
    public function percent(): int
    {
        return $this->isLimited() ? intdiv($this->used * 100, $this->limit) : 0;
    }

    public function isNearlyUsedUp(): bool
    {
        return $this->isLimited() && $this->percent() >= AssistantBudget::WARN_AT && !$this->isUsedUp();
    }

    public function isUsedUp(): bool
    {
        return $this->isLimited() && $this->used >= $this->limit;
    }
}
