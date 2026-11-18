<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\RedirectedAddresses;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(RedirectedAddresses::class)]
#[UsesNamespace('SightPress\Core')]
final class RedirectedAddressesTest extends TestCase
{
    public function testFollowsOldAddressesOfTheSiteToWhereTheirPagesAreNow(): void
    {
        $pages = new PageFixture();
        $pages->published('Welcome');
        $about = $pages->published('About');
        $pages->translated($about, 'pt-PT', new PageContent('Sobre', 'sobre'));
        $pages->redirects()->add('en-GB', 'old', 'about');
        $pages->redirects()->add('pt-PT', 'antigo', 'sobre');
        $pages->redirects()->add('en-GB', 'nowhere-yet', 'planned');
        $pages->redirects()->gone('en-GB', 'closed');
        $addresses = new RedirectedAddresses($pages->addresses(), $pages->redirects());

        self::assertSame('/about', $addresses->follow('/old'));
        self::assertSame('/about?x=1#team', $addresses->follow('/old?x=1#team'));
        self::assertSame('/pt-pt/sobre', $addresses->follow('/pt-pt/antigo'));
        self::assertSame('/planned', $addresses->follow('/nowhere-yet'), 'no page there yet: the target as it is');
        self::assertNull($addresses->follow('/closed'));
        foreach (['/about', '/', '/unknown', 'https://example.org/old', '//example.org/old', 'mailto:a@example.org'] as $kept) {
            self::assertSame($kept, $addresses->follow($kept));
        }
    }
}
