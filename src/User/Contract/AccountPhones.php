<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

/**
 * Mobile numbers on accounts (N-3, ADR-0070). Only a number whose code was typed is stored, and one number belongs to
 * at most one account of the site.
 */
interface AccountPhones
{
    public function phoneOf(int $accountId): ?AccountPhone;

    /**
     * The account that confirmed this number (E.164), or null.
     */
    public function accountWithPhone(string $e164): ?int;

    /**
     * Gives the account this confirmed number, replacing its old one at once.
     *
     * @throws AccountException when another account of the site already has it
     */
    public function confirmPhone(int $accountId, string $e164, ?string $lookup): void;

    /**
     * @return bool false when the account had no number
     */
    public function removePhone(int $accountId): bool;

    /**
     * "Send me text messages" ticked (with these notifications) or unticked.
     *
     * @param list<string> $notifications
     */
    public function chooseTexts(int $accountId, bool $wanted, array $notifications): void;

    /**
     * The number replied STOP or START: the account that has it stops (or starts again) getting texts.
     *
     * @return int|null the account, or null when no account has the number
     */
    public function textReply(string $e164, bool $stopped): ?int;
}
