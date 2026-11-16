<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Rule\VideoRule;
use SightPress\Core\Block\Type\MediaPlayer;
use SightPress\Core\Block\Type\VideoBlock;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PlainBlockContext;
use SightPress\Module\Block\A11ySeverity;

#[CoversClass(VideoBlock::class)]
#[CoversClass(VideoRule::class)]
#[CoversClass(MediaPlayer::class)]
#[UsesNamespace('SightPress\Core')]
final class VideoBlockTest extends TestCase
{
    public function testDescribesItself(): void
    {
        $type = new VideoBlock();

        self::assertSame(['video', 'Video', 1], [$type->name(), $type->label(), $type->version()]);
        self::assertSame(['media', 'title'], $type->schema()['required']);
        self::assertSame(['title' => 'A'], $type->upgradeData(1, ['title' => 'A']));
        self::assertInstanceOf(VideoRule::class, $type->a11yRules()[0]);
    }

    public function testRendersAVideoWithCaptionsPosterAndTranscript(): void
    {
        $fixture = new BlockFixture();
        $video = $fixture->addMedia(kind: MediaKind::Video, fileName: 'tour.mp4');
        $captions = $fixture->addCaptions();
        $poster = $fixture->addMedia();
        $type = new VideoBlock();
        $context = $fixture->context();
        $data = ['media' => $video, 'title' => 'A tour of the harbour', 'captions' => $captions, 'poster' => $poster, 'transcript' => "Welcome.\nLine two.\n\nSecond <part>."];

        $base = 'https://demo.example/media/';
        self::assertSame(
            '<figure class="block-video"><video controls preload="metadata" poster="' . $base . $fixture->key($poster) . '/lighthouse.jpg">'
            . '<source src="' . $base . $fixture->key($video) . '/tour.mp4" type="video/mp4">'
            . '<track kind="captions" src="' . $base . $fixture->key($captions) . '/captions.vtt" srclang="en-GB" label="English (United Kingdom)" default>'
            . '<p>Your browser can\'t play this video. <a href="' . $base . $fixture->key($video) . '/tour.mp4">Download the video: A tour of the harbour</a>.</p></video>'
            . '<figcaption>A tour of the harbour</figcaption>'
            . '<details class="disclosure block-transcript"><summary>Transcript<span class="visually-hidden">: A tour of the harbour</span></summary><div class="disclosure__content"><p>Welcome.<br>Line two.</p><p>Second &lt;part&gt;.</p></div></details></figure>',
            $type->render($data, $context),
        );
        self::assertStringContainsString('srclang="pt-PT" label="português (Portugal)"', $type->render(['captions_language' => 'pt-PT'] + $data, $context));
        self::assertStringContainsString('srclang="zz" label="zz"', $type->render(['captions_language' => 'zz'] + $data, $context));
        self::assertStringNotContainsString('<track', $type->render(['no_speech' => true] + $data, $context));
        self::assertStringNotContainsString('<track', $type->render(['captions' => $poster] + $data, $context));
        self::assertStringNotContainsString('poster=', $type->render(['media' => $video, 'title' => 'T'], $context));
        self::assertStringNotContainsString('<details', $type->render(['media' => $video, 'title' => 'T', 'transcript' => ' '], $context));
    }

    public function testLeavesOutFilesItCannotPlay(): void
    {
        $fixture = new BlockFixture();
        $draft = $fixture->addMedia(published: false, kind: MediaKind::Video);
        $picture = $fixture->addMedia();
        $type = new VideoBlock();

        foreach ([['media' => $draft, 'title' => 'T'], ['media' => $picture, 'title' => 'T'], ['media' => 'x', 'title' => 'T'], ['media' => 99999, 'title' => 'T']] as $data) {
            self::assertSame('', $type->render($data, $fixture->context()));
            self::assertSame('', $type->renderMarkdown($data, $fixture->context()));
            self::assertNull($type->structuredData($data, $fixture->context()));
        }
        self::assertSame('', $type->render(['media' => 3, 'title' => 'T'], new PlainBlockContext(['3'])));
    }

    public function testWorksWithAnyContext(): void
    {
        $type = new VideoBlock();
        $context = new PlainBlockContext();

        self::assertSame(
            '<figure class="block-video"><video controls preload="metadata"><source src="https://cdn.example/3"><track kind="captions" src="https://cdn.example/4" srclang="en-GB" label="English (United Kingdom)" default><p>Your browser can\'t play this video. <a href="https://cdn.example/3">Download the video: T</a>.</p></video><figcaption>T</figcaption></figure>',
            $type->render(['media' => 3, 'title' => 'T', 'captions' => 4], $context),
        );
        self::assertSame("[Video: A \\*tour\\*](https://cdn.example/3)\n\nTranscript:\n\nOne.\n\nTwo \\_x\\_.", $type->renderMarkdown(['media' => 3, 'title' => 'A *tour*', 'transcript' => "One.\n\n\nTwo _x_."], $context));
        self::assertSame(['@type' => 'VideoObject', 'name' => 'T', 'contentUrl' => 'https://cdn.example/3', 'thumbnailUrl' => 'https://cdn.example/5', 'transcript' => 'Hello'], $type->structuredData(['media' => 3, 'title' => 'T', 'poster' => 5, 'transcript' => ' Hello '], $context));
    }

    public function testStructuredDataOnTheSiteHasTheUploadDate(): void
    {
        $fixture = new BlockFixture();
        $video = $fixture->addMedia(kind: MediaKind::Video, fileName: 'tour.mp4');

        self::assertSame(
            ['@type' => 'VideoObject', 'name' => 'T', 'contentUrl' => 'https://demo.example/media/' . $fixture->key($video) . '/tour.mp4', 'uploadDate' => '2026-11-16T10:00:00+00:00'],
            new VideoBlock()->structuredData(['media' => $video, 'title' => 'T'], $fixture->context()),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<array{string, A11ySeverity}>}>
     */
    public static function videos(): iterable
    {
        yield 'complete' => [['captions' => 1, 'transcript' => 'Words'], []];
        yield 'no captions' => [['transcript' => 'Words'], [['video.captions_missing', A11ySeverity::Error]]];
        yield 'nothing' => [[], [['video.captions_missing', A11ySeverity::Error], ['video.transcript_missing', A11ySeverity::Warning]]];
        yield 'no speech, described' => [['no_speech' => true, 'transcript' => 'A boat sails by'], []];
        yield 'no speech, not described' => [['no_speech' => true, 'transcript' => ' '], [['video.description_missing', A11ySeverity::Error]]];
    }

    /**
     * @param array<string, mixed>                  $data
     * @param list<array{string, A11ySeverity}> $expected
     */
    #[DataProvider('videos')]
    public function testNeedsCaptionsOrADescription(array $data, array $expected): void
    {
        self::assertSame($expected, array_map(static fn ($issue): array => [$issue->rule, $issue->severity], new VideoRule()->check($data)));
    }
}
