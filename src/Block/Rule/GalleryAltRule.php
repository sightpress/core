<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Rule;

use SightPress\Core\Block\Type\GalleryBlock;
use SightPress\Module\Block\A11yIssue;
use SightPress\Module\Block\A11yRule;

/**
 * Every picture of a gallery passes {@see ImageAltRule}; issues point at that picture's alt text.
 */
final class GalleryAltRule implements A11yRule
{
    public function check(array $data): array
    {
        $issues = [];
        $rule = new ImageAltRule();
        foreach (GalleryBlock::images($data) as $index => $image) {
            foreach ($rule->check($image) as $issue) {
                $issues[] = new A11yIssue(
                    $issue->rule,
                    sprintf('Picture %d: %s', $index + 1, $issue->message),
                    $issue->severity,
                    '/images/' . $index . '/alt',
                    $issue->wcag,
                );
            }
        }

        return $issues;
    }
}
