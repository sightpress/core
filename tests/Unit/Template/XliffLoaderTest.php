<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\XliffLoader;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use Symfony\Component\Translation\Exception\InvalidResourceException;
use Symfony\Component\Translation\Exception\NotFoundResourceException;

#[CoversClass(XliffLoader::class)]
final class XliffLoaderTest extends TestCase
{
    public function testReadsTheTranslatedUnits(): void
    {
        $directory = new TemporaryDirectory();
        $directory->write('messages.es.xlf', '<?xml version="1.0" encoding="UTF-8"?><xliff xmlns="urn:oasis:names:tc:xliff:document:2.0" version="2.0" srcLang="en-GB" trgLang="es"><file id="m">'
            . '<unit id="1"><segment><source>Save</source><target>Guardar</target></segment></unit>'
            . '<unit id="2"><segment state="initial"><source>Later</source><target></target></segment></unit>'
            . '</file></xliff>');

        $catalogue = new XliffLoader()->load($directory->path . '/messages.es.xlf', 'es', 'admin');

        self::assertSame('es', $catalogue->getLocale());
        self::assertSame(['Save' => 'Guardar'], $catalogue->all('admin'));
        $directory->remove();
    }

    public function testRefusesWhatIsNotACatalogue(): void
    {
        $directory = new TemporaryDirectory();
        $directory->write('broken.xlf', '<xliff');
        try {
            new XliffLoader()->load($directory->path . '/broken.xlf', 'es');
            self::fail('not XML');
        } catch (InvalidResourceException) {
            $this->addToAssertionCount(1);
        }
        $directory->remove();

        $this->expectException(NotFoundResourceException::class);
        new XliffLoader()->load(['not a file'], 'es');
    }
}
