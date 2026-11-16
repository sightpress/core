<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Rule\AudioTranscriptRule;
use SightPress\Core\Block\Type\AudioBlock;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PlainBlockContext;
use SightPress\Module\Block\A11ySeverity;

#[CoversClass(AudioBlock::class)]
#[CoversClass(AudioTranscriptRule::class)]
#[UsesNamespace('SightPress\Core')]
final class AudioBlockTest extends TestCase
{
    public function testDescribesItself(): void
    {
        $type = new AudioBlock();

        self::assertSame(['audio', 'Audio', 1], [$type->name(), $type->label(), $type->version()]);
        self::assertSame(['media', 'title'], $type->schema()['required']);
        self::assertSame(['title' => 'A'], $type->upgradeData(1, ['title' => 'A']));
        self::assertInstanceOf(AudioTranscriptRule::class, $type->a11yRules()[0]);
    }

    public function testRendersAPlayerWithItsTranscript(): void
    {
        $fixture = new BlockFixture();
        $audio = $fixture->addMedia(kind: MediaKind::Audio, fileName: 'talk.mp3');
        $url = 'https://demo.example/media/' . $fixture->key($audio) . '/talk.mp3';
        $type = new AudioBlock();
        $data = ['media' => $audio, 'title' => 'Opening talk', 'transcript' => 'Good evening.'];

        self::assertSame(
            '<figure class="block-audio"><figcaption>Opening talk</figcaption><audio controls preload="metadata"><source src="' . $url . '" type="audio/mpeg">'
            . '<p>Your browser can\'t play this audio. <a href="' . $url . '">Download the audio: Opening talk</a>.</p></audio>'
            . '<details class="disclosure block-transcript"><summary>Transcript<span class="visually-hidden">: Opening talk</span></summary><div class="disclosure__content"><p>Good evening.</p></div></details></figure>',
            $type->render($data, $fixture->context()),
        );
        self::assertSame("[Audio: Opening talk]({$url})\n\nTranscript:\n\nGood evening.", $type->renderMarkdown($data, $fixture->context()));
        self::assertSame(
            ['@type' => 'AudioObject', 'name' => 'Opening talk', 'contentUrl' => $url, 'encodingFormat' => 'audio/mpeg', 'uploadDate' => '2026-11-16T10:00:00+00:00', 'transcript' => 'Good evening.'],
            $type->structuredData($data, $fixture->context()),
        );
        $video = $fixture->addMedia(kind: MediaKind::Video);
        self::assertSame('', $type->render(['media' => $video, 'title' => 'T'], $fixture->context()));
        self::assertSame('', $type->renderMarkdown(['media' => $video, 'title' => 'T'], $fixture->context()));
        self::assertNull($type->structuredData(['media' => $video, 'title' => 'T'], $fixture->context()));
    }

    public function testWorksWithAnyContext(): void
    {
        $type = new AudioBlock();

        self::assertStringContainsString('<source src="https://cdn.example/3">', $type->render(['media' => 3, 'title' => 'T'], new PlainBlockContext()));
        self::assertSame('[Audio: T](https://cdn.example/3)', $type->renderMarkdown(['media' => 3, 'title' => 'T'], new PlainBlockContext()));
        self::assertSame(['@type' => 'AudioObject', 'name' => 'T', 'contentUrl' => 'https://cdn.example/3'], $type->structuredData(['media' => 3, 'title' => 'T'], new PlainBlockContext()));
    }

    public function testNeedsATranscript(): void
    {
        $rule = new AudioTranscriptRule();

        self::assertSame([], $rule->check(['transcript' => 'Words']));
        $issues = $rule->check(['transcript' => "\n"]);
        self::assertSame(['audio.transcript_missing', A11ySeverity::Error, '/transcript', ['1.2.1']], [$issues[0]->rule, $issues[0]->severity, $issues[0]->field, $issues[0]->wcag]);
    }
}
