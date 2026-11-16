<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\FileName;

#[CoversClass(FileName::class)]
final class FileNameTest extends TestCase
{
    public function testMakesUrlSafeNamesWithTheDetectedExtension(): void
    {
        self::assertSame('relatorio-anual-2026.pdf', FileName::safe('Relatório Anual 2026.PDF', 'pdf'));
        self::assertSame('photo.png', FileName::safe('C:\fakepath\photo.jpg', 'png'));
        self::assertSame('moskva.jpg', FileName::safe('../../Москва.jpg', 'jpg'));
        self::assertSame('file.txt', FileName::safe('…', 'txt'));
        self::assertSame('file.txt', FileName::safe('', 'txt'));
        self::assertSame(str_repeat('a', 80) . '.txt', FileName::safe(str_repeat('a', 200) . '.txt', 'txt'));
        self::assertSame(str_repeat('a', 79) . '.txt', FileName::safe(str_repeat('a', 79) . '-b.txt', 'txt'));
    }

    public function testKeepsTheOriginalNameWithoutFoldersOrControlCharacters(): void
    {
        self::assertSame('Relatório Anual.pdf', FileName::original('/home/ada/Relatório Anual.pdf', 'x.pdf'));
        self::assertSame('evil.txt', FileName::original("ev\u{202E}il\x00.txt", 'x.txt'));
        self::assertSame('x.pdf', FileName::original("bad \xC3\x28.pdf", 'x.pdf'));
        self::assertSame('x.pdf', FileName::original('  ', 'x.pdf'));
        self::assertSame(255, mb_strlen(FileName::original(str_repeat('é', 300), 'x')));
    }
}
