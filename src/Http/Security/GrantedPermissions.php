<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Security;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The permissions the person making the request holds on this site. Authentication puts it on the request; an
 * anonymous visitor holds none.
 */
final readonly class GrantedPermissions
{
    /** @var array<string, true> */
    private array $names;

    /**
     * @param list<string> $names permission names, for example `page.publish`
     */
    public function __construct(array $names = [])
    {
        $this->names = array_fill_keys($names, true);
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $granted = $request->getAttribute(self::class);

        return $granted instanceof self ? $granted : new self();
    }

    public function has(string $permission): bool
    {
        return isset($this->names[$permission]);
    }
}
