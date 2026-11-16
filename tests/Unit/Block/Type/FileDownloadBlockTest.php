<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Rule\LinkTextRule;
use SightPress\Core\Block\Type\FileDownloadBlock;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PlainBlockContext;

#[CoversClass(FileDownloadBlock::class)]
#[UsesNamespace('SightPress\Core')]
final class FileDownloadBlockTest extends TestCase
{
    public function testDescribesItself(): void
    {
        $type = new FileDownloadBlock();

        self::assertSame(['file_download', 'File download', 1], [$type->name(), $type->label(), $type->version()]);
        self::assertSame(['media', 'text'], $type->schema()['required']);
        self::assertSame(['text' => 'A'], $type->upgradeData(1, ['text' => 'A']));
        self::assertNull($type->structuredData([], new PlainBlockContext()));
        self::assertInstanceOf(LinkTextRule::class, $type->a11yRules()[0]);
    }

    public function testNamesTheTypeAndSizeOfTheFile(): void
    {
        $fixture = new BlockFixture();
        $report = $fixture->addMedia(kind: MediaKind::Document, fileName: 'report.pdf', size: 2_411_725);
        $url = 'https://demo.example/media/' . $fixture->key($report) . '/report.pdf';
        $type = new FileDownloadBlock();

        self::assertSame(
            '<p class="block-file-download"><a href="' . $url . '" download>Annual report &amp; accounts <span class="block-file-download__details">(PDF, 2.3 MB)</span></a></p>',
            $type->render(['media' => $report, 'text' => 'Annual report & accounts'], $fixture->context()),
        );
        self::assertSame('[Annual report (PDF, 2,3 MB)](' . str_replace('demo.example/', 'demo.example/', $url) . ')', $type->renderMarkdown(['media' => $report, 'text' => 'Annual report'], $fixture->context('pt-BR')));
        self::assertSame('', $type->render(['media' => 99999, 'text' => 'Gone'], $fixture->context()));
        self::assertSame('', $type->renderMarkdown(['media' => 'x', 'text' => 'Gone'], $fixture->context()));
    }

    public function testWorksWithAnyContext(): void
    {
        $type = new FileDownloadBlock();

        self::assertSame('<p class="block-file-download"><a href="https://cdn.example/3" download>Report</a></p>', $type->render(['media' => 3, 'text' => 'Report'], new PlainBlockContext()));
        self::assertSame('[Report](https://cdn.example/3)', $type->renderMarkdown(['media' => 3, 'text' => 'Report'], new PlainBlockContext()));
    }
}
