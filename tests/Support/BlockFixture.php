<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use DateTimeImmutable;
use SightPress\Core\Block\DefaultBlockRenderer;
use SightPress\Core\Block\DefaultBlockValidator;
use SightPress\Core\Block\RichText\RichTextSanitizer;
use SightPress\Core\Block\SiteBlockContext;
use SightPress\Core\Block\SiteBlockContexts;
use SightPress\Core\Block\Type\CallToActionBlock;
use SightPress\Core\Block\Type\ColumnsBlock;
use SightPress\Core\Block\Type\HeadingBlock;
use SightPress\Core\Block\Type\ImageBlock;
use SightPress\Core\Block\Type\RichTextBlock;
use SightPress\Core\Content\BlockTypeCatalogue;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Media\OriginMediaUrlGenerator;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Block\BlockType;
use Symfony\Component\Validator\Validation;

/**
 * Blocks for tests: core's block types (plus any extra ones), a migrated site database for the media library, and
 * the validator, renderer and contexts built on them.
 */
final class BlockFixture
{
    public readonly CoreSiteDatabase $database;

    public readonly BlockTypeCatalogue $types;

    /**
     * @param list<BlockType> $extraTypes
     */
    public function __construct(array $extraTypes = [], ?CoreSiteDatabase $database = null)
    {
        $this->database = $database ?? new CoreSiteDatabase();
        $this->types = new BlockTypeCatalogue(array_map(
            static fn (BlockType $type): array => ['module' => 'test', 'type' => $type],
            [new HeadingBlock(), new RichTextBlock(), new ImageBlock(), new CallToActionBlock(), new ColumnsBlock(), ...$extraTypes],
        ));
    }

    public function media(): MediaRepository
    {
        return new MediaRepository($this->database->entityManager);
    }

    public function validator(): DefaultBlockValidator
    {
        return new DefaultBlockValidator($this->types, Validation::createValidator(), new RichTextSanitizer(), $this->media());
    }

    public function renderer(): DefaultBlockRenderer
    {
        return new DefaultBlockRenderer($this->types);
    }

    public function contexts(?Site $site = null): SiteBlockContexts
    {
        return new SiteBlockContexts($site ?? PageFixture::site(['en-GB', 'pt-BR']), $this->media(), new OriginMediaUrlGenerator(), $this->renderer(), new MediaTypes());
    }

    public function context(string $locale = 'en-GB'): SiteBlockContext
    {
        $context = $this->contexts()->create($locale, 'https://demo.example/');
        assert($context instanceof SiteBlockContext);

        return $context;
    }

    /**
     * Adds a file to the media library and returns its id.
     *
     * @param list<int> $variants
     */
    public function addMedia(bool $published = true, MediaKind $kind = MediaKind::Image, array $variants = [320, 640], string $fileName = 'lighthouse.jpg', ?MediaFormat $format = null, int $size = 2048): int
    {
        $now = new DateTimeImmutable('2026-11-16 10:00:00 UTC');
        $format ??= match ($kind) {
            MediaKind::Image => new MediaFormat('image/jpeg', MediaKind::Image, 'jpg', 'JPEG'),
            MediaKind::Video => new MediaFormat('video/mp4', MediaKind::Video, 'mp4', 'MP4'),
            MediaKind::Audio => new MediaFormat('audio/mpeg', MediaKind::Audio, 'mp3', 'MP3'),
            MediaKind::Document => new MediaFormat('application/pdf', MediaKind::Document, 'pdf', 'PDF'),
        };
        $media = new Media(
            bin2hex(random_bytes(16)),
            hash('sha256', random_bytes(16)),
            $format,
            $fileName,
            $fileName,
            $size,
            $kind === MediaKind::Image ? 1280 : null,
            $kind === MediaKind::Image ? 640 : null,
            $kind === MediaKind::Image ? $variants : [],
            null,
            $now,
        );
        if ($published) {
            $media->publish($now);
        }
        $this->media()->save($media);

        return (int) $media->id();
    }

    /**
     * A published WebVTT captions file.
     */
    public function addCaptions(): int
    {
        return $this->addMedia(kind: MediaKind::Document, fileName: 'captions.vtt', format: new MediaFormat('text/vtt', MediaKind::Document, 'vtt', 'WebVTT captions'));
    }

    public function key(int $id): string
    {
        return (string) $this->media()->find($id)?->key();
    }
}
