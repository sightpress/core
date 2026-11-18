<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Schema;

use SightPress\Core\Template\Message;
use SightPress\Core\Block\Contract\BlockSchema;
use SightPress\Core\Block\BlockException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\Optional;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Required;
use Symfony\Component\Validator\Constraints\Sequentially;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Turns a block type's JSON Schema into validator constraints, with messages an editor understands.
 *
 * Supported: `type` (one or a list), `properties`, `required`, `additionalProperties` (`false` refuses fields that
 * aren't listed), `enum`, `minLength`, `maxLength`, `pattern`, `minimum`, `maximum`, `items`, `minItems`,
 * `maxItems`, `format` (`link`, `email`, and `media` for the id of any file in the media library or `media-image`,
 * `media-video`, `media-audio`, `media-document`, `media-captions` (a WebVTT file) for one kind of file) and `contentMediaType: text/html` (rich
 * text, sanitised on save). `title`, `description`, `default`, `examples` and `$comment` are notes and ignored.
 * Any other keyword is a mistake in the block type and throws, so a rule is never skipped silently.
 */
final class SchemaConstraints
{
    public const string FORMAT_LINK = BlockSchema::FORMAT_LINK;
    public const string FORMAT_MEDIA = BlockSchema::FORMAT_MEDIA;
    public const string FORMAT_IMAGE = BlockSchema::FORMAT_IMAGE;
    public const string FORMAT_VIDEO = BlockSchema::FORMAT_VIDEO;
    public const string FORMAT_AUDIO = BlockSchema::FORMAT_AUDIO;
    public const string FORMAT_DOCUMENT = BlockSchema::FORMAT_DOCUMENT;
    public const string FORMAT_CAPTIONS = BlockSchema::FORMAT_CAPTIONS;

    /** Formats of media library ids. */
    public const array MEDIA_FORMATS = BlockSchema::MEDIA_FORMATS;
    public const string FORMAT_EMAIL = BlockSchema::FORMAT_EMAIL;
    public const string HTML = BlockSchema::HTML;

    private const array NOTES = ['title', 'description', 'default', 'examples', '$comment'];

    private const array KNOWN = [
        'type', 'properties', 'required', 'additionalProperties', 'enum', 'minLength', 'maxLength', 'pattern',
        'minimum', 'maximum', 'items', 'minItems', 'maxItems', 'format', 'contentMediaType',
    ];

    private const array TYPE_NAMES = [
        'string' => 'text',
        'integer' => 'a whole number',
        'number' => 'a number',
        'boolean' => 'yes or no',
        'array' => 'a list',
        'object' => 'a group of fields',
        'null' => 'empty',
    ];

    private const string LINK_PATTERN = '~^(?:https?://[^\s/?#]+[^\s]*|mailto:[^\s@]+@[^\s@]+|tel:\+?[0-9 ()-]{3,}|/(?!/)[^\s]*|#[A-Za-z][\w-]*)$~i';

    /**
     * @param array<string, mixed> $schema
     */
    public static function for(array $schema, string $blockType): Constraint
    {
        return new Sequentially(self::constraints($schema, $blockType, ''));
    }

    public static function isMediaFormat(string $format): bool
    {
        return in_array($format, self::MEDIA_FORMATS, true);
    }

    public static function isLink(string $value): bool
    {
        return preg_match(self::LINK_PATTERN, $value) === 1;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return list<Constraint>
     */
    private static function constraints(array $schema, string $blockType, string $at): array
    {
        foreach (array_keys($schema) as $keyword) {
            if (!in_array($keyword, self::KNOWN, true) && !in_array($keyword, self::NOTES, true)) {
                throw self::unsupported($blockType, $at, sprintf('the keyword "%s"', $keyword));
            }
        }

        $constraints = [];
        if (isset($schema['type'])) {
            $constraints[] = self::type($schema['type'], $blockType, $at);
        }
        if (isset($schema['enum'])) {
            $constraints[] = new Choice(choices: self::list($schema['enum'], 'enum', $blockType, $at), strict: true, message: 'Choose one of the options.');
        }
        if (isset($schema['minLength']) || isset($schema['maxLength'])) {
            $min = self::int($schema, 'minLength', $blockType, $at);
            $constraints[] = new Length(
                min: $min,
                max: self::positive($schema, 'maxLength', $blockType, $at),
                minMessage: $min === 1 ? 'Fill in this field.' : 'Use at least {{ limit }} characters.',
                maxMessage: 'Use {{ limit }} characters or fewer.',
            );
        }
        if (isset($schema['pattern'])) {
            $pattern = $schema['pattern'];
            if (!is_string($pattern) || @preg_match('/' . str_replace('/', '\/', $pattern) . '/u', '') === false) {
                throw self::unsupported($blockType, $at, 'a "pattern" that isn\'t a valid regular expression');
            }
            $constraints[] = new Regex(pattern: '/' . str_replace('/', '\/', $pattern) . '/u', message: 'This isn\'t in the expected format.');
        }
        if (isset($schema['minimum'])) {
            $constraints[] = new GreaterThanOrEqual(value: self::number($schema['minimum'], 'minimum', $blockType, $at), message: 'Use {{ compared_value }} or more.');
        }
        if (isset($schema['maximum'])) {
            $constraints[] = new LessThanOrEqual(value: self::number($schema['maximum'], 'maximum', $blockType, $at), message: 'Use {{ compared_value }} or less.');
        }
        if (isset($schema['minItems']) || isset($schema['maxItems'])) {
            $constraints[] = new Count(
                min: self::int($schema, 'minItems', $blockType, $at),
                max: self::int($schema, 'maxItems', $blockType, $at),
                minMessage: 'Add at least {{ limit }}.',
                maxMessage: 'Use at most {{ limit }}.',
            );
        }
        if (isset($schema['format'])) {
            $constraints = [...$constraints, ...self::format($schema['format'], $blockType, $at)];
        }
        if (isset($schema['contentMediaType']) && $schema['contentMediaType'] !== self::HTML) {
            throw self::unsupported($blockType, $at, sprintf('"contentMediaType" other than "%s"', self::HTML));
        }
        if (isset($schema['items'])) {
            $constraints[] = new All(new Sequentially(self::constraints(self::schema($schema['items'], 'items', $blockType, $at), $blockType, $at . '/items')));
        }
        if (isset($schema['properties']) || ($schema['additionalProperties'] ?? true) === false) {
            $constraints[] = self::collection($schema, $blockType, $at);
        }

        return $constraints;
    }

    private static function type(mixed $type, string $blockType, string $at): Callback
    {
        $types = [];
        foreach (is_string($type) ? [$type] : self::list($type, 'type', $blockType, $at) as $name) {
            if (!is_string($name) || !isset(self::TYPE_NAMES[$name])) {
                throw self::unsupported($blockType, $at, sprintf('the type %s', json_encode($name)));
            }
            $types[] = $name;
        }
        $message = sprintf('This must be %s.', implode(' or ', array_map(static fn (string $name): string => self::TYPE_NAMES[$name], $types)));

        return new Callback(static function (mixed $value, ExecutionContextInterface $context) use ($types, $message): void {
            foreach ($types as $name) {
                if (self::is($value, $name)) {
                    return;
                }
            }
            $context->buildViolation($message)->addViolation();
        });
    }

    private static function is(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            default => $value === null,
        };
    }

    /**
     * @return list<Constraint>
     */
    private static function format(mixed $format, string $blockType, string $at): array
    {
        return match ($format) {
            self::FORMAT_LINK => [new Callback(static function (mixed $value, ExecutionContextInterface $context): void {
                if (is_string($value) && !self::isLink($value)) {
                    $context->buildViolation('Enter a web address starting with https://, an address on this site starting with /, or a mailto: or tel: link.')->addViolation();
                }
            })],
            self::FORMAT_EMAIL => [new Email(message: 'Enter an email address such as name@example.org.')],
            self::FORMAT_MEDIA, self::FORMAT_IMAGE, self::FORMAT_VIDEO, self::FORMAT_AUDIO, self::FORMAT_DOCUMENT, self::FORMAT_CAPTIONS => [
                new GreaterThanOrEqual(value: 1, message: 'Choose a file from the media library.'),
            ],
            default => throw self::unsupported($blockType, $at, sprintf('the format %s', json_encode($format))),
        };
    }

    /**
     * @param array<mixed> $schema
     */
    private static function collection(array $schema, string $blockType, string $at): Collection
    {
        $properties = self::schema($schema['properties'] ?? [], 'properties', $blockType, $at);
        $required = ($schema['required'] ?? []) === [] ? [] : self::list($schema['required'], 'required', $blockType, $at);
        /** @var array<string, Constraint> $fields */
        $fields = [];
        foreach ($properties as $name => $property) {
            $name = (string) $name;
            $inner = new Sequentially(self::constraints(self::schema($property, 'properties', $blockType, $at), $blockType, $at . '/' . $name));
            $fields[$name] = in_array($name, $required, true) ? new Required($inner) : new Optional($inner);
        }
        foreach ($required as $name) {
            if (!is_string($name) || !isset($fields[$name])) {
                throw self::unsupported($blockType, $at, sprintf('a required field %s that isn\'t in "properties"', json_encode($name)));
            }
        }

        return new Collection(
            fields: $fields,
            allowExtraFields: ($schema['additionalProperties'] ?? true) !== false,
            extraFieldsMessage: 'This field isn\'t allowed here.',
            missingFieldsMessage: 'Fill in this field.',
        );
    }

    /**
     * @return array<mixed>
     */
    private static function schema(mixed $value, string $keyword, string $blockType, string $at): array
    {
        if (!is_array($value)) {
            throw self::unsupported($blockType, $at, sprintf('a "%s" that isn\'t an object', $keyword));
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value, string $keyword, string $blockType, string $at): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw self::unsupported($blockType, $at, sprintf('a "%s" that isn\'t a list', $keyword));
        }

        return $value;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return int<1, max>|null
     */
    private static function positive(array $schema, string $keyword, string $blockType, string $at): ?int
    {
        $value = self::int($schema, $keyword, $blockType, $at);
        if ($value === 0) {
            throw self::unsupported($blockType, $at, sprintf('a "%s" of 0', $keyword));
        }

        return $value;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return int<0, max>|null
     */
    private static function int(array $schema, string $keyword, string $blockType, string $at): ?int
    {
        $value = $schema[$keyword] ?? null;
        if ($value !== null && (!is_int($value) || $value < 0)) {
            throw self::unsupported($blockType, $at, sprintf('a "%s" that isn\'t a whole number from 0 up', $keyword));
        }

        return $value;
    }

    private static function number(mixed $value, string $keyword, string $blockType, string $at): int|float
    {
        if (!is_int($value) && !is_float($value)) {
            throw self::unsupported($blockType, $at, sprintf('a "%s" that isn\'t a number', $keyword));
        }

        return $value;
    }

    private static function unsupported(string $blockType, string $at, string $what): BlockException
    {
        return new BlockException(new Message('The schema of block type "{block_type}" uses {what} at "{at}", which SightPress doesn\'t support.', ['block_type' => $blockType, 'what' => $what, 'at' => $at === '' ? '/' : $at]));
    }
}
