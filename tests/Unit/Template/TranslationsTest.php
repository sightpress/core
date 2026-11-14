<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\Translations;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(Translations::class)]
final class TranslationsTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->directory->write('messages.pt-BR.php', "<?php return ['Go to the home page' => 'Ir para a página inicial', "
            . "'{count, plural, one {# page} other {# pages}}' => '{count, plural, one {# página} other {# páginas}}'];");
        $this->directory->write('admin.cy.php', "<?php return ['Save' => 'Cadw'];");
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testTranslatesAndFallsBackToTheEnglishText(): void
    {
        $translations = Translations::fromDirectories([$this->directory->path, '/does/not/exist']);

        self::assertSame('Ir para a página inicial', $translations->translate('Go to the home page', [], 'pt-BR'));
        self::assertSame('Go to the home page', $translations->translate('Go to the home page', [], 'cy'));
        self::assertSame('Cadw', $translations->translate('Save', [], 'cy', 'admin'));
        self::assertSame('Save', $translations->translate('Save', [], 'pt-BR', 'admin'));
    }

    public function testFormatsPluralsAndPlaceholdersInTheViewersLanguage(): void
    {
        $translations = Translations::fromDirectories([$this->directory->path]);
        $plural = '{count, plural, one {# page} other {# pages}}';

        self::assertSame('1 page', $translations->translate($plural, ['count' => 1], 'en-GB'));
        self::assertSame('3 pages', $translations->translate($plural, ['count' => 3], 'en-GB'));
        self::assertSame('2 páginas', $translations->translate($plural, ['count' => 2], 'pt-BR'));
        self::assertSame('Hello, Ada', $translations->translate('Hello, {name}', ['name' => 'Ada'], 'en-GB'));
        self::assertSame('Broken {', $translations->translate('Broken {', ['x' => 1], 'en-GB'), 'invalid patterns are shown as they are');
    }
}
