<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Search\Analysis;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Search\Analysis\TextAnalyzer;
use SightPress\Core\Search\Analysis\TextAnalyzers;
use SightPress\Core\Search\Analysis\Token;

#[CoversClass(TextAnalyzer::class)]
#[CoversClass(TextAnalyzers::class)]
#[CoversClass(Token::class)]
final class TextAnalyzerTest extends TestCase
{
    /**
     * Golden table: what a text in each language becomes, word by word (`word→term`), with core's stop words.
     *
     * @return iterable<string, array{non-empty-string, string, string}>
     */
    public static function golden(): iterable
    {
        yield 'English: case, stems, stop words, possessives' => ['en-GB', 'The Accessibility of LIBRARIES: running cafés, the library’s books', 'Accessibility→access LIBRARIES→librari running→run cafés→cafe library’s→librari books→book'];
        yield 'English: numbers are kept as they are' => ['en-US', 'Open 24 hours, 7 days', 'Open→open 24→24 hours→hour 7→7 days→day'];
        yield 'Portuguese: accents, stems' => ['pt-PT', 'Acessibilidade e informação nas bibliotecas', 'Acessibilidade→acessibil informação→informaca bibliotecas→bibliotec'];
        yield 'Portuguese: typed without accents finds the same' => ['pt-BR', 'acessibilidade informacao', 'acessibilidade→acessibil informacao→informaca'];
        yield 'Spanish: ñ is typed as n too' => ['es', 'Las bibliotecas públicas de España', 'bibliotecas→bibliotec públicas→public España→espan'];
        yield 'French: elision, accents' => ['fr', 'Les élèves étudient l’histoire d’Europe', 'élèves→elev étudient→etudient l’histoire→histoir d’Europe→europ'];
        yield 'German: ß and umlauts' => ['de-DE', 'Die Straße und die Häuser', 'Straße→strass Häuser→haus'];
        yield 'Hebrew: points and direction marks removed' => ['he', "שָׁלוֹם\u{200F} עוֹלָם", "שָׁלוֹם\u{200F}→שלום עוֹלָם→עולם"];
        yield 'Arabic: short vowels and tatweel removed' => ['ar', 'مَكْتَبَة الْمَدِينَة كـتـاب', 'مَكْتَبَة→مكتبة الْمَدِينَة→المدينة كـتـاب→كتاب'];
        yield 'Full-width letters become plain ones' => ['en', 'ＳｉｇｈｔＰｒｅｓｓ', 'SightPress→sightpress'];
    }

    /**
     * @param non-empty-string $locale
     */
    #[DataProvider('golden')]
    public function testAnalysesTextInEachLanguage(string $locale, string $text, string $expected): void
    {
        $analyzer = new TextAnalyzers()->for($locale);
        $words = array_map(static fn (Token $token): string => $token->word . '→' . $token->term, $analyzer->tokens($text));

        self::assertSame($expected, implode(' ', $words));
        self::assertSame($locale, $analyzer->locale());
    }

    /**
     * Scripts written without spaces: ICU's dictionaries split them (exactly where depends on the ICU version), no
     * character is lost and marks that are vowels or voicing (Japanese dakuten, Thai vowels) are kept.
     *
     * @return iterable<string, array{non-empty-string, string, string}>
     */
    public static function withoutSpaces(): iterable
    {
        yield 'Japanese' => ['ja', '日本語のガラスです', 'ガラス'];
        yield 'Chinese' => ['zh-Hans', '我们的图书馆很大', '我们'];
        yield 'Thai' => ['th', 'ภาษาไทยง่ายนิดเดียว', 'ไทย'];
    }

    /**
     * @param non-empty-string $locale
     */
    #[DataProvider('withoutSpaces')]
    public function testSplitsScriptsWrittenWithoutSpaces(string $locale, string $text, string $word): void
    {
        $tokens = new TextAnalyzers()->for($locale)->tokens($text);

        self::assertGreaterThan(2, count($tokens));
        self::assertSame($text, implode('', array_map(static fn (Token $token): string => $token->word, $tokens)));
        self::assertContains($word, array_map(static fn (Token $token): string => $token->term, $tokens));
        foreach ($tokens as $token) {
            self::assertSame($token->word, $token->term, 'no stemmer, nothing removed');
        }
    }

    public function testGivesTermsAndNormalisesWords(): void
    {
        $analyzer = new TextAnalyzer('en-GB', ['The', '  ', 'Of']);

        self::assertSame(['librari', 'citi'], $analyzer->terms('The Libraries of the City'));
        self::assertSame('resume', $analyzer->normalise('RÉSUMÉ'));
        self::assertSame('a b', $analyzer->normalise(" a\u{200E} b "));
        self::assertSame([], $analyzer->tokens(''));
        self::assertSame([], $analyzer->tokens('  — … !! '));
        self::assertSame([], $analyzer->tokens(str_repeat('a', 65)), 'too long to index');
        self::assertCount(1, $analyzer->tokens(str_repeat('a', 64)));
        self::assertSame([], $analyzer->tokens("\xC3\x28"), 'not UTF-8');
        self::assertSame('', $analyzer->normalise("\xC3\x28"));
    }

    public function testLanguagesWithoutAStemmerKeepWholeWords(): void
    {
        $analyzer = new TextAnalyzer('pl', []);
        self::assertSame(['bibliotekami'], $analyzer->terms('Bibliotekami'));

        $norwegian = new TextAnalyzer('nb-NO', []);
        self::assertSame(['bibliotek'], $norwegian->terms('bibliotekene'), 'Norwegian Bokmål uses the Norwegian stemmer');
        self::assertSame(['l\'histoir'], new TextAnalyzer('en', [])->terms('l’histoire'), 'only French, Italian and Catalan elide');
    }

    public function testEachLocaleHasOneAnalyzerWithItsLanguagesStopWords(): void
    {
        $analyzers = new TextAnalyzers();
        self::assertSame($analyzers->for('pt-PT'), $analyzers->for('pt-PT'));
        self::assertSame(['bibliotec'], $analyzers->for('pt-BR')->terms('as bibliotecas'));
        self::assertSame(['the', 'city'], $analyzers->for('xx-Latn')->terms('the city'), 'no stop words or stemmer for unknown languages');

        $directory = sys_get_temp_dir() . '/sp-stopwords-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/en.txt', "# comment\nthe # article\n\ncity\n");
        mkdir($directory . '/de.txt');
        try {
            $own = new TextAnalyzers($directory);
            self::assertSame(['of'], $own->for('en-GB')->terms('the city of'));
            self::assertSame(['der'], $own->for('de')->terms('der'), 'a folder is not a list');
            self::assertSame(['the'], $own->for('../en')->terms('the'), 'only language codes are looked up');
        } finally {
            unlink($directory . '/en.txt');
            rmdir($directory . '/de.txt');
            rmdir($directory);
        }
    }
}
