<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use SightPress\Core\Block\Html;
use SightPress\Core\Block\SiteBlockContext;
use SightPress\Module\Block\BlockContext;

/**
 * What the video and audio blocks share: the file's address and type, the transcript as a disclosure, Markdown.
 */
final class MediaPlayer
{
    /**
     * Address and MIME type of a file referenced by `$data[$key]`; on a site only published files of the right
     * kind (`$mimePrefix`, such as `video/`) count.
     *
     * @param array<string, mixed> $data
     *
     * @return array{url: string, type: ?string}|null
     */
    public static function source(array $data, string $key, BlockContext $context, string $mimePrefix): ?array
    {
        $id = $data[$key] ?? null;
        if (!is_int($id)) {
            return null;
        }
        if (!$context instanceof SiteBlockContext) {
            $url = $context->mediaUrl((string) $id);

            return $url === null ? null : ['url' => $url, 'type' => null];
        }
        $file = $context->file($id);
        $url = $context->mediaUrl((string) $id);
        if ($file === null || $url === null || !str_starts_with($file->mimeType(), $mimePrefix)) {
            return null;
        }

        return ['url' => $url, 'type' => $file->mimeType()];
    }

    /**
     * The address of a published WebVTT file for a `<track>`.
     */
    public static function captionsUrl(mixed $id, BlockContext $context): ?string
    {
        if (!is_int($id)) {
            return null;
        }
        if (!$context instanceof SiteBlockContext) {
            return $context->mediaUrl((string) $id);
        }
        $file = $context->file($id);

        return $file !== null && $file->mimeType() === 'text/vtt' ? $context->captionsUrl($file) : null;
    }

    /**
     * The transcript as a closed disclosure, named after the title so several on a page can be told apart.
     */
    public static function transcript(string $transcript, string $title): string
    {
        if (trim($transcript) === '') {
            return '';
        }

        return '<details class="disclosure block-transcript"><summary>Transcript<span class="visually-hidden">: ' . Html::e($title)
            . '</span></summary><div class="disclosure__content">' . Html::paragraphs($transcript) . '</div></details>';
    }

    /**
     * `[Video: title](url)` and the transcript.
     */
    public static function markdown(string $kind, string $title, ?string $url, string $transcript): string
    {
        $heading = $kind . ': ' . Html::markdown($title);
        $markdown = $url === null ? '**' . $heading . '**' : '[' . $heading . '](' . $url . ')';
        if (trim($transcript) !== '') {
            $markdown .= "\n\nTranscript:\n\n" . Html::markdownParagraphs($transcript);
        }

        return $markdown;
    }
}
