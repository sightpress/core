<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\Contract\PageSubject;
use SightPress\Core\Rbac\CorePermissions;
use SightPress\Core\Rbac\PageVoter;
use SightPress\Core\Rbac\RoleName;
use SightPress\Core\Rbac\VoterAuthorizer;
use SightPress\Module\Access\Permission;
use stdClass;

/**
 * The full permission matrix for pages: every seeded role, as author and as someone else, against every action
 * in every state. Each row lists the states where the action is allowed; every other state is refused.
 */
#[CoversClass(PageVoter::class)]
#[CoversClass(VoterAuthorizer::class)]
#[UsesClass(Actor::class)]
#[UsesClass(CorePermissions::class)]
#[UsesClass(RoleName::class)]
final class PageVoterTest extends TestCase
{
    private const int AUTHOR = 7;
    private const int OTHER = 8;

    private const array LIVE = ['draft', 'in_review', 'approved', 'published'];
    private const array ALL = ['draft', 'in_review', 'approved', 'published', 'archived', 'trash'];

    /**
     * role, whose page (`own` / `other`), action => states where it is allowed.
     *
     * @return array<string, array<string, list<string>>>
     */
    private static function matrix(): array
    {
        $editor = [
            'page.view' => self::ALL,
            'page.edit' => self::LIVE,
            'page.submit' => ['draft'],
            'page.reject' => ['in_review'],
            'page.approve' => ['in_review'],
            'page.publish' => ['approved'],
            'page.unpublish' => ['published'],
            'page.archive' => self::LIVE,
            'page.restore' => ['archived'],
            'page.trash' => ['draft', 'in_review', 'approved', 'published', 'archived'],
            'page.untrash' => ['trash'],
            'page.purge' => ['trash'],
        ];
        $reader = ['page.view' => ['published']];

        return [
            'admin own' => $editor,
            'admin other' => $editor,
            'editor own' => $editor,
            'editor other' => $editor,
            'contributor own' => [
                'page.view' => ['draft', 'in_review', 'approved', 'published', 'archived'],
                'page.edit' => ['draft'],
                'page.submit' => ['draft'],
            ],
            'contributor other' => $reader,
            'member own' => $reader,
            'member other' => $reader,
            'anonymous other' => $reader,
        ];
    }

    /**
     * @return iterable<string, array{string, string, string, string, bool}>
     */
    public static function cases(): iterable
    {
        foreach (self::matrix() as $who => $allowed) {
            [$role, $whose] = explode(' ', $who);
            foreach (PageAction::cases() as $action) {
                foreach (self::ALL as $state) {
                    yield sprintf('%s %s %s', $who, $action->value, $state) => [
                        $role,
                        $whose,
                        $action->value,
                        $state,
                        in_array($state, $allowed[$action->value] ?? [], true),
                    ];
                }
            }
        }
    }

    #[DataProvider('cases')]
    public function testTheMatrixOnAPublicPage(string $role, string $whose, string $action, string $state, bool $expected): void
    {
        $authorizer = new VoterAuthorizer([new PageVoter()]);
        $page = $this->page(PageState::from($state), $whose === 'own' ? self::AUTHOR : self::OTHER, public: true);

        self::assertSame($expected, $authorizer->can($this->actor($role), $action, $page));
    }

    public function testAMembersOnlyPageNeedsASignedInAccount(): void
    {
        $voter = new PageVoter();
        $page = $this->page(PageState::Published, self::OTHER, public: false);

        self::assertFalse($voter->vote(Actor::anonymous(), 'page.view', $page));
        self::assertTrue($voter->vote($this->actor('member'), 'page.view', $page));
    }

    public function testAPageWithoutAuthorBelongsToNobody(): void
    {
        $voter = new PageVoter();
        $page = $this->page(PageState::Draft, null, public: true);

        self::assertFalse($voter->vote($this->actor('contributor'), 'page.edit', $page));
        self::assertTrue($voter->vote($this->actor('editor'), 'page.edit', $page));
    }

    public function testOnlyPageActionsOnPagesAreSupported(): void
    {
        $voter = new PageVoter();
        $page = $this->page(PageState::Published, self::OTHER, public: true);

        self::assertTrue($voter->supports('page.view', $page));
        self::assertFalse($voter->supports('menu.manage', $page));
        self::assertFalse($voter->supports('page.view', new stdClass()));
        self::assertFalse($voter->vote($this->actor('admin'), 'menu.manage', $page));
        self::assertFalse($voter->vote($this->actor('admin'), 'page.view', new stdClass()));
    }

    public function testWithoutASubjectThePermissionDecidesAndUnknownSubjectsAreRefused(): void
    {
        $authorizer = new VoterAuthorizer([new PageVoter()]);

        self::assertTrue($authorizer->can($this->actor('editor'), 'menu.manage'));
        self::assertFalse($authorizer->can($this->actor('contributor'), 'menu.manage'));
        self::assertFalse($authorizer->can($this->actor('admin'), 'page.view', new stdClass()));
    }

    private function actor(string $role): Actor
    {
        if ($role === 'anonymous') {
            return Actor::anonymous();
        }

        $permissions = array_values(array_map(
            static fn (Permission $permission): string => $permission->name,
            array_filter(
                CorePermissions::all(),
                static fn (Permission $permission): bool => $role === RoleName::ADMIN || in_array($role, $permission->defaultRoles, true),
            ),
        ));

        return new Actor(self::AUTHOR, $permissions);
    }

    private function page(PageState $state, ?int $author, bool $public): PageSubject
    {
        return new readonly class ($state, $author, $public) implements PageSubject {
            public function __construct(private PageState $state, private ?int $author, private bool $public)
            {
            }

            public function authorId(): ?int
            {
                return $this->author;
            }

            public function state(): PageState
            {
                return $this->state;
            }

            public function isPublic(): bool
            {
                return $this->public;
            }
        };
    }
}
