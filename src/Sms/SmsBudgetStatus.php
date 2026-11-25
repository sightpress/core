<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

/**
 * How much of the site's monthly text message budget is used (N-2): parts sent this month (UTC) of the limit, with
 * {@see $reserved} parts that only security codes may use. 0 = no limit.
 */
final readonly class SmsBudgetStatus
{
    public const int WARN_AT = 80;

    /**
     * @param int<0, max> $used
     * @param int<0, max> $limit
     * @param int<0, max> $reserved
     */
    public function __construct(public int $used, public int $limit, public int $reserved = 0)
    {
    }

    public function hasLimit(): bool
    {
        return $this->limit > 0;
    }

    /**
     * @return int<0, max> per cent of the limit used, 0 without a limit
     */
    public function percent(): int
    {
        return $this->limit === 0 ? 0 : max(0, intdiv($this->used * 100, $this->limit));
    }

    public function isWarning(): bool
    {
        return $this->hasLimit() && $this->percent() >= self::WARN_AT;
    }

    public function isUsedUp(): bool
    {
        return $this->hasLimit() && $this->used >= $this->limit;
    }

    /**
     * Whether a message of `$parts` parts still fits: notifications must leave the reserved parts for codes.
     */
    public function allows(int $parts, bool $securityCode): bool
    {
        if (!$this->hasLimit()) {
            return true;
        }

        return $this->used + $parts <= $this->limit - ($securityCode ? 0 : $this->reserved);
    }
}
