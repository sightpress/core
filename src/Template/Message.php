<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use MessageFormatter;
use Stringable;

/**
 * Text for people, to be shown in their language: the en-GB text (also the key in the translation catalogues) and the
 * values that go into it. The text is an ICU message, so `Saved “{title}”.` and
 * `{count, plural, one {# page} other {# pages}}` work in every language; values of validation messages are written
 * `{{ limit }}`, as the validator gives them.
 *
 * Code builds a Message wherever a sentence holds a value, instead of `sprintf()`, so the sentence can be
 * translated; templates show it with `t()`. Cast to a string, it is the en-GB sentence (for logs and the command line).
 */
final readonly class Message implements Stringable
{
    /**
     * @param non-empty-string                          $text       en-GB, the catalogue key
     * @param array<string, string|int|float|self|LanguageName|null> $parameters a value that is itself a Message is
     *                                                                         translated too
     * @param bool                                      $lowerFirst whether its first letter is lower-cased once it is
     *                                                              translated, to go inside a sentence ("Enter title")
     */
    public function __construct(
        public string $text,
        public array $parameters = [],
        public bool $lowerFirst = false,
    ) {
    }

    /**
     * The same message with its first letter lower-cased once translated, to go inside another sentence.
     */
    public function lowerFirst(): self
    {
        return new self($this->text, $this->parameters, true);
    }

    /**
     * The message as given, or a string as a message without values.
     */
    public static function of(self|string $message): self
    {
        return $message instanceof self ? $message : new self($message === '' ? ' ' : $message);
    }

    /**
     * Fills a text, translated or not, with the values, in the given locale's way of writing numbers and plurals.
     *
     * @param array<string, string|int|float|null> $parameters values already translated
     */
    public static function format(string $text, array $parameters, string $locale): string
    {
        $icu = [];
        $replace = [];
        foreach ($parameters as $name => $value) {
            if (str_starts_with($name, '{{')) {
                $replace[$name] = (string) $value;
            } else {
                $icu[$name] = $value ?? '';
            }
        }
        // The validator's `{{ limit }}` values go in first, quoted for ICU, so the rest is a valid ICU message.
        $text = strtr($text, array_map(static fn (string $value): string => $icu === [] ? $value : str_replace(['{', '}'], ["'{'", "'}'"], $value), $replace));
        if ($icu !== []) {
            $formatted = MessageFormatter::formatMessage(str_replace('-', '_', $locale), $text, $icu);
            $text = $formatted === false ? $text : $formatted;
        }

        return $text;
    }

    /**
     * Several things as one, the way the language lists them: "a, b and c" (from the messages `{first}, {rest}` and
     * `{first} and {last}`, which each language translates).
     *
     * @param non-empty-list<string|self> $items
     */
    public static function list(array $items): self
    {
        $items = array_map(self::of(...), $items);
        $last = array_pop($items);
        if ($items === []) {
            return $last;
        }
        $first = array_shift($items);
        $rest = $items === [] ? $last : self::list([...$items, $last]);

        return $items === [] ? new Message('{first} and {last}', ['first' => $first, 'last' => $last]) : new Message('{first}, {rest}', ['first' => $first, 'rest' => $rest]);
    }

    /**
     * The message as plain arrays and values, for a session.
     *
     * @return array{text: string, parameters: array<string, mixed>, lower: bool}
     */
    public function export(): array
    {
        return [
            'text' => $this->text,
            'parameters' => array_map(static fn (string|int|float|self|LanguageName|null $value): mixed => match (true) {
                $value instanceof self => $value->export(),
                $value instanceof LanguageName => ['language' => $value->locale],
                default => $value,
            }, $this->parameters),
            'lower' => $this->lowerFirst,
        ];
    }

    /**
     * A message {@see export()}ed before (or a plain text), or null for anything else.
     */
    public static function import(mixed $value): ?self
    {
        if (is_string($value)) {
            return $value === '' ? null : new self($value);
        }
        if (!is_array($value) || !is_string($value['text'] ?? null) || $value['text'] === '') {
            return null;
        }
        $parameters = [];
        foreach (is_array($value['parameters'] ?? null) ? $value['parameters'] : [] as $name => $parameter) {
            if (is_string($name)) {
                $parameters[$name] = match (true) {
                    is_array($parameter) && is_string($parameter['language'] ?? null) => new LanguageName($parameter['language']),
                    is_array($parameter) => self::import($parameter),
                    is_string($parameter), is_int($parameter), is_float($parameter) => $parameter,
                    default => null,
                };
            }
        }

        return new self($value['text'], $parameters, ($value['lower'] ?? false) === true);
    }

    /**
     * The en-GB sentence, with the values (and messages among them) filled in.
     */
    public function __toString(): string
    {
        $parameters = array_map(static fn (string|int|float|self|LanguageName|null $value): string|int|float|null => $value instanceof self || $value instanceof LanguageName ? (string) $value : $value, $this->parameters);
        $text = self::format($this->text, $parameters, 'en-GB');

        return $this->lowerFirst ? mb_lcfirst($text) : $text;
    }
}
