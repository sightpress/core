<?php

declare(strict_types=1);

namespace SightPress\Core\People\Http;

use Collator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\People\Invitations;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\RoleGrants;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\AccountDirectory;
use SightPress\Core\User\Contract\AccountRoster;
use SightPress\Core\User\Contract\AccountSummary;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/users`: everyone with an account, as a data table that can be sorted by each column (the headers are
 * links; the sorted one has `aria-sort` and the caption says how it is sorted), and the invitations that haven't
 * been accepted yet. Needs `user.manage`.
 */
final readonly class UsersHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/users';
    public const string PERMISSION = 'user.manage';

    /** Sortable columns: query value => header. */
    public const array COLUMNS = [
        'name' => 'Name',
        'full_name' => 'Full name',
        'email' => 'Email address',
        'role' => 'Role',
        'status' => 'Status',
    ];

    public const array STATUSES = [
        AccountSummary::ACTIVE => 'Active',
        AccountSummary::PENDING => 'Hasn’t set a password yet',
        AccountSummary::DEACTIVATED => 'Deactivated',
        AccountSummary::UNVERIFIED => 'Hasn’t confirmed their email address yet',
    ];

    public function __construct(
        private AdminScreens $screens,
        private AccountRoster $accounts,
        private AccountDirectory $directory,
        private RoleGrants $roles,
        private Invitations $invitations,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(self::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $query = $request->getQueryParams();
        $sort = is_string($query['sort'] ?? null) && isset(self::COLUMNS[$query['sort']]) ? $query['sort'] : 'name';
        $order = ($query['order'] ?? null) === 'descending' ? 'descending' : 'ascending';

        $summaries = $this->accounts->summaries();
        $roles = $this->roles->rolesOf(array_map(static fn (AccountSummary $account): int => $account->id, $summaries));
        $rows = array_map(static fn (AccountSummary $account): array => [
            'account' => $account,
            'role' => isset($roles[$account->id]) ? $roles[$account->id]->label : 'No role',
            'status' => self::STATUSES[$account->status] ?? $account->status,
        ], $summaries);
        $collator = new Collator($this->site->defaultLocale);
        usort($rows, static function (array $a, array $b) use ($collator, $sort, $order): int {
            $result = (int) $collator->compare(self::sortKey($a, $sort), self::sortKey($b, $sort));
            $result = $result === 0 ? $a['account']->id <=> $b['account']->id : $result;

            return $order === 'descending' ? -$result : $result;
        });

        $open = $this->invitations->open();
        $inviters = [];
        foreach ($this->directory->findAccounts(array_values(array_filter(array_map(static fn ($invitation): ?int => $invitation->invitedBy, $open), static fn (?int $id): bool => $id !== null))) as $inviter) {
            $inviters[(int) $inviter->id()] = $inviter->effectiveName();
        }
        $invitations = array_map(fn ($invitation): array => [
            'invitation' => $invitation,
            'role' => $this->roles->find($invitation->role)->label ?? $invitation->role,
            'by' => $invitation->invitedBy === null ? null : ($inviters[$invitation->invitedBy] ?? null),
        ], $open);

        return $this->screens->render($request, '@people/users.html.twig', [
            'rows' => $rows,
            'columns' => self::COLUMNS,
            'sort' => $sort,
            'order' => $order,
            'invitations' => $invitations,
            'can_invite' => $actor->has(InviteHandler::PERMISSION),
        ]);
    }

    /**
     * @param array{account: AccountSummary, role: string, status: string} $row
     */
    private static function sortKey(array $row, string $sort): string
    {
        return match ($sort) {
            'full_name' => $row['account']->fullName,
            'email' => $row['account']->email,
            'role' => $row['role'],
            'status' => $row['status'],
            default => $row['account']->effectiveName,
        };
    }
}
