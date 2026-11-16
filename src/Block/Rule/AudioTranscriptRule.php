<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Rule;

use SightPress\Core\Block\Html;
use SightPress\Module\Block\A11yIssue;
use SightPress\Module\Block\A11yRule;
use SightPress\Module\Block\A11ySeverity;

/**
 * Audio needs a transcript (WCAG 1.2.1).
 */
final class AudioTranscriptRule implements A11yRule
{
    public function check(array $data): array
    {
        if (trim(Html::text($data, 'transcript')) !== '') {
            return [];
        }

        return [new A11yIssue('audio.transcript_missing', 'Add a transcript, for people who can\'t hear the audio.', A11ySeverity::Error, '/transcript', ['1.2.1'])];
    }
}
