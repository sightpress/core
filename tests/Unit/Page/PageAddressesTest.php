<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageException;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(PageAddresses::class)]
#[UsesNamespace('SightPress\Core')]
final class PageAddressesTest extends TestCase
{
    public function testSplitsAddressesIntoLocaleAndPath(): void
    {
        $addresses = new PageFixture()->addresses();

        self::assertSame(['en-GB', ''], $addresses->split('/'));
        self::assertSame(['en-GB', 'about/team'], $addresses->split('about/team/?q=1#x'));
        self::assertSame(['pt-PT', ''], $addresses->split('/pt-pt'));
        self::assertSame(['pt-PT', 'sobre'], $addresses->split('/pt-pt/sobre/'));
        self::assertSame(['en-GB', 'pt-ptx/sobre'], $addresses->split('/pt-ptx/sobre'));
    }

    public function testTheHomePageIsTheChosenOneOrTheFirstAtTheTop(): void
    {
        $fixture = new PageFixture();
        $addresses = $fixture->addresses();
        self::assertNull($addresses->home());
        self::assertNull($addresses->find('/'));

        $welcome = $fixture->published('Welcome');
        $about = $fixture->published('About');
        $fixture->translated($about, 'pt-PT', new PageContent('Sobre', 'sobre'));
        $addresses = $fixture->addresses();
        self::assertSame($welcome, $addresses->home());
        self::assertTrue($addresses->isHome($welcome));
        self::assertSame('/', $addresses->url($welcome->translation('en-GB') ?? self::fail()));
        self::assertSame('/about', $addresses->url($about->translation('en-GB') ?? self::fail()));
        self::assertSame('/pt-pt/sobre', $addresses->url($about->translation('pt-PT') ?? self::fail()));

        $addresses->setHome($about);
        self::assertSame($about, $addresses->home());
        self::assertSame('/pt-pt', $addresses->url($about->translation('pt-PT') ?? self::fail()));
        self::assertSame($about->translation('pt-PT'), $fixture->addresses()->find('/pt-pt'), 'kept in the site settings');
        self::assertSame($welcome->translation('en-GB'), $fixture->addresses()->find('/welcome'));
        self::assertNull($fixture->addresses()->find('/pt-pt/missing'));
        self::assertFalse($addresses->isHome(new Page(null, 0, null, new DateTimeImmutable())));

        $fixture->database->settings()->set(PageAddresses::HOME_SETTING, 'none');
        self::assertSame($welcome, $fixture->addresses()->home(), 'a broken setting falls back to the first page');
    }

    public function testOnlySavedPagesCanBeTheHomePage(): void
    {
        $this->expectException(PageException::class);

        new PageFixture()->addresses()->setHome(new Page(null, 0, null, new DateTimeImmutable()));
    }

    public function testFindsAPageInALanguageAndWritesTheLanguagePart(): void
    {
        $fixture = new PageFixture();
        $home = $fixture->published('Welcome');
        $about = $fixture->published('About');
        $fixture->translated($about, 'pt-PT', new PageContent('Sobre', 'sobre'));
        $addresses = $fixture->addresses();

        self::assertSame($home, $addresses->findIn('en-GB', '')?->page());
        self::assertSame('Sobre', $addresses->findIn('pt-PT', 'sobre')?->title());
        self::assertNull($addresses->findIn('pt-PT', 'about'));
        self::assertSame(['', '/pt-pt'], [$addresses->prefixOf('en-GB'), $addresses->prefixOf('pt-PT')]);
    }
}
