<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Rule;

use SightPress\Core\Block\Html;
use SightPress\Module\Block\A11yIssue;
use SightPress\Module\Block\A11yRule;
use SightPress\Module\Block\A11ySeverity;

/**
 * A picture needs alt text unless it is marked as decorative (WCAG 1.1.1). Alt text that looks like a file name,
 * starts with "image of" or is very long gets a warning.
 */
final class ImageAltRule implements A11yRule
{
    public const int LONG = 250;

    public function check(array $data): array
    {
        if (($data['decorative'] ?? false) === true) {
            return [];
        }
        $alt = trim(Html::text($data, 'alt'));
        if ($alt === '') {
            return [new A11yIssue('image.alt_missing', 'Describe the image, or mark it as decorative.', A11ySeverity::Error, '/alt', ['1.1.1'])];
        }
        $issues = [];
        if (preg_match('/^[\w\s-]+\.(jpe?g|png|gif|webp|avif|svg)$/i', $alt) === 1 || preg_match('/^(img|dsc|image|photo)[_-]?\d+$/i', $alt) === 1) {
            $issues[] = new A11yIssue('image.alt_file_name', 'The alt text looks like a file name. Describe what the image shows.', A11ySeverity::Warning, '/alt', ['1.1.1']);
        }
        if (preg_match('/^(an? )?(image|picture|photo|photograph|graphic) of\b/i', $alt) === 1) {
            $issues[] = new A11yIssue('image.alt_redundant', 'Screen readers already say it is an image. Start with what it shows.', A11ySeverity::Warning, '/alt', ['1.1.1']);
        }
        if (mb_strlen($alt) > self::LONG) {
            $issues[] = new A11yIssue('image.alt_long', sprintf('Keep alt text under %d characters; put longer descriptions in the caption or the text.', self::LONG), A11ySeverity::Warning, '/alt', ['1.1.1']);
        }

        return $issues;
    }
}
