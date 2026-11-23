<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

use SightPress\Core\Infrastructure\Assistant\AssistantAccess;
use SightPress\Core\Media\Contract\MediaLibrary;
use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\AssistantTask;
use SightPress\Module\Exception\AssistantException;
use SightPress\Module\Exception\InvalidValueException;

/**
 * `transcript_cleanup`: the saved transcript of a video or audio file with punctuation, paragraphs and speaker labels
 * (M-4). The answer is refused unless it has the same words in the same order, apart from labels at the start of a
 * line that are "Speaker n" or use only words of the transcript, so it can't add content or invent a name. It stays
 * marked as the assistant's until a person checks it.
 */
final readonly class TranscriptCleanupTask implements AssistantTask
{
    public const string ID = 'transcript_cleanup';

    /** Room for punctuation and speaker labels on top of the transcript. */
    public const int MAX_ANSWER = AssistantAccess::TRANSCRIPT_CLEANUP_MAX + 1500;

    public function __construct(
        private Prompts $prompts,
        private MediaLibrary $media,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Tidy up the transcript';
    }

    public function permission(): string
    {
        return 'media.upload';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'field' => ['type' => 'string', 'enum' => ['transcript']],
                'media' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['media'],
            'additionalProperties' => false,
        ];
    }

    public function needsVision(): bool
    {
        return false;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        $transcript = $this->transcript($input, $context);

        return [
            AssistantMessage::system($this->prompts->system(self::ID, $context->locale())),
            AssistantMessage::user(PromptData::block('transcript', $transcript) . "\n\nTidy up this transcript."),
        ];
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        if ($response->truncated) {
            throw new AssistantException(AssistantFailure::InvalidOutput, 'The tidied transcript was cut short.');
        }
        $text = PlainText::of($response->text, self::MAX_ANSWER);
        if (!self::keepsTheWords($this->transcript($input, $context), $text)) {
            throw new AssistantException(AssistantFailure::InvalidOutput, 'The tidied transcript changed the words.');
        }

        return new AssistantSuggestion($text);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws InvalidValueException
     */
    private function transcript(array $input, AssistantContext $context): string
    {
        $file = is_int($input['media'] ?? null) ? $this->media->file($input['media']) : null;
        $transcript = trim((string) $file?->transcript($context->locale()));
        if ($transcript === '') {
            throw new InvalidValueException('There is no saved transcript in this language.');
        }
        if (mb_strlen($transcript) > AssistantAccess::TRANSCRIPT_CLEANUP_MAX) {
            throw new InvalidValueException('The transcript is too long to tidy up in one go.');
        }

        return $transcript;
    }

    private static function keepsTheWords(string $source, string $answer): bool
    {
        $words = self::words($source);
        $at = 0;
        foreach (explode("\n", $answer) as $line) {
            $found = self::words($line);
            if (array_slice($words, $at, count($found)) !== $found) {
                if (preg_match('/^\s*([^:]{1,40}):\s*(.*)$/u', $line, $labelled) !== 1 || !self::isLabel($labelled[1], $words)) {
                    return false;
                }
                $found = self::words($labelled[2]);
                if (array_slice($words, $at, count($found)) !== $found) {
                    return false;
                }
            }
            $at += count($found);
        }

        return $at === count($words);
    }

    /**
     * @param list<string> $words the transcript's
     */
    private static function isLabel(string $label, array $words): bool
    {
        return preg_match('/^Speaker \d{1,2}$/', trim($label)) === 1 || array_diff(self::words($label), $words) === [];
    }

    /**
     * The words in lower case; apostrophes don't count, so adding one to "dont" keeps the word.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{M}\p{N}]+/u', str_replace(['\'', '’'], '', mb_strtolower($text)), $found);

        return $found[0];
    }
}
