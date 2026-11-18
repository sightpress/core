<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Contract\RedirectChanged;
use SightPress\Core\Page\Entity\PageRedirect;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\PageRedirects;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(PageRedirects::class)]
#[CoversClass(PageRedirect::class)]
#[CoversClass(RedirectChanged::class)]
#[UsesNamespace('SightPress\Core')]
final class PageRedirectsTest extends TestCase
{
    private PageFixture $fixture;

    private PageRedirects $redirects;

    protected function setUp(): void
    {
        $this->fixture = new PageFixture();
        $this->redirects = $this->fixture->redirects();
    }

    public function testMovesLeaveOneHopRedirectsAndTakenAddressesWin(): void
    {
        $this->redirects->moved('en-GB', 'a', 'b', true);
        $this->redirects->moved('en-GB', 'b', 'c', true);
        $this->redirects->moved('en-GB', 'draft', 'elsewhere', false);

        self::assertSame(['a' => 'c', 'b' => 'c'], $this->map());
        $this->redirects->moved('en-GB', 'c', 'b', true);
        self::assertSame(['a' => 'b', 'c' => 'b'], $this->map(), 'b is taken again, so its redirect goes');
        $this->redirects->claim('en-GB', 'a');
        self::assertSame(['c' => 'b'], $this->map());
        self::assertSame([], $this->fixture->events, 'moves are part of the page change');
    }

    public function testDeletedPagesAreGoneAndSoAreTheRedirectsToThem(): void
    {
        $this->redirects->moved('en-GB', 'a', 'b', true);
        $this->redirects->gone('en-GB', 'b');

        self::assertSame(['a' => null, 'b' => null], $this->map());
        self::assertTrue($this->redirects->find('en-GB', 'a')?->isGone());
        $this->redirects->gone('en-GB', 'b');
        self::assertCount(2, $this->redirects->all());
    }

    public function testPeopleAddRedirectsThatNeverLoopNorHideAPage(): void
    {
        $this->fixture->published('News');
        $this->redirects->moved('en-GB', 'old-news', 'news', true);
        $this->redirects->gone('en-GB', 'deleted');
        $this->fixture->events = [];

        $added = $this->redirects->add('en-GB', '/blog/', '/old-news', 4);
        self::assertSame(['blog', 'news'], [$added->source(), $added->target()], 'the target is followed to its end');
        self::assertEquals([new RedirectChanged(RedirectChanged::ADDED, 'en-GB', 'blog', 'news', 4)], $this->fixture->events);

        $this->redirects->add('en-GB', 'diary', 'blog');
        $this->redirects->add('en-GB', 'journal', 'diary-2026');
        $this->redirects->add('en-GB', 'diary-2026', 'contact');
        self::assertSame(['blog' => 'news', 'deleted' => null, 'diary' => 'news', 'diary-2026' => 'contact', 'journal' => 'contact', 'old-news' => 'news'], $this->map(), 'what led to the old address leads to the new one');

        foreach ([
            ['news', 'blog', 'The page "News" uses the address /news. Only addresses no page uses can be redirected.'],
            ['x', 'deleted', 'The page that was at /deleted has been deleted. Choose an address that is in use.'],
            ['x', 'x', 'That redirect would go round in a loop: the new address leads back to the old one.'],
            ['contact', 'journal', 'That redirect would go round in a loop: the new address leads back to the old one.'],
        ] as [$from, $to, $message]) {
            try {
                $this->redirects->add('en-GB', $from, $to);
                self::fail('Expected ' . $from . ' → ' . $to . ' to be refused.');
            } catch (PageException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }

        $redirect = $this->redirects->find('en-GB', 'old-news');
        self::assertNotNull($redirect);
        self::assertSame($redirect, $this->redirects->get((int) $redirect->id()));
        $this->fixture->events = [];
        $this->redirects->remove($redirect, 4);
        self::assertNull($this->redirects->find('en-GB', 'old-news'));
        self::assertEquals([new RedirectChanged(RedirectChanged::REMOVED, 'en-GB', 'old-news', 'news', 4)], $this->fixture->events);
        self::assertNull($this->redirects->get(999));
    }

    public function testARedirectIsInALocaleAndNeverLeadsToItself(): void
    {
        $redirect = new PageRedirect('pt-PT', 'antigo', 'novo', $this->fixture->clock->now());
        self::assertSame(['pt-PT', 'antigo', 'novo', false, null], [$redirect->locale(), $redirect->source(), $redirect->target(), $redirect->isGone(), $redirect->id()]);
        self::assertEquals($this->fixture->clock->now(), $redirect->updatedAt());

        foreach ([
            static fn () => new PageRedirect('not a locale', 'a', 'b', new \DateTimeImmutable()),
            static fn () => new PageRedirect('en-GB', 'a', 'a', new \DateTimeImmutable()),
            static fn () => new PageRedirect('en-GB', str_repeat('a', 621), null, new \DateTimeImmutable()),
            static fn () => new PageRedirect('en-GB', 'a', str_repeat('b', 621), new \DateTimeImmutable()),
        ] as $make) {
            try {
                $make();
                self::fail('Expected the redirect to be refused.');
            } catch (PageException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    /**
     * @return array<string, ?string> old address => new one
     */
    private function map(): array
    {
        $map = [];
        foreach ($this->redirects->all() as $redirect) {
            $map[$redirect->source()] = $redirect->target();
        }

        return $map;
    }
}
