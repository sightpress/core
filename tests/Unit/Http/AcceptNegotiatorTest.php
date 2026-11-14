<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\AcceptNegotiator;

#[CoversClass(AcceptNegotiator::class)]
final class AcceptNegotiatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function headers(): iterable
    {
        yield 'none' => ['', false];
        yield 'browser' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', false];
        yield 'json' => ['application/json', true];
        yield 'problem json' => ['application/problem+json', true];
        yield 'tie goes to html' => ['application/json, text/html', false];
        yield 'json preferred by quality' => ['text/html;q=0.5, application/json', true];
        yield 'html preferred by quality' => ['application/json;q=0.4, application/xhtml+xml;q=0.9', false];
        yield 'json with spaces in q' => ['APPLICATION/JSON ; q = 0.7', true];
        yield 'anything' => ['*/*', false];
    }

    #[DataProvider('headers')]
    public function testItPrefersJsonOnlyWhenAskedForIt(string $accept, bool $expected): void
    {
        $request = new ServerRequest('GET', '/', $accept === '' ? [] : ['Accept' => $accept]);

        self::assertSame($expected, new AcceptNegotiator()->prefersJson($request));
    }
}
