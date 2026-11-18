<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\BlockException;
use SightPress\Core\Block\Schema\SchemaConstraints;
use Symfony\Component\Validator\Validation;

#[CoversClass(SchemaConstraints::class)]
#[CoversClass(BlockException::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
final class SchemaConstraintsTest extends TestCase
{
    private const array SCHEMA = [
        'type' => 'object',
        'title' => 'Example',
        'additionalProperties' => false,
        'required' => ['text', 'count'],
        'properties' => [
            'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5, 'description' => 'Note'],
            'code' => ['type' => 'string', 'minLength' => 2, 'pattern' => '^[a-z/]+$'],
            'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
            'ratio' => ['type' => 'number'],
            'flag' => ['type' => 'boolean'],
            'maybe' => ['type' => ['string', 'null']],
            'style' => ['enum' => ['a', 'b']],
            'tags' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 2, 'items' => ['type' => 'string']],
            'group' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]],
            'url' => ['type' => 'string', 'format' => 'link'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'media' => ['type' => 'integer', 'format' => 'media'],
            'picture' => ['type' => 'integer', 'format' => 'media-image'],
            'html' => ['type' => 'string', 'contentMediaType' => 'text/html'],
        ],
    ];

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function data(): iterable
    {
        $valid = ['text' => 'Hi', 'count' => 2];
        yield 'valid' => [$valid + ['code' => 'a/b', 'ratio' => 1.5, 'flag' => true, 'maybe' => null, 'style' => 'a', 'tags' => ['x'], 'group' => ['x' => 1], 'url' => 'https://example.org', 'email' => 'a@example.org', 'media' => 3, 'html' => '<p>x</p>'], []];
        yield 'missing' => [['text' => 'Hi'], ['[count]' => 'Fill in this field.']];
        yield 'extra' => [$valid + ['other' => 1], ['[other]' => 'This field isn\'t allowed here.']];
        yield 'empty text' => [['text' => '', 'count' => 2], ['[text]' => 'Fill in this field.']];
        yield 'long text' => [['text' => 'Too long', 'count' => 2], ['[text]' => 'Use 5 characters or fewer.']];
        yield 'short code' => [$valid + ['code' => 'a'], ['[code]' => 'Use at least 2 characters.']];
        yield 'pattern' => [$valid + ['code' => 'A1'], ['[code]' => 'This isn\'t in the expected format.']];
        yield 'type' => [['text' => 5, 'count' => '2'], ['[text]' => 'This must be text.', '[count]' => 'This must be a whole number.']];
        yield 'number' => [$valid + ['ratio' => 'x', 'flag' => 1, 'maybe' => 2], ['[ratio]' => 'This must be a number.', '[flag]' => 'This must be yes or no.', '[maybe]' => 'This must be text or empty.']];
        yield 'range' => [['text' => 'a', 'count' => 0], ['[count]' => 'Use 1 or more.']];
        yield 'range high' => [['text' => 'a', 'count' => 4], ['[count]' => 'Use 3 or less.']];
        yield 'enum' => [$valid + ['style' => 'c'], ['[style]' => 'Choose one of the options.']];
        yield 'list' => [$valid + ['tags' => ['a' => 'x']], ['[tags]' => 'This must be a list.']];
        yield 'count' => [$valid + ['tags' => []], ['[tags]' => 'Add at least 1.']];
        yield 'count high' => [$valid + ['tags' => ['a', 'b', 'c']], ['[tags]' => 'Use at most 2.']];
        yield 'items' => [$valid + ['tags' => [1]], ['[tags][0]' => 'This must be text.']];
        yield 'object' => [$valid + ['group' => ['a']], ['[group]' => 'This must be a group of fields.']];
        yield 'nested' => [$valid + ['group' => ['x' => 'y', 'z' => 1]], ['[group][x]' => 'This must be a whole number.']];
        yield 'link' => [$valid + ['url' => 'javascript:alert(1)'], ['[url]' => 'Enter a web address starting with https://, an address on this site starting with /, or a mailto: or tel: link.']];
        yield 'email' => [$valid + ['email' => 'nope'], ['[email]' => 'Enter an email address such as name@example.org.']];
        yield 'media' => [$valid + ['media' => 0], ['[media]' => 'Choose a file from the media library.']];
        yield 'picture' => [$valid + ['picture' => -1], ['[picture]' => 'Choose a file from the media library.']];
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $expected property path => message
     */
    #[DataProvider('data')]
    public function testValidatesDataAgainstTheSchema(array $data, array $expected): void
    {
        $violations = Validation::createValidator()->validate($data, SchemaConstraints::for(self::SCHEMA, 'example'));

        $found = [];
        foreach ($violations as $violation) {
            $found[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        self::assertSame($expected, $found);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function links(): iterable
    {
        yield 'https' => ['https://example.org/a?b=1#c', true];
        yield 'http' => ['http://example.org', true];
        yield 'path' => ['/about/team', true];
        yield 'root' => ['/', true];
        yield 'anchor' => ['#contact', true];
        yield 'mailto' => ['mailto:hello@example.org', true];
        yield 'tel' => ['tel:+44 20 7946 0000', true];
        yield 'protocol relative' => ['//evil.example', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'data' => ['data:text/html,x', false];
        yield 'relative' => ['about', false];
        yield 'space' => ['https://exa mple.org', false];
        yield 'empty host' => ['https:///x', false];
    }

    #[DataProvider('links')]
    public function testKnowsWhatALinkIs(string $link, bool $valid): void
    {
        self::assertSame($valid, SchemaConstraints::isLink($link));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unsupported(): iterable
    {
        yield 'keyword' => [['properties' => ['a' => ['oneOf' => []]]], 'the keyword "oneOf" at "/a"'];
        yield 'type' => [['type' => 'date'], 'the type "date" at "/"'];
        yield 'type list' => [['type' => []], 'a "type" that isn\'t a list'];
        yield 'format' => [['format' => 'uri'], 'the format "uri"'];
        yield 'media kind' => [['format' => 'media-font'], 'the format "media-font"'];
        yield 'media type' => [['contentMediaType' => 'text/markdown'], '"contentMediaType" other than "text/html"'];
        yield 'pattern' => [['pattern' => '('], 'a "pattern" that isn\'t a valid regular expression'];
        yield 'pattern type' => [['pattern' => 5], 'a "pattern" that isn\'t a valid regular expression'];
        yield 'min length' => [['minLength' => -1], 'a "minLength" that isn\'t a whole number from 0 up'];
        yield 'max length' => [['maxLength' => 0], 'a "maxLength" of 0'];
        yield 'minimum' => [['minimum' => '1'], 'a "minimum" that isn\'t a number'];
        yield 'items' => [['items' => 'string'], 'a "items" that isn\'t an object'];
        yield 'enum' => [['enum' => 'a'], 'a "enum" that isn\'t a list'];
        yield 'required' => [['properties' => [], 'required' => ['a']], 'a required field "a" that isn\'t in "properties"'];
        yield 'property' => [['properties' => ['a' => 'string']], 'a "properties" that isn\'t an object'];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('unsupported')]
    public function testRefusesSchemasItCannotEnforce(array $schema, string $message): void
    {
        $this->expectException(BlockException::class);
        $this->expectExceptionMessage($message);

        SchemaConstraints::for($schema, 'acme.example');
    }

    public function testNamesTheBlockTypeInTheError(): void
    {
        $this->expectExceptionMessage('The schema of block type "acme.example" uses the keyword "$ref" at "/", which SightPress doesn\'t support.');

        SchemaConstraints::for(['$ref' => '#'], 'acme.example');
    }

    public function testKnowsTheMediaFormats(): void
    {
        self::assertTrue(SchemaConstraints::isMediaFormat('media'));
        self::assertTrue(SchemaConstraints::isMediaFormat('media-audio'));
        self::assertFalse(SchemaConstraints::isMediaFormat('media-font'));
        self::assertFalse(SchemaConstraints::isMediaFormat('link'));
        self::assertTrue(SchemaConstraints::isMediaFormat('media-captions'));
    }

    public function testAcceptsFloatsAsLimits(): void
    {
        $violations = Validation::createValidator()->validate(0.5, SchemaConstraints::for(['type' => 'number', 'minimum' => 1.5, 'maximum' => 2.5], 'x'));

        self::assertCount(1, $violations);
    }
}
