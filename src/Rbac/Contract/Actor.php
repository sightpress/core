<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is making the request: a signed-in user with the permissions their roles give them on this site, or an
 * anonymous visitor (no user, no permissions). Sign-in puts it on the request.
 */
final readonly class Actor
{
    /** @var array<string, true> */
    private array $permissions;

    /**
     * @param list<string> $permissions permission names, for example `page.publish`
     */
    public function __construct(
        public ?int $userId,
        array $permissions = [],
    ) {
        $this->permissions = $userId === null ? [] : array_fill_keys($permissions, true);
    }

    public static function anonymous(): self
    {
        return new self(null);
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $actor = $request->getAttribute(self::class);

        return $actor instanceof self ? $actor : self::anonymous();
    }

    public function isSignedIn(): bool
    {
        return $this->userId !== null;
    }

    public function has(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }

    /**
     * @return list<string> sorted
     */
    public function permissions(): array
    {
        $names = array_keys($this->permissions);
        sort($names);

        return $names;
    }

    public function is(?int $userId): bool
    {
        return $userId !== null && $this->userId === $userId;
    }
}
