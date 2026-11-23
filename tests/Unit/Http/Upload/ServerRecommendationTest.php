<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Upload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Upload\ServerRecommendation;
use SightPress\Core\Media\MediaLimits;

#[CoversClass(ServerRecommendation::class)]
#[UsesNamespace('SightPress\Core\Http\Upload')]
#[UsesNamespace('SightPress\Core\Media')]
final class ServerRecommendationTest extends TestCase
{
    private const int MB = 1024 * 1024;

    public function testMatchesTheLargestSiteWithoutChunkedUploads(): void
    {
        $lines = new ServerRecommendation([
            new MediaLimits(20 * self::MB, 100 * self::MB, 32 * self::MB, 20 * self::MB),
            new MediaLimits(20 * self::MB, 32 * self::MB, 500 * self::MB, 50 * self::MB, 50_000_000),
        ])->lines(false);

        foreach (['UPLOAD_MAX_SIZE=500M', 'PHP_MEMORY_LIMIT=512M', 'upload_max_filesize = 500M', 'post_max_size = 501M', 'memory_limit = 512M', 'client_max_body_size 501M;', 'LimitRequestBody 525336576', 'request_body { max_size 501MiB }'] as $line) {
            self::assertContains($line, $lines);
        }
    }

    public function testWithChunkedUploadsOneRequestCarriesOnePart(): void
    {
        $recommendation = new ServerRecommendation([new MediaLimits()]);
        $lines = $recommendation->lines(true);

        self::assertSame(1024 * self::MB, $recommendation->largestFile);
        foreach (['UPLOAD_MAX_SIZE=16M', 'PHP_MEMORY_LIMIT=256M', 'post_max_size = 17M', 'client_max_body_size 17M;'] as $line) {
            self::assertContains($line, $lines);
        }
        self::assertContains('UPLOAD_MAX_SIZE=1G', $recommendation->lines(false));
        self::assertContains('client_max_body_size 1025M;', $recommendation->lines(false));
    }
}
