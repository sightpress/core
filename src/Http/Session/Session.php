<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The visitor's session for one request. It holds plain values (scalars and arrays) and only starts — sets a cookie
 * — once something is stored, so anonymous visitors of public pages never get one.
 *
 * Call {@see regenerate()} whenever someone signs in or their rights change, and {@see invalidate()} when they sign
 * out, so an old session id can't be reused.
 */
final class Session
{
    private ?string $previousId = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private ?string $id = null,
        private array $data = [],
    ) {
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $session = $request->getAttribute(self::class);
        if (!$session instanceof self) {
            throw new LogicException('There is no session: the session middleware must run before this code.');
        }

        return $session;
    }

    /**
     * The session id, or null while the session hasn't started or right after {@see regenerate()}.
     */
    public function id(): ?string
    {
        return $this->id;
    }

    /**
     * The current id, created now if there is none yet.
     */
    public function idOrNew(): string
    {
        return $this->id ??= rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * @param scalar|array<mixed>|null $value
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * A list stored under a key that other code changes in place (for example the CSRF tokens).
     *
     * @return array<mixed>
     */
    public function &bag(string $key): array
    {
        if (!is_array($this->data[$key] ?? null)) {
            $this->data[$key] = [];
        }

        $bag = &$this->data[$key];

        return $bag;
    }

    /**
     * Gives the session a new id and keeps its data; the old id stops working.
     */
    public function regenerate(): void
    {
        $this->previousId ??= $this->id;
        $this->id = null;
    }

    /**
     * Forgets everything and gives the session a new id (for example on sign-out).
     */
    public function invalidate(): void
    {
        $this->data = [];
        $this->regenerate();
    }

    /**
     * The id that {@see regenerate()} replaced, which must be deleted from the store.
     */
    public function previousId(): ?string
    {
        return $this->previousId;
    }
}
