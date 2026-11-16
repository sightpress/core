<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Rule;

use SightPress\Core\Block\Html;
use SightPress\Module\Block\A11yIssue;
use SightPress\Module\Block\A11yRule;
use SightPress\Module\Block\A11ySeverity;

/**
 * The text of a button or link field says where it goes (WCAG 2.4.4).
 */
final readonly class LinkTextRule implements A11yRule
{
    /**
     * @param non-empty-string $rule  id, for example `call_to_action.text_vague`
     * @param string           $field data key of the link text
     */
    public function __construct(
        private string $rule,
        private string $field,
    ) {
    }

    public function check(array $data): array
    {
        if (!LinkText::isVague(Html::text($data, $this->field))) {
            return [];
        }

        return [new A11yIssue(
            $this->rule,
            'Say where the link goes, for example "Read the annual report" instead of "Read more".',
            A11ySeverity::Warning,
            '/' . $this->field,
            ['2.4.4'],
        )];
    }
}
