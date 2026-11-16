<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use Locale;
use SightPress\Core\Block\Html;
use SightPress\Core\Block\Rule\VideoRule;
use SightPress\Core\Block\Schema\SchemaConstraints;
use SightPress\Core\Block\SiteBlockContext;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockType;

/**
 * A video from the media library with a title, a captions track (WebVTT) unless it has no speech, an optional
 * poster picture and a transcript. It never plays by itself; the browser's own controls work by keyboard.
 */
final class VideoBlock implements BlockType
{
    public const int TRANSCRIPT_MAX_LENGTH = 100_000;

    public const string LANGUAGE_PATTERN = '^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$';

    public function name(): string
    {
        return 'video';
    }

    public function label(): string
    {
        return 'Video';
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
                'media' => ['type' => 'integer', 'format' => SchemaConstraints::FORMAT_VIDEO],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'captions' => ['type' => 'integer', 'format' => SchemaConstraints::FORMAT_CAPTIONS],
                'captions_language' => ['type' => 'string', 'maxLength' => 35, 'pattern' => self::LANGUAGE_PATTERN],
                'no_speech' => ['type' => 'boolean'],
                'poster' => ['type' => 'integer', 'format' => SchemaConstraints::FORMAT_IMAGE],
                'transcript' => ['type' => 'string', 'maxLength' => self::TRANSCRIPT_MAX_LENGTH],
            ],
        ];
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        $video = MediaPlayer::source($data, 'media', $context, 'video/');
        if ($video === null) {
            return '';
        }
        $title = Html::text($data, 'title');
        $attributes = ' controls preload="metadata"';
        $poster = is_int($data['poster'] ?? null) ? $context->mediaUrl((string) $data['poster']) : null;
        if ($poster !== null) {
            $attributes .= ' poster="' . Html::e($poster) . '"';
        }
        $inner = '<source src="' . Html::e($video['url']) . '"' . ($video['type'] === null ? '' : ' type="' . Html::e($video['type']) . '"') . '>';
        $captions = ($data['no_speech'] ?? false) === true ? null : MediaPlayer::source($data, 'captions', $context, 'text/vtt');
        if ($captions !== null) {
            $language = self::captionsLanguage($data, $context);
            $inner .= sprintf(
                '<track kind="captions" src="%s" srclang="%s" label="%s" default>',
                Html::e($captions['url']),
                Html::e($language),
                Html::e(self::languageName($language)),
            );
        }
        $inner .= '<p>Your browser can\'t play this video. <a href="' . Html::e($video['url']) . '">Download the video: ' . Html::e($title) . '</a>.</p>';

        return '<figure class="block-video"><video' . $attributes . '>' . $inner . '</video>'
            . '<figcaption>' . Html::e($title) . '</figcaption>'
            . MediaPlayer::transcript(Html::text($data, 'transcript'), $title)
            . '</figure>';
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        $video = MediaPlayer::source($data, 'media', $context, 'video/');

        return $video === null ? '' : MediaPlayer::markdown('Video', Html::text($data, 'title'), $video['url'], Html::text($data, 'transcript'));
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        $video = MediaPlayer::source($data, 'media', $context, 'video/');
        if ($video === null) {
            return null;
        }
        $node = ['@type' => 'VideoObject', 'name' => Html::text($data, 'title'), 'contentUrl' => $video['url']];
        $poster = is_int($data['poster'] ?? null) ? $context->mediaUrl((string) $data['poster']) : null;
        if ($poster !== null) {
            $node['thumbnailUrl'] = $poster;
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
        return [new VideoRule()];
    }

    private static function languageName(string $language): string
    {
        $name = Locale::getDisplayName($language, $language);

        return is_string($name) && $name !== '' ? $name : $language;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function captionsLanguage(array $data, BlockContext $context): string
    {
        $language = Html::text($data, 'captions_language');

        return $language === '' ? $context->locale() : $language;
    }
}
