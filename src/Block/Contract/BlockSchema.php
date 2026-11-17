<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

/**
 * The words of block schemas that core gives a meaning to, for modules that read schemas (the page editor builds
 * its fields from them) or write them. A block type's JSON Schema may use `format` with one of these values, and
 * `contentMediaType` {@see HTML} for rich text. `title` names a field for editors and `description` explains it.
 */
final class BlockSchema
{
    /** A link: an address on the site (`/contact`) or a full web address. */
    public const string FORMAT_LINK = 'link';

    public const string FORMAT_EMAIL = 'email';

    /** The id of any file in the media library. */
    public const string FORMAT_MEDIA = 'media';

    /** The id of a picture in the media library. */
    public const string FORMAT_IMAGE = 'media-image';

    public const string FORMAT_VIDEO = 'media-video';

    public const string FORMAT_AUDIO = 'media-audio';

    public const string FORMAT_DOCUMENT = 'media-document';

    /** The id of a WebVTT captions file (`text/vtt`) in the media library. */
    public const string FORMAT_CAPTIONS = 'media-captions';

    /** Formats of media library ids. */
    public const array MEDIA_FORMATS = [
        self::FORMAT_MEDIA, self::FORMAT_IMAGE, self::FORMAT_VIDEO, self::FORMAT_AUDIO, self::FORMAT_DOCUMENT,
        self::FORMAT_CAPTIONS,
    ];

    /** `contentMediaType` of rich text, cleaned with an allow-list when it is saved. */
    public const string HTML = 'text/html';
}
