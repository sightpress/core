<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Page\Contract\RedirectChanged;
use SightPress\Core\Page\Entity\PageRedirect;
use SightPress\Core\Template\Message;

/**
 * The redirects table (P-1, ADR-0027): old addresses of pages, per locale, and where they lead now.
 *
 * - When a page's address changes (it moved, or got a new slug) the old address leads to the new one (301), if
 *   visitors could read the page there; older addresses that led to the old one lead to the new one too, so a
 *   redirect is always one hop.
 * - When a page takes an address, a redirect from that address is removed: the page wins.
 * - When a page is deleted for good, its addresses answer "gone" (410), and so do the redirects that led to them.
 * - People can add redirects of their own (never from an address a page uses, never in a loop) and remove any.
 *
 * Every change is saved straight away.
 */
final readonly class PageRedirects
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PageRepository $pages,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @phpstan-impure
     */
    public function find(string $locale, string $path): ?PageRedirect
    {
        return $this->entityManager->getRepository(PageRedirect::class)->findOneBy(['locale' => $locale, 'source' => $path]);
    }

    public function get(int $id): ?PageRedirect
    {
        return $this->entityManager->find(PageRedirect::class, $id);
    }

    /**
     * @return list<PageRedirect> by locale, then old address
     */
    public function all(): array
    {
        return array_values($this->entityManager->getRepository(PageRedirect::class)->findBy([], ['locale' => 'ASC', 'source' => 'ASC']));
    }

    /**
     * A page's address in a locale changed from `$from` to `$to`.
     *
     * @param bool $public whether visitors could read the page at `$from`; only then does `$from` lead to `$to`
     */
    public function moved(string $locale, string $from, string $to, bool $public): void
    {
        $now = $this->clock->now();
        $this->drop($locale, $to);
        foreach ($this->leadingTo($locale, $from) as $redirect) {
            if ($redirect->source() !== $to) {
                $redirect->retarget($to, $now);
            }
        }
        if ($public) {
            $this->put($locale, $from, $to);
        }
        $this->entityManager->flush();
    }

    /**
     * A page took the address: a redirect from it is removed.
     */
    public function claim(string $locale, string $path): void
    {
        $this->drop($locale, $path);
        $this->entityManager->flush();
    }

    /**
     * The page at the address was deleted for good: the address, and every redirect that led to it, answer "gone".
     */
    public function gone(string $locale, string $path): void
    {
        $now = $this->clock->now();
        foreach ($this->leadingTo($locale, $path) as $redirect) {
            $redirect->retarget(null, $now);
        }
        $this->put($locale, $path, null);
        $this->entityManager->flush();
    }

    /**
     * Adds a redirect of one's own, or changes where an old address leads. A target that is itself redirected is
     * followed to its end, and redirects that led to `$from` now lead there too.
     *
     * @throws PageException when a page uses `$from`, the target is gone, or the redirect would go round in a loop
     */
    public function add(string $locale, string $from, string $to, ?int $addedBy = null): PageRedirect
    {
        $from = trim($from, '/');
        $to = trim($to, '/');
        $taken = $this->pages->findByPath($locale, $from);
        if ($taken !== null) {
            throw new PageException(new Message('The page "{title}" uses the address /{path}. Only addresses no page uses can be redirected.', ['title' => $taken->title(), 'path' => $from]));
        }
        $next = $this->find($locale, $to);
        if ($next !== null) {
            $to = $next->target() ?? throw new PageException(new Message('The page that was at /{path} has been deleted. Choose an address that is in use.', ['path' => $to]));
        }
        if ($to === $from) {
            throw new PageException('That redirect would go round in a loop: the new address leads back to the old one.');
        }
        $now = $this->clock->now();
        foreach ($this->leadingTo($locale, $from) as $redirect) {
            $redirect->retarget($to, $now);
        }
        $redirect = $this->put($locale, $from, $to);
        $this->entityManager->flush();
        $this->events->dispatch(new RedirectChanged(RedirectChanged::ADDED, $locale, $from, $to, $addedBy));

        return $redirect;
    }

    public function remove(PageRedirect $redirect, ?int $removedBy = null): void
    {
        $this->entityManager->remove($redirect);
        $this->entityManager->flush();
        $this->events->dispatch(new RedirectChanged(RedirectChanged::REMOVED, $redirect->locale(), $redirect->source(), $redirect->target(), $removedBy));
    }

    private function put(string $locale, string $from, ?string $to): PageRedirect
    {
        $redirect = $this->find($locale, $from);
        if ($redirect === null) {
            $redirect = new PageRedirect($locale, $from, $to, $this->clock->now());
            $this->entityManager->persist($redirect);
        } else {
            $redirect->retarget($to, $this->clock->now());
        }

        return $redirect;
    }

    private function drop(string $locale, string $path): void
    {
        $redirect = $this->find($locale, $path);
        if ($redirect !== null) {
            $this->entityManager->remove($redirect);
        }
    }

    /**
     * @return list<PageRedirect>
     */
    private function leadingTo(string $locale, string $path): array
    {
        return array_values($this->entityManager->getRepository(PageRedirect::class)->findBy(['locale' => $locale, 'target' => $path]));
    }
}
