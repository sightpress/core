<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Type\AudioBlock;
use SightPress\Core\Block\Type\FileDownloadBlock;
use SightPress\Core\Block\Type\GalleryBlock;
use SightPress\Core\Block\Type\VideoBlock;
use SightPress\Core\Contact\ContactFormBlock;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Page\Contract\MarkdownAddress;
use SightPress\Core\Page\PageMarkdown;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(PageMarkdown::class)]
#[CoversClass(MarkdownAddress::class)]
#[UsesNamespace('SightPress\Core')]
final class PageMarkdownTest extends TestCase
{
    /**
     * Golden file: a page with every core block type. To update it after a deliberate change, delete
     * `golden/every-block.md` and run the test once.
     */
    public function testAPageWithEveryBlockTypeAsMarkdown(): void
    {
        $blocks = new BlockFixture([new GalleryBlock(), new VideoBlock(), new AudioBlock(), new FileDownloadBlock(), new ContactFormBlock(new AuthFixture()->templates)]);
        $picture = $blocks->addMedia();
        $video = $blocks->addMedia(kind: MediaKind::Video, fileName: 'tour.mp4');
        $audio = $blocks->addMedia(kind: MediaKind::Audio, fileName: 'talk.mp3');
        $report = $blocks->addMedia(kind: MediaKind::Document, fileName: 'annual-report.pdf', size: 2_400_000);
        $captions = $blocks->addCaptions();
        $site = PageFixture::site(['en-GB']);
        $markdown = new PageMarkdown($blocks->contexts($site), $blocks->renderer())->document('en-GB', "Visit  the\n library", '  Books, music and the harbour. ', [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Around the library', 'level' => 2]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>We are open <strong>every</strong> day. Find out <a href="/about">who we are</a>.</p><ul><li>Books</li><li>Music</li></ul>']],
            ['type' => 'call_to_action', 'version' => 1, 'data' => ['text' => 'Book a visit', 'url' => '/book']],
            ['type' => 'image', 'version' => 1, 'data' => ['media' => $picture, 'alt' => 'The harbour from the reading room', 'caption' => 'The view from the first floor']],
            ['type' => 'gallery', 'version' => 1, 'data' => ['images' => [['media' => $picture, 'alt' => 'Boats in the harbour'], ['media' => $picture, 'decorative' => true]], 'caption' => 'The harbour through the day']],
            ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [
                ['blocks' => [['type' => 'video', 'version' => 1, 'data' => ['media' => $video, 'title' => 'A tour', 'captions' => $captions, 'transcript' => "Welcome.\n\nThe reading room is upstairs."]]]],
                ['blocks' => [['type' => 'audio', 'version' => 1, 'data' => ['media' => $audio, 'title' => 'Opening talk', 'transcript' => 'Good evening.']]]],
            ]]],
            ['type' => 'file_download', 'version' => 1, 'data' => ['media' => $report, 'text' => 'Annual report 2025']],
            ['type' => 'contact_form', 'version' => 1, 'data' => ['recipient' => 'office@example.org']],
        ], 'https://demo.example/visit', new DateTimeImmutable('2026-11-16 23:30:00 Europe/Lisbon'), 'https://demo.example/');
        foreach ([$picture, $video, $audio, $report, $captions] as $index => $id) {
            $markdown = str_replace($blocks->key($id), 'KEY' . ($index + 1), $markdown);
        }

        $golden = __DIR__ . '/golden/every-block.md';
        if (!is_file($golden)) {
            file_put_contents($golden, $markdown);
        }
        self::assertSame(file_get_contents($golden), $markdown);
        self::assertStringNotContainsString('office@example.org', $markdown, 'forms give nothing, and never the recipient');
    }

    public function testAPageWithoutSummaryOrBlocks(): void
    {
        $blocks = new BlockFixture();

        self::assertSame(
            "# Empty\n\n---\n\nSource: <https://demo.example/empty>  \nLast updated: 2026-11-16\n",
            new PageMarkdown($blocks->contexts(), $blocks->renderer())->document('en-GB', 'Empty', null, [], 'https://demo.example/empty', new DateTimeImmutable('2026-11-16 10:00 UTC'), 'https://demo.example'),
        );
        self::assertSame('/index.md', MarkdownAddress::of('/', true));
        self::assertSame('/pt-br/index.md', MarkdownAddress::of('/pt-br', true));
        self::assertSame('/about/team.md', MarkdownAddress::of('/about/team', false));
    }
}
