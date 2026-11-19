<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Help;

/**
 * Finds the parts of the documentation that answer a question (A-5), from `resources/assistant/help-index.json`:
 * keyword search, no vector database and no other service. Words of the question count where they appear in a
 * chunk's text, and three times in its page title or heading; chunks about screens or commands the person can't use
 * are left out.
 */
final class HelpIndex
{
    /** Words that say nothing about what is asked. */
    private const array STOP_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'can', 'do', 'does', 'for', 'from', 'how', 'i', 'in', 'is', 'it',
        'my', 'of', 'on', 'or', 'the', 'to', 'what', 'when', 'where', 'which', 'who', 'why', 'with', 'you', 'your',
    ];

    /** @var list<array{id: string, page: string, heading: string, url: string, text: string, needs: list<string>}>|null */
    private ?array $chunks = null;

    public function __construct(private readonly string $file = __DIR__ . '/../../../resources/assistant/help-index.json')
    {
    }

    /**
     * The best chunks for the question, best first.
     *
     * @param callable(string): bool $may whether the person holds a permission
     *
     * @return list<array{id: string, page: string, heading: string, url: string, text: string, needs: list<string>}>
     */
    public function search(string $question, callable $may, int $limit = 4): array
    {
        $words = self::words($question);
        if ($words === []) {
            return [];
        }
        $scored = [];
        foreach ($this->chunks() as $position => $chunk) {
            foreach ($chunk['needs'] as $permission) {
                if (!$may($permission)) {
                    continue 2;
                }
            }
            $title = self::words($chunk['page'] . ' ' . $chunk['heading']);
            $text = self::words($chunk['text']);
            $score = 0;
            foreach (array_unique($words) as $word) {
                $score += 3 * count(array_keys($title, $word, true)) + min(5, count(array_keys($text, $word, true)));
            }
            if ($score > 0) {
                $scored[] = [$score, $position, $chunk];
            }
        }
        usort($scored, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_map(static fn (array $found): array => $found[2], array_slice($scored, 0, $limit));
    }

    /**
     * @return list<array{id: string, page: string, heading: string, url: string, text: string, needs: list<string>}>
     */
    public function chunks(): array
    {
        if ($this->chunks === null) {
            $data = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;
            $chunks = [];
            foreach (is_array($data) && is_array($data['chunks'] ?? null) ? $data['chunks'] : [] as $chunk) {
                if (is_array($chunk) && is_string($chunk['url'] ?? null) && is_string($chunk['text'] ?? null)) {
                    $chunks[] = [
                        'id' => is_string($chunk['id'] ?? null) ? $chunk['id'] : '',
                        'page' => is_string($chunk['page'] ?? null) ? $chunk['page'] : '',
                        'heading' => is_string($chunk['heading'] ?? null) ? $chunk['heading'] : '',
                        'url' => $chunk['url'],
                        'text' => $chunk['text'],
                        'needs' => is_array($chunk['needs'] ?? null) ? array_values(array_filter($chunk['needs'], is_string(...))) : [],
                    ];
                }
            }
            $this->chunks = $chunks;
        }

        return $this->chunks;
    }

    /**
     * Lower-case words of at least two letters, without the stop words, plurals made singular ("pages" → "page").
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($text), $matches);
        $words = [];
        foreach ($matches[0] as $word) {
            if (in_array($word, self::STOP_WORDS, true)) {
                continue;
            }
            $words[] = mb_strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss') ? mb_substr($word, 0, -1) : $word;
        }

        return $words;
    }
}
