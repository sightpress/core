<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Schema\SchemaConstraints;
use SightPress\Core\Block\Schema\SchemaWalker;

#[CoversClass(SchemaWalker::class)]
#[UsesClass(SchemaConstraints::class)]
final class SchemaWalkerTest extends TestCase
{
    private const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'cover' => ['type' => 'integer', 'format' => 'media'],
            'html' => ['type' => 'string', 'contentMediaType' => 'text/html'],
            'unknown' => 'not a schema',
            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['file' => ['type' => 'integer', 'format' => 'media-video']]]],
            'a/b~' => ['type' => 'integer', 'format' => 'media-document'],
            'link' => ['type' => 'string', 'format' => 'link'],
        ],
    ];

    public function testFindsMediaIdsAnywhereInTheData(): void
    {
        $data = ['cover' => 4, 'items' => [['file' => 7], ['file' => 'x'], ['other' => 9]], 'unknown' => 5, 'extra' => 6];

        self::assertSame([4, 7], SchemaWalker::mediaIds(self::SCHEMA, $data));
        self::assertSame([], SchemaWalker::mediaIds(self::SCHEMA, ['items' => ['not' => 'a list']]));
        self::assertSame([], SchemaWalker::mediaIds(self::SCHEMA, []));
    }

    public function testSaysWhereEachFileIsAndWhatKindItMustBe(): void
    {
        $data = ['cover' => 4, 'items' => [['file' => 7], ['file' => 8]], 'a/b~' => 9, 'link' => '/x'];

        self::assertSame(
            [
                ['id' => 4, 'path' => '/cover', 'format' => 'media'],
                ['id' => 7, 'path' => '/items/0/file', 'format' => 'media-video'],
                ['id' => 8, 'path' => '/items/1/file', 'format' => 'media-video'],
                ['id' => 9, 'path' => '/a~1b~0', 'format' => 'media-document'],
            ],
            SchemaWalker::mediaReferences(self::SCHEMA, $data),
        );
    }

    public function testChangesValuesOnTheWay(): void
    {
        $result = SchemaWalker::walk(self::SCHEMA, ['html' => '<b>x</b>', 'cover' => 1], static fn (array $schema, mixed $value): mixed => ($schema['contentMediaType'] ?? null) === 'text/html' && is_string($value) ? strip_tags($value) : $value);

        self::assertSame(['html' => 'x', 'cover' => 1], $result);
    }
}
