<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use SightPress\Core\Block\Html;
use SightPress\Core\Block\Schema\SchemaConstraints;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockStyles;
use SightPress\Module\Block\StyledBlockType;

/**
 * A video in a sign language (BSL, ISL, LSF …), for example the page's content signed. The sign language is chosen
 * from a list and tagged (ISO 639-3, also in the `VideoObject`'s `inLanguage`), and shown in words under the video,
 * so readers know what it is before they play it. Captions are still required: not everyone who signs reads the
 * same sign language, and captions serve people who don't sign. Drawn like a video block. Language names are in
 * en-GB, like the rest of the text core's blocks draw.
 */
final class SignLanguageVideoBlock implements StyledBlockType
{
    /** Choice => [ISO 639-3 code, name in English]. */
    public const array LANGUAGES = [
        'british' => ['bfi', 'British Sign Language (BSL)'],
        'irish' => ['isg', 'Irish Sign Language (ISL)'],
        'american' => ['ase', 'American Sign Language (ASL)'],
        'french' => ['fsl', 'French Sign Language (LSF)'],
        'german' => ['gsg', 'German Sign Language (DGS)'],
        'spanish' => ['ssp', 'Spanish Sign Language (LSE)'],
        'portuguese' => ['psr', 'Portuguese Sign Language (LGP)'],
        'italian' => ['ise', 'Italian Sign Language (LIS)'],
        'dutch' => ['dse', 'Sign Language of the Netherlands (NGT)'],
        'ukrainian' => ['ukl', 'Ukrainian Sign Language (USL)'],
        'israeli' => ['isr', 'Israeli Sign Language'],
        'international' => ['ils', 'International Sign'],
    ];

    private VideoBlock $video;

    public function __construct()
    {
        $this->video = new VideoBlock();
    }

    public function name(): string
    {
        return 'sign_language_video';
    }

    public function label(): string
    {
        return 'Sign language video';
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
            'required' => ['media', 'title', 'sign_language', 'captions'],
            'properties' => [
                'media' => ['title' => 'Video', 'description' => 'A video from the media library.', 'type' => 'integer', 'format' => SchemaConstraints::FORMAT_VIDEO],
                'title' => ['title' => 'Title', 'description' => 'Names the video, for example "This page in British Sign Language".', 'type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'sign_language' => ['title' => 'Sign language', 'description' => 'The sign language the video is in. It is shown under the video.', 'enum' => array_keys(self::LANGUAGES)],
                'captions' => ['title' => 'Captions', 'description' => 'A WebVTT captions file (.vtt) from the media library, for people who don\'t sign or use another sign language.', 'type' => 'integer', 'format' => SchemaConstraints::FORMAT_CAPTIONS],
                'captions_language' => ['title' => 'Language of the captions', 'description' => 'A language tag such as en-GB. Leave it empty for the page\'s language.', 'type' => 'string', 'maxLength' => 35, 'pattern' => VideoBlock::LANGUAGE_PATTERN],
                'poster' => ['title' => 'Poster picture', 'description' => 'Shown before the video plays.', 'type' => 'integer', 'format' => SchemaConstraints::FORMAT_IMAGE],
                'transcript' => ['title' => 'Transcript', 'description' => 'What is signed, as text.', 'type' => 'string', 'maxLength' => VideoBlock::TRANSCRIPT_MAX_LENGTH],
            ],
        ];
    }

    public function styles(): BlockStyles
    {
        return new BlockStyles([], [BlockOption::Width, BlockOption::Spacing]);
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        $language = self::language($data);
        $html = $this->video->render(self::asVideo($data), $context);
        if ($html === '' || $language === null) {
            return $html;
        }
        $title = Html::e(Html::text($data, 'title'));

        return str_replace(
            ['<figure class="block-video">', '<figcaption>' . $title . '</figcaption>'],
            [
                '<figure class="block-video block-sign-language-video" data-sign-language="' . $language[0] . '">',
                '<figcaption>' . $title . ' <span class="block-sign-language-video__language">(' . Html::e($language[1]) . ')</span></figcaption>',
            ],
            $html,
        );
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        $video = MediaPlayer::source($data, 'media', $context, 'video/');
        $language = self::language($data);

        return $video === null ? '' : MediaPlayer::markdown(
            $language === null ? 'Video' : 'Video in ' . $language[1],
            Html::text($data, 'title'),
            $video['url'],
            Html::text($data, 'transcript'),
        );
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        $language = self::language($data);
        $node = $this->video->structuredData(self::asVideo($data), $context);

        return $node === null || $language === null ? $node : $node + ['inLanguage' => $language[0]];
    }

    public function a11yRules(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{string, string}|null
     */
    private static function language(array $data): ?array
    {
        $choice = $data['sign_language'] ?? null;

        return is_string($choice) ? self::LANGUAGES[$choice] ?? null : null;
    }

    /**
     * The data as a video block reads it: always with captions.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function asVideo(array $data): array
    {
        unset($data['sign_language']);

        return $data;
    }
}
