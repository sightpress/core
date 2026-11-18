<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Template;

use SightPress\Core\Tests\Support\Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\Translations;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(Translations::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
final class TranslationsTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->directory->write('messages.pt-BR.xlf', self::xliff('pt-BR', ['Go to the home page' => 'Ir para a página inicial', '{count, plural, one {# page} other {# pages}}' => '{count, plural, one {# página} other {# páginas}}', 'Not yet' => '']));
        $this->directory->write('admin.cy.xlf', self::xliff('cy', ['Save' => 'Cadw']));
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testTranslatesAndFallsBackToTheEnglishText(): void
    {
        $translations = Translations::fromDirectories([$this->directory->path, '/does/not/exist']);

        self::assertSame('Ir para a página inicial', Text::of($translations->translate('Go to the home page', [], 'pt-BR')));
        self::assertSame('Go to the home page', Text::of($translations->translate('Go to the home page', [], 'cy')));
        self::assertSame('Cadw', Text::of($translations->translate('Save', [], 'cy', 'admin')));
        self::assertSame('Save', Text::of($translations->translate('Save', [], 'pt-BR', 'admin')));
    }

    public function testFormatsPluralsAndPlaceholdersInTheViewersLanguage(): void
    {
        $translations = Translations::fromDirectories([$this->directory->path]);
        $plural = '{count, plural, one {# page} other {# pages}}';

        self::assertSame('1 page', Text::of($translations->translate($plural, ['count' => 1], 'en-GB')));
        self::assertSame('3 pages', Text::of($translations->translate($plural, ['count' => 3], 'en-GB')));
        self::assertSame('2 páginas', Text::of($translations->translate($plural, ['count' => 2], 'pt-BR')));
        self::assertSame('Hello, Ada', Text::of($translations->translate('Hello, {name}', ['name' => 'Ada'], 'en-GB')));
        self::assertSame('Broken {', Text::of($translations->translate('Broken {', ['x' => 1], 'en-GB')), 'invalid patterns are shown as they are');
    }

    public function testUsesTheClosestCatalogueAndTranslatesTheValuesToo(): void
    {
        $translations = Translations::fromDirectories([$this->directory->path], 'en-GB');

        self::assertEqualsCanonicalizing(['en-GB', 'pt-BR', 'cy'], $translations->locales());
        self::assertSame('en-GB', $translations->defaultLocale());
        self::assertSame('pt-BR', $translations->catalogueLocale('pt-PT'), 'the same language in another region');
        self::assertSame('en-GB', $translations->catalogueLocale('de-DE'));
        self::assertSame('Ir para a página inicial', $translations->translate(new \SightPress\Core\Template\Message('Go to the home page'), [], 'pt-PT'));
        self::assertSame('Espanhol', $translations->translate(new \SightPress\Core\Template\LanguageName('es'), [], 'pt-BR'));
        self::assertSame('Go: ir para a página inicial, 1, , ', $translations->translate('Go: {a}, {b}, {c}, {d}', [
            'a' => new \SightPress\Core\Template\Message('Go to the home page')->lowerFirst(),
            'b' => true,
            'c' => new class () implements \Stringable {
                public function __toString(): string
                {
                    return '';
                }
            },
            'd' => [],
        ], 'pt-BR'));
    }

    /**
     * @param array<string, string> $units
     */
    private static function xliff(string $locale, array $units): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><xliff xmlns="urn:oasis:names:tc:xliff:document:2.0" version="2.0" srcLang="en-GB" trgLang="' . $locale . '"><file id="f">';
        foreach ($units as $source => $target) {
            $xml .= '<unit id="' . md5($source) . '"><segment><source>' . htmlspecialchars($source) . '</source><target>' . htmlspecialchars($target) . '</target></segment></unit>';
        }

        return $xml . '</file></xliff>';
    }
}
