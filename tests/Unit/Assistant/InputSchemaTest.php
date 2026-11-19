<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\InputSchema;

#[CoversClass(InputSchema::class)]
final class InputSchemaTest extends TestCase
{
    private const array SCHEMA = [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'type' => 'object',
        'properties' => [
            'text' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 5, 'description' => 'The text'],
            'tone' => ['type' => 'string', 'enum' => ['plain', 'friendly'], 'default' => 'plain'],
            'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
            'ratio' => ['type' => 'number', 'minimum' => 0.5, 'maximum' => 1],
            'bold' => ['type' => 'boolean'],
            'tags' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'string', 'maxLength' => 3]],
            'any' => ['type' => 'array'],
            'meta' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
        ],
        'required' => ['text'],
        'additionalProperties' => false,
    ];

    public function testAcceptsValidInput(): void
    {
        self::assertSame([], InputSchema::problems(self::SCHEMA, ['text' => 'Hi', 'tone' => 'plain', 'count' => 2, 'ratio' => 0.75, 'bold' => true, 'tags' => ['a', 'b'], 'any' => [1, 'x'], 'meta' => ['a' => 'b', 'extra' => 1]]));
        self::assertSame([], InputSchema::problems(self::SCHEMA, ['text' => 'Hiya', 'ratio' => 1, 'meta' => []]));
    }

    public function testNamesWhatIsWrong(): void
    {
        self::assertSame([
            'input.text is missing.',
            'input.tone must be one of the allowed values.',
            'input.count must be 1 or more.',
            'input.ratio must be 1 or less.',
            'input.bold must be true or false.',
            'input.tags[1] must be 3 characters or fewer.',
            'input.meta must be an object.',
            'input.other isn\'t expected.',
        ], InputSchema::problems(self::SCHEMA, ['tone' => 'angry', 'count' => 0, 'ratio' => 2, 'bold' => 'yes', 'tags' => ['a', 'long'], 'meta' => ['x'], 'other' => 1]));
        self::assertSame([
            'input.text must be at least 2 characters long.',
            'input.count must be 3 or less.',
            'input.ratio must be 0.5 or more.',
            'input.tags may have at most 2 items.',
        ], InputSchema::problems(self::SCHEMA, ['text' => 'x', 'count' => 4, 'ratio' => 0.1, 'tags' => ['a', 'b', 'c']]));
        self::assertSame([
            'input.text must be 5 characters or fewer.',
            'input.count must be a whole number.',
            'input.ratio must be a number.',
            'input.tags must be a list.',
        ], InputSchema::problems(self::SCHEMA, ['text' => 'too long', 'count' => '2', 'ratio' => 'half', 'tags' => ['a' => 'b']]));
        self::assertSame(['input.text must be text.'], InputSchema::problems(self::SCHEMA, ['text' => 5]));
        self::assertSame(['input must be an object.'], InputSchema::problems(self::SCHEMA, ['a', 'b']));
        self::assertSame(['input must be an object.'], InputSchema::problems(self::SCHEMA, 'text'));
    }

    public function testRefusesEverythingWithASchemaItDoesNotUnderstand(): void
    {
        self::assertSame(['The schema of input uses pattern, oneOf, which isn\'t supported.'], InputSchema::problems(['type' => 'string', 'pattern' => 'x', 'oneOf' => []], 'x'));
        self::assertSame(['The schema of input has no type, or one that isn\'t supported.'], InputSchema::problems([], 'x'));
        self::assertSame(['The schema of input has no type, or one that isn\'t supported.'], InputSchema::problems(['type' => 'null'], null));
    }
}
