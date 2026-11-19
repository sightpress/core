<?php

declare(strict_types=1);

namespace SightPress\Core\Search\Analysis;

/**
 * One {@see TextAnalyzer} per locale, with the stop words of its language from `resources/search/stopwords/`
 * (one word per line, `#` starts a comment): `pt.txt` for `pt-PT` and `pt-BR`.
 */
final class TextAnalyzers
{
    /** @var array<string, TextAnalyzer> */
    private array $analyzers = [];

    private readonly string $directory;

    /**
     * @param string|null $directory folder of the stop word lists; core's own by default
     */
    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? dirname(__DIR__, 3) . '/resources/search/stopwords';
    }

    /**
     * @param non-empty-string $locale
     */
    public function for(string $locale): TextAnalyzer
    {
        return $this->analyzers[$locale] ??= new TextAnalyzer($locale, $this->stopWords($locale));
    }

    /**
     * @return list<string>
     */
    private function stopWords(string $locale): array
    {
        $language = strtolower(explode('-', $locale)[0]);
        $file = $this->directory . '/' . $language . '.txt';
        if (preg_match('/^[a-z]{2,3}$/', $language) !== 1 || !is_file($file)) {
            return [];
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $words = [];
        foreach ($lines === false ? [] : $lines as $line) {
            $word = trim(explode('#', $line, 2)[0]);
            if ($word !== '') {
                $words[] = $word;
            }
        }

        return $words;
    }
}
