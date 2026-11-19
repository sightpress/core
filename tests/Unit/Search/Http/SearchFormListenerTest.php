<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Search\Http;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Search\Http\SearchFormListener;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(SearchFormListener::class)]
#[UsesNamespace('SightPress\Core')]
final class SearchFormListenerTest extends TestCase
{
    public function testTheHeaderFormSearchesInThePagesLanguage(): void
    {
        $listener = new SearchFormListener(PageFixture::site(['en-GB', 'pt-PT']));

        foreach (['en-GB' => '/search', 'pt-PT' => '/pt-pt/search', 'fr' => '/search'] as $locale => $action) {
            $event = new PublicPageRendering(new ServerRequest('GET', '/'), 1, $locale, '/', 'https://demo.example', 'Home', null, null, [], true, true, new DateTimeImmutable());
            $listener($event);
            self::assertSame($action, $event->variables()['search_action'] ?? null, $locale);
        }
    }
}
