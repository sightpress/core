<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Template\ViewerPreferences;

#[CoversClass(Viewer::class)]
#[UsesClass(ViewerPreferences::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesNamespace('SightPress\Core\Infrastructure\Crypto')]
final class ViewerTest extends TestCase
{
    public function testOnASiteTheDefaultsAreTheSitesAndEmailsAreInTheRecipientsLanguage(): void
    {
        $site = \SightPress\Core\Tests\Support\PageFixture::site(['pt-PT', 'en-GB']);
        $context = new \SightPress\Core\Tenant\SiteContext($site, new \SightPress\Core\Tests\Support\ArrayContainer());
        $request = new ServerRequest('GET', '/')->withAttribute(\SightPress\Core\Tenant\SiteContext::class, $context);
        $default = new Viewer('en-GB', 'UTC');

        self::assertEquals(new Viewer('pt-PT', 'Europe/London'), Viewer::fromRequest($request, $default));
        self::assertEquals(new Viewer('es', 'Europe/London'), Viewer::fromRequest($request->withAttribute(\SightPress\Core\Template\ViewerPreferences::class, new \SightPress\Core\Template\ViewerPreferences('es')), $default));
        $displayed = new \SightPress\Core\Tenant\SiteContext($site, new \SightPress\Core\Tests\Support\ArrayContainer([\SightPress\Core\Tenant\Site::class => \SightPress\Core\Tests\Support\PageFixture::site(['es'])]));
        self::assertSame('es', Viewer::fromRequest($request->withAttribute(\SightPress\Core\Tenant\SiteContext::class, $displayed), $default)->locale, 'the site as its admins set it up');
        self::assertEquals(new Viewer('pt-PT', 'Europe/London'), Viewer::recipient(null, $site));
        self::assertEquals(new Viewer('es', 'Asia/Tokyo'), Viewer::recipient(new \SightPress\Core\Template\ViewerPreferences('es', 'Asia/Tokyo'), $site));
        self::assertEquals(new Viewer('pt-PT', 'Europe/London'), Viewer::recipient(new \SightPress\Core\Template\ViewerPreferences('', ''), $site));
    }
}
