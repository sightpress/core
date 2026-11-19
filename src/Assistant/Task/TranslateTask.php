<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

use SightPress\Core\Assistant\AssistantModule;
use SightPress\Core\Block\Contract\RichText;
use SightPress\Core\Page\Contract\PageLimits;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\AssistantTask;
use SightPress\Module\Exception\AssistantException;

/**
 * `translate`: a page's texts in another language (A-6), from {@see TranslationSegments}, so only words are sent and
 * the blocks, media and settings stay as they were. The answer must have one text per text sent: HTML stays HTML
 * through the rich text sanitizer and keeps exactly the same link addresses; plain text stays plain and within the
 * field's limit. The suggestion is the translated title; `segments` holds every text as JSON.
 */
final readonly class TranslateTask implements AssistantTask
{
    public const string ID = 'translate';

    /** Most characters of a page sent in one go. */
    public const int MAX_CHARACTERS = 60000;

    public const int MAX_SEGMENTS = 500;

    public function __construct(
        private Prompts $prompts,
        private RichText $richText,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Draft a translation';
    }

    public function permission(): string
    {
        return 'page.edit.own';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 35],
                'to' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 35],
                'segments' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => self::MAX_CHARACTERS], 'maxItems' => self::MAX_SEGMENTS],
            ],
            'required' => ['from', 'to', 'segments'],
            'additionalProperties' => false,
        ];
    }

    public function needsVision(): bool
    {
        return false;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        $segments = self::segments($input);
        $from = is_string($input['from'] ?? null) ? $input['from'] : 'en-GB';
        $to = is_string($input['to'] ?? null) ? $input['to'] : $context->locale();

        return [
            AssistantMessage::system($this->prompts->system(self::ID, $to, [
                'from' => LocaleCode::describe($from),
                'to' => LocaleCode::describe($to),
                'count' => count($segments),
            ])),
            AssistantMessage::user(PromptData::block('texts', (string) json_encode($segments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n\nTranslate the texts above."),
        ];
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        $sources = self::segments($input);
        $answered = JsonAnswer::object(CodeFence::strip($response->text))['segments'] ?? null;
        if (!is_array($answered) || !array_is_list($answered) || count($answered) !== count($sources)) {
            throw new AssistantException(AssistantFailure::InvalidOutput, 'The translation didn\'t have one text for each text sent.');
        }
        $translated = [];
        foreach ($sources as $index => $source) {
            $text = $answered[$index];
            if (!is_string($text)) {
                throw new AssistantException(AssistantFailure::InvalidOutput, 'The translation had something that isn\'t text.');
            }
            $translated[] = self::checked($source, $text, $index, $this->richText);
        }
        return new AssistantSuggestion($translated[0] ?? '…', ['segments' => (string) json_encode($translated, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /**
     * @throws AssistantException
     */
    private static function checked(string $source, string $text, int $index, RichText $richText): string
    {
        if (trim($source) === '') {
            return '';
        }
        if (RewriteTask::isHtml($source)) {
            $html = trim($richText->clean($text));
            if ($html === '' || self::links($html) !== self::links($source)) {
                throw new AssistantException(AssistantFailure::InvalidOutput, 'The translation changed the text\'s links.');
            }

            return $html;
        }
        $max = [PageLimits::TITLE, PageLimits::SUMMARY, PageLimits::SEO_TITLE, PageLimits::DESCRIPTION][$index] ?? max(1000, 3 * mb_strlen($source));

        return Unquoted::of(PlainText::of($text, $max));
    }

    /**
     * The link addresses of HTML, in order.
     *
     * @return list<string>
     */
    private static function links(string $html): array
    {
        preg_match_all('/<a\b[^>]*\bhref\s*=\s*"([^"]*)"/i', $html, $matches);

        return array_map(static fn (string $href): string => html_entity_decode($href, ENT_QUOTES | ENT_HTML5), $matches[1]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<string>
     */
    private static function segments(array $input): array
    {
        $segments = is_array($input['segments'] ?? null) ? $input['segments'] : [];

        return array_values(array_map(static fn (mixed $segment): string => is_string($segment) ? $segment : '', $segments));
    }
}
