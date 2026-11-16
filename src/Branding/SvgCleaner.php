<?php

declare(strict_types=1);

namespace SightPress\Core\Branding;

use DOMDocument;
use enshrined\svgSanitize\Sanitizer;

/**
 * Cleans an uploaded SVG logo with an allow-list of elements and attributes: scripts, event handlers, links to other
 * files and anything else that could run or load something are removed. A file that isn't a readable SVG, or that
 * has nothing left to show, is refused. SVG files are also only ever served with a strict Content-Security-Policy.
 */
final class SvgCleaner
{
    public const int MAX_BYTES = 512 * 1024;

    /**
     * @return array{string, float, float} the clean SVG and its width and height (from its `viewBox`, else its
     *                                     `width` and `height`)
     */
    public static function clean(string $svg): array
    {
        if (strlen($svg) > self::MAX_BYTES) {
            throw new BrandingException('An SVG logo must be 512 KB or smaller.');
        }
        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->minify(true);
        $sanitizer->removeXMLTag(true);
        $clean = $sanitizer->sanitize($svg);
        $document = new DOMDocument();
        if (!is_string($clean) || $clean === '' || !@$document->loadXML($clean, LIBXML_NONET)) {
            throw new BrandingException('This SVG file can\'t be read. Export it again from your drawing program, or upload a PNG instead.');
        }
        $root = $document->documentElement;
        if ($root === null || $root->localName !== 'svg' || $root->childElementCount === 0) {
            throw new BrandingException('This SVG file has nothing left to show once it is made safe. Upload a PNG instead.');
        }
        $box = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')), -1, PREG_SPLIT_NO_EMPTY);
        if ($box !== false && count($box) === 4 && (float) $box[2] > 0 && (float) $box[3] > 0) {
            return [$clean, (float) $box[2], (float) $box[3]];
        }
        $width = (float) $root->getAttribute('width');
        $height = (float) $root->getAttribute('height');
        if ($width <= 0 || $height <= 0) {
            throw new BrandingException('This SVG file has no size: give it a viewBox, or a width and a height, and upload it again.');
        }

        return [$clean, $width, $height];
    }
}
