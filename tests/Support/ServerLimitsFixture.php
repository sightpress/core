<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use SightPress\Core\Http\Upload\ServerLimits;

/**
 * Server limits for tests: the skeleton's recommended *standard* values, PHP's own defaults, or anything else.
 */
final class ServerLimitsFixture
{
    public const int MB = 1024 * 1024;

    /**
     * `UPLOAD_MAX_SIZE` 64M, `INPUT_MAX_VARS` 5000, `PHP_MEMORY_LIMIT` 256M, nginx 65M (plan §1.2).
     */
    public static function standard(
        ?int $upload = 64 * self::MB,
        ?int $post = 65 * self::MB,
        ?int $memory = 256 * self::MB,
        int $inputVars = 5000,
        ?int $proxy = 65 * self::MB,
        bool $tempWritable = true,
        ?int $tempFree = 10240 * self::MB,
    ): ServerLimits {
        return new ServerLimits($upload, $post, $memory, 20, $inputVars, $proxy, '/tmp', $tempWritable, $tempFree);
    }

    /**
     * What PHP does with no php.ini: 2 MB files, 8 MB requests, 128 MB memory, 1000 fields; web server not stated.
     */
    public static function phpDefaults(): ServerLimits
    {
        return new ServerLimits(2 * self::MB, 8 * self::MB, 128 * self::MB, 20, 1000, null, '/tmp', true, 10240 * self::MB);
    }
}
