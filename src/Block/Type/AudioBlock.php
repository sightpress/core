<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use SightPress\Core\Block\Html;
use SightPress\Core\Block\Rule\AudioTranscriptRule;
use SightPress\Core\Block\Schema\SchemaConstraints;
use SightPress\Core\Block\SiteBlockContext;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockStyles;
use SightPress\Module\Block\StyledBlockType;

/**
 * An audio file from the media library (a talk, a podcast episode) with a title and a transcript, which is needed
 * before the page can be published. It never plays by itself.
 */
final class AudioBlock implements StyledBlockType
{
    public function name(): string
    {
        return 'audio';
    }

    public function label(): string
    {
        return 'Audio';
    }

    public function version(): int
    {
        return 1;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['media', 'title'],
            'properties' => [
                'media' => ['title' => 'Audio', 'description' => 'A sound file from the media library.', 'type' => 'integer', 'format' => SchemaConstraints::FORMAT_AUDIO],
                'title' => ['title' => 'Title', 'description' => 'Names the recording for screen readers and search engines.', 'type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'transcript' => ['title' => 'Transcript', 'description' => 'Everything said, as text, for people who can\'t hear the recording.', 'type' => 'string', 'maxLength' => VideoBlock::TRANSCRIPT_MAX_LENGTH],
            ],
        ];
    }

    public function styles(): BlockStyles
    {
        return new BlockStyles([], [BlockOption::Spacing]);
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        $audio = MediaPlayer::source($data, 'media', $context, 'audio/');
        if ($audio === null) {
            return '';
        }
        $title = Html::text($data, 'title');
        $source = '<source src="' . Html::e($audio['url']) . '"' . ($audio['type'] === null ? '' : ' type="' . Html::e($audio['type']) . '"') . '>';

        return '<figure class="block-audio"><figcaption>' . Html::e($title) . '</figcaption>'
            . '<audio controls preload="metadata">' . $source
            . '<p>Your browser can\'t play this audio. <a href="' . Html::e($audio['url']) . '">Download the audio: ' . Html::e($title) . '</a>.</p></audio>'
            . MediaPlayer::transcript(Html::text($data, 'transcript'), $title)
            . '</figure>';
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        $audio = MediaPlayer::source($data, 'media', $context, 'audio/');

        return $audio === null ? '' : MediaPlayer::markdown('Audio', Html::text($data, 'title'), $audio['url'], Html::text($data, 'transcript'));
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        $audio = MediaPlayer::source($data, 'media', $context, 'audio/');
        if ($audio === null) {
            return null;
        }
        $node = ['@type' => 'AudioObject', 'name' => Html::text($data, 'title'), 'contentUrl' => $audio['url']];
        if ($audio['type'] !== null) {
            $node['encodingFormat'] = $audio['type'];
        }
        $file = $context instanceof SiteBlockContext && is_int($data['media']) ? $context->file($data['media']) : null;
        if ($file !== null) {
            $node['uploadDate'] = $file->createdAt()->format(DATE_ATOM);
        }
        $transcript = trim(Html::text($data, 'transcript'));
        if ($transcript !== '') {
            $node['transcript'] = $transcript;
        }

        return $node;
    }

    public function a11yRules(): array
    {
        return [new AudioTranscriptRule()];
    }
}
