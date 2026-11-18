<?php

declare(strict_types=1);

namespace SightPress\Core\User;

use SightPress\Core\Template\Message;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\Contract\AccountChanged;
use SightPress\Core\User\Contract\AccountException;
use SightPress\Core\User\Contract\AccountRoster;
use SightPress\Core\User\Contract\AccountSummary;
use SightPress\Core\User\Entity\User;

final readonly class AccountAdministration implements AccountRoster
{
    public function __construct(
        private UserRepository $users,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
    ) {
    }

    public function summaries(): array
    {
        return array_map(self::summarize(...), $this->users->all());
    }

    public function summary(int $id): ?AccountSummary
    {
        $user = $this->users->find($id);

        return $user === null ? null : self::summarize($user);
    }

    public function deactivate(int $id, ?int $changedBy): void
    {
        $user = $this->user($id);
        $user->deactivate($this->clock->now());
        $this->saved($user, $changedBy);
    }

    public function reactivate(int $id, ?int $changedBy): void
    {
        $user = $this->user($id);
        $user->reactivate($this->clock->now());
        $this->saved($user, $changedBy);
    }

    private function user(int $id): User
    {
        return $this->users->find($id) ?? throw new AccountException(new Message('There is no account {id}.', ['id' => (string) $id]));
    }

    private function saved(User $user, ?int $changedBy): void
    {
        $this->users->save($user);
        $this->events->dispatch(new AccountChanged((int) $user->id(), $changedBy, ['status']));
    }

    private static function summarize(User $user): AccountSummary
    {
        return new AccountSummary(
            (int) $user->id(),
            $user->email(),
            $user->effectiveName(),
            $user->fullName(),
            $user->status()->value,
            AvatarService::url($user, 64),
        );
    }
}
