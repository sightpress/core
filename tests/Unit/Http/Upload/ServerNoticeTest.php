<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Upload;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Upload\RequestTooLargeException;
use SightPress\Core\Http\Upload\RequestTooLargeHandler;
use SightPress\Core\Http\Upload\ServerNotice;
use SightPress\Core\Infrastructure\Clock\FrozenClock;
use SightPress\Core\Media\UploadRefusals;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\ServerLimitsFixture;
use Nyholm\Psr7\ServerRequest;

#[CoversClass(ServerNotice::class)]
#[CoversClass(UploadRefusals::class)]
#[CoversClass(RequestTooLargeHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class ServerNoticeTest extends TestCase
{
    private CoreSiteDatabase $database;

    protected function setUp(): void
    {
        $this->database = new CoreSiteDatabase();
    }

    public function testHiddenUntilTheResultChanges(): void
    {
        $notice = $this->notice(ServerLimitsFixture::standard(inputVars: 1000));
        self::assertCount(1, $notice->visible());

        $notice->hide();
        self::assertSame([], $notice->visible());
        self::assertCount(1, $notice->problems(), 'system:check still reports it.');

        self::assertCount(1, $this->notice(ServerLimitsFixture::standard(inputVars: 2000))->visible(), 'Another value is another result.');
    }

    public function testFreeSpaceAloneDoesNotBringItBack(): void
    {
        $low = fn (int $free): ServerNotice => $this->notice(ServerLimitsFixture::standard(tempFree: $free * ServerLimitsFixture::MB));
        $low(10)->hide();

        self::assertSame([], $low(9)->visible());
    }

    public function testNothingToShowWithoutProblems(): void
    {
        self::assertSame([], $this->notice(ServerLimitsFixture::standard())->visible());
    }

    public function testCountsRefusalsOfTheLastSevenDays(): void
    {
        $this->refusals('2026-11-10T12:00:00Z')->record();
        $this->refusals('2026-11-17T00:00:00Z')->record();
        $today = $this->refusals('2026-11-23T12:00:00Z');
        $today->record();
        $today->record();

        self::assertSame(3, $today->lastSevenDays());
        $problems = $this->notice(ServerLimitsFixture::standard())->problems();
        self::assertCount(1, $problems);
        self::assertStringStartsWith('3 uploads were refused by the web server', (string) $problems[0]);
    }

    public function testACountThatMovesDoesNotBringTheNoticeBack(): void
    {
        $notice = $this->notice(ServerLimitsFixture::standard());
        $this->refusals('2026-11-23T12:00:00Z')->record();
        $notice->hide();
        $this->refusals('2026-11-23T12:00:00Z')->record();

        self::assertSame([], $notice->visible());
    }

    public function testOldDaysAreRemoved(): void
    {
        $this->refusals('2026-10-01T12:00:00Z')->record();
        $this->refusals('2026-11-23T12:00:00Z')->record();

        self::assertEquals(1, $this->database->connection->fetchOne('SELECT COUNT(*) FROM ' . UploadRefusals::TABLE));
    }

    public function testTwoRefusalsAtOnceAreBothCounted(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturnOnConsecutiveCalls(0, 1);
        $connection->expects(self::once())->method('insert')->willThrowException(new UniqueConstraintViolationException(self::createStub(DriverException::class), null));

        new UploadRefusals($connection, self::clock('2026-11-23T12:00:00Z'))->record();
    }

    public function testCountingNeverGetsInTheWay(): void
    {
        $this->database->connection->executeStatement('DROP TABLE ' . UploadRefusals::TABLE);
        $refusals = $this->refusals('2026-11-23T12:00:00Z');

        $refusals->record();

        self::assertSame(0, $refusals->lastSevenDays());
    }

    public function testOnlyTheWebServerCanCountARefusal(): void
    {
        $handler = new RequestTooLargeHandler(ServerLimitsFixture::standard(), $this->refusals('2026-11-23T12:00:00Z'));
        $request = new ServerRequest('GET', RequestTooLargeHandler::PATH);
        foreach ([$request, new ServerRequest('GET', RequestTooLargeHandler::PATH, [], null, '1.1', [RequestTooLargeHandler::REFUSED_PARAM => '1'])] as $each) {
            try {
                $handler->handle($each);
                self::fail('Expected a 413.');
            } catch (RequestTooLargeException) {
                continue;
            }
        }

        self::assertSame(1, $this->refusals('2026-11-23T12:00:00Z')->lastSevenDays());
    }

    private function notice(\SightPress\Core\Http\Upload\ServerLimits $server): ServerNotice
    {
        return ServerLimitsFixture::notice($server, $this->database->connection, $this->database->settings(), self::clock('2026-11-23T12:00:00Z'));
    }

    private function refusals(string $now): UploadRefusals
    {
        return new UploadRefusals($this->database->connection, self::clock($now));
    }

    private static function clock(string $now): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable($now));
    }
}
