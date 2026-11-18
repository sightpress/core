<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\TemporaryDirectory;

/**
 * The catalogue lint in `composer check` (I-3, `tools/translations.php`): it fails on a text that is used but not in
 * the en-GB catalogue, on one the catalogue has but nothing uses, on a shipped catalogue without a translation, and on
 * text built at run time where people read it; `update` brings the catalogues in line again. Core's own catalogues
 * pass.
 */
#[CoversNothing]
final class TranslationLintTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->directory->write('src/Http/Greeting.php', <<<'PHP'
            <?php
            use SightPress\Core\Template\Message;
            final class Greeting
            {
                public function texts(string $name): array
                {
                    Flash::add($session, sprintf('Hello %s', $name));

                    return [new Message('Hello {name}', ['name' => $name]), new Field('name', 'Your name')];
                }
            }
            PHP);
        $this->directory->write('views/page.html.twig', "<p>{{ t('Goodbye') }}</p>");
        $this->catalogue('en-GB', ['Hello {name}' => 'Hello {name}', 'Your name' => 'Your name', 'Unused text' => 'Unused text']);
        $this->catalogue('es', ['Hello {name}' => 'Hola {nombre}', 'Your name' => '']);
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testFailsOnMissingUnusedAndUntranslatedTexts(): void
    {
        [$status, $errors] = $this->lint('check');

        self::assertSame(1, $status);
        self::assertStringContainsString('Missing key "Goodbye"', $errors);
        self::assertStringContainsString('Unused key "Unused text"', $errors);
        self::assertStringContainsString('es: no translation of "Your name".', $errors);

        self::assertStringContainsString('es: the translation of "Hello {name}" has other placeholders: "Hola {nombre}".', $errors);
        self::assertStringContainsString('Greeting.php:7: add() gets text built at run time; use a Message with values.', $errors);
    }

    public function testUpdateBringsTheCataloguesInLine(): void
    {
        [$status, $output] = $this->lint('update');
        self::assertSame(1, $status, 'the text built at run time is still reported');
        self::assertStringContainsString('es: 3 texts, 2 to translate.', $output);
        $source = (string) file_get_contents($this->directory->path . '/catalogues/messages.en-GB.xlf');
        self::assertStringContainsString('<source>Goodbye</source>', $source);
        self::assertStringNotContainsString('Unused text', $source);
    }

    public function testCoresOwnCataloguesPass(): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/tools/translations.php', 'check'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);

        self::assertSame(0, proc_close($process), $errors);
        self::assertStringContainsString('2 catalogues besides en-GB, 0 problems', $output);
    }

    /**
     * @return array{int, string} exit code and everything printed
     */
    private function lint(string $command): array
    {
        $path = $this->directory->path;
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/tools/translations.php', '--src=' . $path . '/src', '--views=' . $path . '/views', '--catalogues=' . $path . '/catalogues', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return [proc_close($process), $output];
    }

    /**
     * @param array<string, string> $units
     */
    private function catalogue(string $locale, array $units): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><xliff xmlns="urn:oasis:names:tc:xliff:document:2.0" version="2.0" srcLang="en-GB" trgLang="' . $locale . '"><file id="messages">';
        foreach ($units as $source => $target) {
            $xml .= '<unit id="' . md5($source) . '"><segment><source>' . htmlspecialchars($source) . '</source><target>' . htmlspecialchars($target) . '</target></segment></unit>';
        }
        $this->directory->write('catalogues/messages.' . $locale . '.xlf', $xml . '</file></xliff>');
    }
}
