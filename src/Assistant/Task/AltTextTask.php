<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Task;

use SightPress\Core\Assistant\AssistantModule;
use SightPress\Core\Media\Contract\MediaPictures;
use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Assistant\AssistantImage;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantSuggestion;
use SightPress\Module\Assistant\AssistantTask;
use SightPress\Module\Exception\AssistantException;
use SightPress\Module\Exception\InvalidValueException;

/**
 * `alt_text`: the alt text of a picture of the media library (the Suggest button by its alt text field), from the
 * picture itself, so only with a model that can see pictures. Plain text within the field's limit, without "Image
 * of". It stays marked as the assistant's until a person checks it (A-4, C-4.5).
 */
final readonly class AltTextTask implements AssistantTask
{
    public const string ID = 'alt_text';

    /** Widest copy of the picture sent: enough to describe it, and fewer tokens. */
    public const int WIDTH = 1024;

    public function __construct(
        private Prompts $prompts,
        private MediaPictures $pictures,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Suggest alt text';
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
                'field' => ['type' => 'string', 'enum' => ['alt']],
                'media' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'maxLength' => 255],
            ],
            'required' => ['media'],
            'additionalProperties' => false,
        ];
    }

    public function needsVision(): bool
    {
        return true;
    }

    public function buildPrompt(array $input, AssistantContext $context): array
    {
        $id = is_int($input['media'] ?? null) ? $input['media'] : 0;
        $picture = $this->pictures->picture($id, self::WIDTH);
        if ($picture === null) {
            throw new InvalidValueException('There is no such picture.');
        }
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';

        return [
            AssistantMessage::system($this->prompts->system(self::ID, $context->locale(), ['max' => MediaPictures::ALT_MAX])),
            AssistantMessage::user(($name === '' ? '' : PromptData::block('file_name', $name) . "\n\n") . 'Write the alt text of this picture.', [new AssistantImage($picture['type'], $picture['data'])]),
        ];
    }

    public function validateOutput(AssistantResponse $response, array $input, AssistantContext $context): AssistantSuggestion
    {
        $text = Unquoted::of(PlainText::of($response->text, MediaPictures::ALT_MAX));
        $text = (string) preg_replace('/^(?:an?\s+)?(?:image|picture|photo(?:graph)?)\s+(?:of|showing)(?:\s+|$)/iu', '', $text);
        if (trim($text) === '') {
            throw new AssistantException(AssistantFailure::InvalidOutput, 'The alt text was empty.');
        }

        return new AssistantSuggestion(mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1));
    }
}
