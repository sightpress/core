<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SightPress\Core\Http\AfterResponse;

#[CoversClass(AfterResponse::class)]
final class AfterResponseTest extends TestCase
{
    public function testRunsTheWorkOnceAfterLettingTheVisitorGoAndLogsFailures(): void
    {
        $log = new TestHandler();
        $after = new AfterResponse(new Logger('test', [$log]));
        $done = [];
        $after->add(static function () use (&$done): void {
            $done[] = 'released? ' . (isset($done[0]) ? 'yes' : 'no');
        });
        $after->add(static fn () => throw new RuntimeException('database gone'));
        $after->add(static function () use (&$done): void {
            $done[] = 'still runs';
        });

        $after->run(static function () use (&$done): void {
            $done[] = 'released';
        });
        $after->run(static function () use (&$done): void {
            $done[] = 'released with nothing to do';
        });

        self::assertSame(['released', 'released? yes', 'still runs'], $done);
        self::assertTrue($log->hasErrorThatContains('Work after the response failed'));
        self::assertSame(['class' => RuntimeException::class, 'message' => 'database gone'], $log->getRecords()[0]->context);
    }
}
