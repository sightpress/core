<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

/**
 * Makes a piece of work take at least a fixed time, so a page answers just as fast whether or not it sent an email
 * (no account enumeration by timing).
 */
final readonly class EvenTiming
{
    /**
     * @param int<0, max> $microseconds the least time the work takes
     */
    public function __construct(private int $microseconds = 750_000)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed
    {
        $start = hrtime(true);
        try {
            return $work();
        } finally {
            $left = $this->microseconds - intdiv(hrtime(true) - $start, 1000);
            if ($left > 0) {
                usleep($left);
            }
        }
    }
}
