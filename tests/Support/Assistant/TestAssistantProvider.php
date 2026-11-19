<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Assistant;

use SightPress\Module\Assistant\AssistantConnection;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantProvider;
use SightPress\Module\Assistant\AssistantRequest;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\ProviderCapabilities;
use SightPress\Module\Assistant\TokenUsage;
use SightPress\Module\Exception\AssistantException;

/**
 * "Test AI": answers on the server, without a network, from what the request asks for. The first answer in
 * {@see ANSWERS} whose words are in the request wins; a key that ends in `0000` is refused like a wrong key, and a
 * question with `[[busy]]` in it finds the service busy, and one with `[[long]]` gets an answer cut short. A
 * translation puts "Tradução: " before each plain text and keeps HTML as it is.
 */
final class TestAssistantProvider implements AssistantProvider
{
    public const string ID = 'test_ai';

    /** Words in the request => the answer. */
    public const array ANSWERS = [
        'connection test' => 'OK',
        'You write the summary of a web page' => 'When the library is open, and the days it is closed.',
        'You write what search engines show' => '{"title": "Opening hours of Riverside Library", "description": "When Riverside Library is open each day, and the bank holidays it is closed."}',
        'You write the alt text of a picture' => 'A reading room with tall windows and children reading on cushions.',
        'You rewrite a piece of text' => 'We are open every day from 9 to 5.',
        'You draft the text of a web page' => '{"blocks": [{"type": "heading", "text": "Join our book club", "level": 2}, {"type": "rich_text", "html": "<p>We meet on the first Tuesday of each month. [Add the time.]</p>"}]}',
        'You explain one check' => 'Pictures need alt text so that people who can’t see them know what they show. Open the picture’s block and describe it.',
        'main menu' => 'Open Menus in the admin panel, choose the main menu, then add the page to it.',
    ];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Test AI';
    }

    public function description(): string
    {
        return 'A pretend AI service for tests. Nothing leaves the server.';
    }

    public function models(): array
    {
        return ['test-small', 'test-vision'];
    }

    public function needsBaseUrl(): bool
    {
        return false;
    }

    public function capabilities(string $model): ProviderCapabilities
    {
        return new ProviderCapabilities(vision: $model === 'test-vision');
    }

    public function complete(AssistantRequest $request, AssistantConnection $connection): AssistantResponse
    {
        if (str_ends_with($connection->apiKey, '0000')) {
            throw new AssistantException(AssistantFailure::InvalidKey, 'Test AI: 401');
        }
        $asked = implode("\n", array_map(static fn (AssistantMessage $message): string => $message->text, $request->messages));
        if (str_contains($asked, '[[busy]]')) {
            throw new AssistantException(AssistantFailure::RateLimited, 'Test AI: 429');
        }
        if (str_contains($asked, '[[long]]')) {
            return new AssistantResponse('The first part of a long answer', $request->model, new TokenUsage(100, 20), truncated: true);
        }
        if (str_contains($asked, 'You translate the texts of a web page') && preg_match('#<data name="texts">\n(.*)\n</data>#s', $asked, $texts) === 1) {
            $segments = json_decode($texts[1], true);

            return new AssistantResponse((string) json_encode(['segments' => array_map(
                static fn (mixed $text): string => is_string($text) && preg_match('/<[a-z]/i', $text) !== 1 ? 'Tradução: ' . $text : (is_string($text) ? $text : ''),
                is_array($segments) ? $segments : [],
            )], JSON_UNESCAPED_UNICODE), $request->model, new TokenUsage(300, 300));
        }
        foreach (self::ANSWERS as $words => $answer) {
            if (str_contains($asked, $words)) {
                return new AssistantResponse($answer, $request->model, new TokenUsage(120, 30));
            }
        }

        return new AssistantResponse('I can help with writing and running this website.', $request->model, new TokenUsage(100, 20));
    }
}
