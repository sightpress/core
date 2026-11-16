<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Contract;

use DateTimeImmutable;

/**
 * One file of the site's media library, as other modules see it (blocks, branding, the admin).
 *
 * A file never changes once uploaded: its {@see key()} names exactly one content, so URLs built from it can be
 * cached for good. Only its descriptions (alt text, caption, transcript) and whether it is published change.
 */
interface MediaFile
{
    /**
     * The random public name of the file: 32 lower-case hex characters.
     */
    public function key(): string;

    public function kind(): MediaKind;

    /**
     * The real type of the stored file, for example `image/jpeg`.
     */
    public function mimeType(): string;

    /**
     * The name used in URLs and downloads: lower-case ASCII letters, digits and hyphens, then the extension.
     */
    public function fileName(): string;

    /**
     * The name the file had on the uploader's computer, for people to recognise it.
     */
    public function originalName(): string;

    /**
     * Size of the stored file in bytes.
     */
    public function size(): int;

    /**
     * Width in pixels, for pictures.
     */
    public function width(): ?int;

    /**
     * Height in pixels, for pictures.
     */
    public function height(): ?int;

    /**
     * Widths of the smaller copies of a picture, from small to large; empty for other files.
     *
     * @return list<int>
     */
    public function variantWidths(): array;

    /**
     * Published files are public; the others are only for people who can manage media.
     */
    public function isPublished(): bool;

    public function altText(string $locale): ?string;

    public function caption(string $locale): ?string;

    public function transcript(): ?string;

    /**
     * When the file was uploaded (UTC). Its content never changes after that.
     */
    public function createdAt(): DateTimeImmutable;
}
