<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Menu\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\Entity\MenuItem;
use SightPress\Core\Menu\MenuException;

#[CoversClass(MenuItem::class)]
#[CoversClass(MenuName::class)]
#[UsesClass(MenuException::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
final class MenuItemTest extends TestCase
{
    public function testAnEntryIsAPageOrALinkWithALabel(): void
    {
        $page = new MenuItem(MenuName::Main, null, 4, "  Our\n team ", null);
        self::assertSame(MenuName::Main, $page->menu());
        self::assertSame(4, $page->pageId());
        self::assertSame('Our team', $page->label());
        self::assertNull($page->url());
        self::assertNull($page->id());
        self::assertNull($page->parent());
        self::assertSame(0, $page->position());

        $link = new MenuItem(MenuName::Footer, $page, null, 'Hours', ' /hours ');
        self::assertSame('/hours', $link->url());
        self::assertSame($page, $link->parent());
        self::assertNull(new MenuItem(MenuName::Main, null, 4, '  ', '')->label());

        $link->place(null, 3);
        self::assertNull($link->parent());
        self::assertSame(3, $link->position());
        self::assertSame('Main menu', MenuName::Main->label());
        self::assertSame('Footer menu', MenuName::Footer->label());
    }

    public function testAnEntryHasItsTextInEachLanguageAndMayBeForMembersOnly(): void
    {
        $page = new MenuItem(MenuName::Main, null, 4, null, null);
        self::assertSame([], $page->labels());
        self::assertFalse($page->isMembersOnly());
        self::assertNull($page->labelIn('pt-PT', 'en-GB'), 'the page title then');

        $page->describe(' Our team ', ['pt-PT' => " A nossa\n equipa ", 'es' => '  ', 'de' => null], true);
        self::assertSame('Our team', $page->label());
        self::assertSame(['pt-PT' => 'A nossa equipa'], $page->labels());
        self::assertTrue($page->isMembersOnly());
        self::assertSame('Our team', $page->labelIn('en-GB', 'en-GB'));
        self::assertSame('A nossa equipa', $page->labelIn('pt-PT', 'en-GB'));
        self::assertNull($page->labelIn('es', 'en-GB'));

        $page->describe(null, [], false);
        self::assertNull($page->label());
        self::assertSame([], $page->labels());

        $link = new MenuItem(MenuName::Footer, null, null, 'Hours', '/hours');
        self::assertSame('Hours', $link->labelIn('es', 'en-GB'), 'a link falls back to its main text');
        foreach ([[null, 'Give the link a label.'], [str_repeat('x', 101), 'The label must be 100 characters or fewer.']] as [$label, $message]) {
            try {
                $link->describe($label, [], false);
                self::fail('Accepted ' . var_export($label, true));
            } catch (MenuException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
        $this->expectException(MenuException::class);
        $link->describe('Hours', ['pt-PT' => str_repeat('x', 101)], false);
    }

    #[DataProvider('links')]
    public function testLinksMustBeSafeAddresses(string $url, bool $valid): void
    {
        if (!$valid) {
            $this->expectExceptionMessage('The address must start with https://, http://, mailto:, tel: or / (an address on this site).');
        }

        self::assertSame($url, new MenuItem(MenuName::Main, null, null, 'Link', $url)->url());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function links(): iterable
    {
        yield 'https' => ['https://example.org/a?b=c', true];
        yield 'http' => ['http://example.org', true];
        yield 'mailto' => ['mailto:office@example.org', true];
        yield 'tel' => ['tel:+44 20 7946 0000', true];
        yield 'site' => ['/events/2026', true];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'protocol-relative' => ['//evil.example', false];
        yield 'space' => ['/a b', false];
        yield 'relative' => ['events', false];
        yield 'too long' => ['/' . str_repeat('a', 500), false];
    }

    public function testRefusesEntriesThatAreNeitherOrBothOrUnlabelled(): void
    {
        foreach ([
            [null, 'Label', null, 'A menu entry is either a page or a link.'],
            [3, 'Label', '/x', 'A menu entry is either a page or a link.'],
            [null, null, '/x', 'Give the link a label.'],
            [3, str_repeat('a', 101), null, 'The label must be 100 characters or fewer.'],
        ] as [$page, $label, $url, $message]) {
            try {
                new MenuItem(MenuName::Main, null, $page, $label, $url);
                self::fail('Expected: ' . $message);
            } catch (MenuException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }
}
