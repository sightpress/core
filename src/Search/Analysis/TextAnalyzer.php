<?php

declare(strict_types=1);

namespace SightPress\Core\Search\Analysis;

use IntlBreakIterator;
use IntlRuleBasedBreakIterator;
use Normalizer;
use RuntimeException;
use Wamania\Snowball\NotFoundException;
use Wamania\Snowball\Stemmer\Stemmer;
use Wamania\Snowball\StemmerFactory;

/**
 * Turns text in one language into the terms site search indexes and looks for (ADR-0011): Unicode normalisation
 * (NFKC), case folding, accents and other marks removed from Latin, Greek, Cyrillic, Hebrew and Arabic letters
 * (never from scripts whose marks are vowels, such as Thai or Devanagari, or from Japanese kana), invisible
 * direction marks dropped, words split by ICU (which also splits Chinese, Japanese and Thai, written without
 * spaces), stop words left out and Snowball stemming where the language has a stemmer.
 *
 * The same analyzer must be used for a document and for the queries that should find it.
 */
final class TextAnalyzer
{
    /** Longer words are left out: they are rarely searched for and would not fit the index. */
    public const int MAX_WORD_LENGTH = 64;

    /** Languages that join elided words to the next one with an apostrophe. */
    private const array ELIDING = ['fr', 'it', 'ca'];

    private readonly ?Stemmer $stemmer;

    /** @var array<string, true> */
    private readonly array $stopWords;

    /** @var array<string, non-empty-string> stems found so far */
    private array $stems = [];

    /**
     * @param non-empty-string $locale    BCP 47 tag of the text's language, for example `pt-PT`
     * @param list<string>     $stopWords words left out, as they are written; they are normalised here
     */
    public function __construct(private readonly string $locale, array $stopWords = [])
    {
        $this->stemmer = self::stemmerFor(self::language($locale));
        $words = [];
        foreach ($stopWords as $word) {
            $normalised = $this->normalise($word);
            if ($normalised !== '') {
                $words[$normalised] = true;
            }
        }
        $this->stopWords = $words;
    }

    /**
     * @return non-empty-string
     */
    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * The words of a text, in order, without stop words.
     *
     * @return list<Token>
     */
    public function tokens(string $text): array
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KC);
        if (!is_string($text) || $text === '') {
            return [];
        }
        $words = self::wordBreaker($this->locale);
        $words->setText($text);
        $tokens = [];
        $start = $words->first();
        for ($end = $words->next(); $end !== IntlBreakIterator::DONE; $start = $end, $end = $words->next()) {
            // Spaces and punctuation have a rule status below WORD_NONE_LIMIT; numbers, letters, kana and ideographs above.
            if ($words->getRuleStatus() < IntlBreakIterator::WORD_NONE_LIMIT) {
                continue;
            }
            $word = substr($text, $start, $end - $start);
            $normalised = $this->elided($this->normalise($word));
            if ($word === '' || $normalised === '' || mb_strlen($normalised) > self::MAX_WORD_LENGTH || isset($this->stopWords[$normalised])) {
                continue;
            }
            $tokens[] = new Token($word, $normalised, $this->stem($normalised));
        }

        return $tokens;
    }

    /**
     * The terms of a text, in order, without stop words.
     *
     * @return list<non-empty-string>
     */
    public function terms(string $text): array
    {
        return array_map(static fn (Token $token): string => $token->term, $this->tokens($text));
    }

    /**
     * A word case folded, without invisible marks and without the accents of letters whose accents people often
     * leave out when they type: `Ação` → `acao`, `שָׁלוֹם` → `שלום`, `ß` → `ss`.
     */
    public function normalise(string $word): string
    {
        if (!mb_check_encoding($word, 'UTF-8')) {
            return '';
        }
        $folded = mb_convert_case($word, MB_CASE_FOLD, 'UTF-8');
        // Direction marks, zero-width (non-)joiners and the Arabic tatweel change how text looks, not what it says.
        $folded = (string) preg_replace('/[\p{Cf}\x{0640}]+/u', '', $folded);
        $folded = str_replace(['’', '‘', 'ʼ'], "'", $folded);
        // Valid UTF-8 (checked above) always normalises.
        $decomposed = Normalizer::normalize($folded, Normalizer::FORM_D);
        $decomposed = is_string($decomposed) ? $decomposed : '';
        $bare = (string) preg_replace('/(?<=[\p{Latin}\p{Greek}\p{Cyrillic}\p{Hebrew}\p{Arabic}])\p{Mn}+/u', '', $decomposed);
        $composed = Normalizer::normalize($bare, Normalizer::FORM_C);

        return is_string($composed) ? trim($composed) : '';
    }

    /**
     * The word without an elided article or pronoun in front, in languages that write them so: `l'histoire` →
     * `histoire`, `dell'arte` → `arte`.
     */
    private function elided(string $word): string
    {
        if (!in_array(self::language($this->locale), self::ELIDING, true)) {
            return $word;
        }

        return (string) preg_replace("/^\\p{L}{1,7}'(?=\\p{L})/u", '', $word);
    }

    /**
     * @param non-empty-string $word
     *
     * @return non-empty-string
     */
    private function stem(string $word): string
    {
        if ($this->stemmer === null || preg_match("/^[\p{L}']+$/u", $word) !== 1) {
            return $word;
        }
        if (!isset($this->stems[$word])) {
            $stem = $this->stemmer->stem($word);
            $this->stems[$word] = $stem === '' ? $word : $stem;
        }

        return $this->stems[$word];
    }

    private static function wordBreaker(string $locale): IntlRuleBasedBreakIterator
    {
        $breaker = IntlBreakIterator::createWordInstance(str_replace('-', '_', $locale));

        return $breaker instanceof IntlRuleBasedBreakIterator ? $breaker : throw new RuntimeException('ICU can\'t split words: check that the intl extension has its data.');
    }

    private static function language(string $locale): string
    {
        $language = strtolower(explode('-', str_replace('_', '-', $locale))[0]);

        return match ($language) {
            'nb', 'nn' => 'no',
            default => $language,
        };
    }

    private static function stemmerFor(string $language): ?Stemmer
    {
        try {
            return StemmerFactory::create($language);
        } catch (NotFoundException) {
            return null;
        }
    }
}
