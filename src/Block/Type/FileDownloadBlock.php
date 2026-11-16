<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use SightPress\Core\Block\Html;
use SightPress\Core\Block\Rule\LinkTextRule;
use SightPress\Core\Block\Schema\SchemaConstraints;
use SightPress\Core\Block\SiteBlockContext;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockType;

/**
 * A link to download a file from the media library. The link text says what it is, followed by the file's type and
 * size ("Annual report (PDF, 2.3 MB)"), so people know what they get before they choose it.
 */
final class FileDownloadBlock implements BlockType
{
    public function name(): string
    {
        return 'file_download';
    }

    public function label(): string
    {
        return 'File download';
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
            'required' => ['media', 'text'],
            'properties' => [
                'media' => ['type' => 'integer', 'format' => SchemaConstraints::FORMAT_MEDIA],
                'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            ],
        ];
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        $link = self::link($data, $context);
        if ($link === null) {
            return '';
        }
        $details = $link['details'] === null ? '' : ' <span class="block-file-download__details">(' . Html::e($link['details']) . ')</span>';

        return '<p class="block-file-download"><a href="' . Html::e($link['url']) . '" download>'
            . Html::e(Html::text($data, 'text')) . $details . '</a></p>';
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        $link = self::link($data, $context);
        if ($link === null) {
            return '';
        }
        $text = Html::markdown(Html::text($data, 'text')) . ($link['details'] === null ? '' : ' (' . Html::markdown($link['details']) . ')');

        return '[' . $text . '](' . $link['url'] . ')';
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        return null;
    }

    public function a11yRules(): array
    {
        return [new LinkTextRule('file_download.text_vague', 'text')];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{url: string, details: ?string}|null
     */
    private static function link(array $data, BlockContext $context): ?array
    {
        $id = $data['media'] ?? null;
        $url = is_int($id) ? $context->mediaUrl((string) $id) : null;
        if ($url === null || !is_int($id)) {
            return null;
        }
        $file = $context instanceof SiteBlockContext ? $context->file($id) : null;

        return ['url' => $url, 'details' => $file === null ? null : $context->fileDetails($file)];
    }
}
