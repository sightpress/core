<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant\Task;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\InputSchema;
use SightPress\Core\Assistant\SiteAssistantContext;
use SightPress\Core\Assistant\Task\Prompts;
use SightPress\Core\Assistant\Task\TranslateTask;
use SightPress\Core\Assistant\Task\TranslationSegments;
use SightPress\Core\Block\Contract\RichText;
use SightPress\Core\Page\Contract\TranslationSource;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\TokenUsage;
use SightPress\Module\Exception\AssistantException;

/**
 * Translations drafted by the assistant (A-6): only the words go out, the blocks come back the same, and answers that
 * lose a text or change a link are refused.
 */
#[CoversClass(TranslateTask::class)]
#[CoversClass(TranslationSegments::class)]
#[CoversClass(TranslationSource::class)]
#[UsesNamespace('SightPress\Core')]
final class TranslateTaskTest extends TestCase
{
    public function testSendsOnlyTheWordsAndPutsThemBackInTheSameBlocks(): void
    {
        $source = self::source();
        $segments = TranslationSegments::of($source);
        self::assertSame(['Book club', 'We read together.', '', '', 'Join us', '<p>Every <a href="/events">month</a>.</p>', 'Readers in the garden', 'Inside', 'Go'], $segments);

        $translated = TranslationSegments::apply($source, ['Clube do livro', 'Lemos juntos.', 'ignored', ' ', 'Junte-se a nós', '<p>Todos os <a href="/events">meses</a>.</p>', 'Leitores no jardim', 'Dentro', 'Ir']);
        self::assertSame(['Clube do livro', 'Lemos juntos.', null, ''], [$translated->title, $translated->summary, $translated->seoTitle, $translated->description], 'what had no text gets none');
        self::assertSame([
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Junte-se a nós', 'level' => 2]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>Todos os <a href="/events">meses</a>.</p>']],
            ['type' => 'image', 'version' => 1, 'data' => ['media' => 7, 'alt' => 'Leitores no jardim', 'decorative' => false]],
            ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [['blocks' => [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Dentro', 'level' => 3]]]], ['blocks' => [['type' => 'call_to_action', 'version' => 1, 'data' => ['text' => 'Ir', 'url' => 'https://example.org/go', 'style' => 'primary']]]]]]],
        ], $translated->blocks, 'same types, media, levels, addresses and styles');
        self::assertSame('Book club', TranslationSegments::apply($source, [])->title, 'nothing translated, nothing changed');
    }

    public function testChecksTheTranslation(): void
    {
        $task = new TranslateTask(new Prompts(), new RichText());
        $segments = TranslationSegments::of(self::source());
        $input = ['from' => 'en-GB', 'to' => 'pt-PT', 'segments' => $segments];
        self::assertSame([], InputSchema::problems($task->inputSchema(), $input));
        self::assertSame(['translate', 'Draft a translation', 'page.edit.own', false], [$task->id(), $task->label(), $task->permission(), $task->needsVision()]);
        $context = new SiteAssistantContext('pt-PT');
        [$system, $user] = $task->buildPrompt($input, $context);
        self::assertStringContainsString('from British English into European Portuguese', $system->text);
        self::assertStringContainsString('exactly 9 texts', $system->text);
        self::assertStringContainsString('"<p>Every <a href=\"/events\">month</a>.</p>"', $user->text);
        self::assertStringContainsString('exactly 0 texts', $task->buildPrompt(['segments' => 'x'], $context)[0]->text);

        $good = ['Clube do livro', '"Lemos juntos."', 'x', '', 'Junte-se a nós', '<p>Todos os <a href="/events">meses</a>.<script>x()</script></p>', 'Leitores no jardim', 'Dentro', 'Ir'];
        $suggestion = $task->validateOutput(self::answer($good), $input, $context);
        self::assertSame('Clube do livro', $suggestion->text);
        $kept = json_decode(is_string($suggestion->fields['segments'] ?? null) ? $suggestion->fields['segments'] : '', true);
        self::assertSame(['Clube do livro', 'Lemos juntos.', '', '', 'Junte-se a nós', '<p>Todos os <a href="/events">meses</a>.</p>', 'Leitores no jardim', 'Dentro', 'Ir'], $kept, 'quotes off, script gone, empty stays empty');

        $refused = [
            'one text missing' => array_slice($good, 0, 8),
            'a link changed' => array_replace($good, [5 => '<p>Todos os <a href="https://evil.example">meses</a>.</p>']),
            'a link lost' => array_replace($good, [5 => '<p>Todos os meses.</p>']),
            'a title too long' => array_replace($good, [0 => str_repeat('x', 201)]),
            'not text' => array_replace($good, [6 => 3]),
            'an empty text' => array_replace($good, [7 => '  ']),
        ];
        foreach ($refused as $what => $answer) {
            try {
                $task->validateOutput(self::answer($answer), $input, $context);
                self::fail('Not refused: ' . $what);
            } catch (AssistantException $exception) {
                self::assertSame('invalid_output', $exception->failure->value, $what);
            }
        }
        try {
            $task->validateOutput(new AssistantResponse('{"segments": "x"}', 'm', new TokenUsage()), $input, $context);
            self::fail('Not refused: no list');
        } catch (AssistantException $exception) {
            self::assertSame('invalid_output', $exception->failure->value);
        }
        self::assertSame('…', $task->validateOutput(new AssistantResponse('{"segments": []}', 'm', new TokenUsage()), ['segments' => []], $context)->text);
    }

    /**
     * @param list<mixed> $segments
     */
    private static function answer(array $segments): AssistantResponse
    {
        return new AssistantResponse('Here: ' . json_encode(['segments' => $segments], JSON_UNESCAPED_UNICODE), 'm', new TokenUsage(10, 10));
    }

    private static function source(): TranslationSource
    {
        return new TranslationSource('Book club', 'We read together.', null, '', [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Join us', 'level' => 2]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>Every <a href="/events">month</a>.</p>']],
            ['type' => 'image', 'version' => 1, 'data' => ['media' => 7, 'alt' => 'Readers in the garden', 'decorative' => false]],
            ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [['blocks' => [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Inside', 'level' => 3]]]], ['blocks' => [['type' => 'call_to_action', 'version' => 1, 'data' => ['text' => 'Go', 'url' => 'https://example.org/go', 'style' => 'primary']]]]]]],
        ]);
    }
}
