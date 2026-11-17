<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Editor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Contract\BlockSchema;
use SightPress\Core\Page\Admin\Editor\ControlKind;

#[CoversClass(ControlKind::class)]
#[UsesNamespace('SightPress\Core')]
final class ControlKindTest extends TestCase
{
    public function testPicksAControlForEachKindOfField(): void
    {
        $cases = [
            [ControlKind::Media, ['type' => 'integer', 'format' => BlockSchema::FORMAT_IMAGE]],
            [ControlKind::Html, ['type' => 'string', 'contentMediaType' => BlockSchema::HTML]],
            [ControlKind::Select, ['enum' => ['primary', 'secondary']]],
            [ControlKind::Checkbox, ['type' => 'boolean']],
            [ControlKind::Blocks, ['type' => 'array', 'items' => ['type' => 'object']]],
            [ControlKind::Items, ['type' => 'array', 'items' => ['type' => 'object', 'properties' => []]]],
            [ControlKind::Items, ['type' => 'array']],
            [ControlKind::Select, ['type' => 'integer', 'minimum' => 2, 'maximum' => 6]],
            [ControlKind::Number, ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000]],
            [ControlKind::Number, ['type' => 'number']],
            [ControlKind::Email, ['type' => 'string', 'format' => BlockSchema::FORMAT_EMAIL]],
            [ControlKind::Textarea, ['type' => 'string', 'maxLength' => 2000]],
            [ControlKind::Text, ['type' => 'string', 'maxLength' => 200]],
            [ControlKind::Text, ['type' => 'string', 'format' => BlockSchema::FORMAT_LINK]],
        ];
        foreach ($cases as [$kind, $schema]) {
            self::assertSame($kind, ControlKind::of($schema), (string) json_encode($schema));
        }
    }

    public function testListsTheChoicesOfASelect(): void
    {
        self::assertSame(['primary', 2], ControlKind::choices(['enum' => ['primary', 2, null, 1.5]]));
        self::assertSame([2, 3, 4], ControlKind::choices(['type' => 'integer', 'minimum' => 2, 'maximum' => 4]));
        self::assertSame([], ControlKind::choices(['type' => 'integer', 'minimum' => 4, 'maximum' => 2]));
        self::assertSame([], ControlKind::choices(['type' => 'integer', 'minimum' => 'x']));
    }
}
