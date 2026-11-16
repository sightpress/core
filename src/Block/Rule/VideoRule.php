<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Rule;

use SightPress\Core\Block\Html;
use SightPress\Module\Block\A11yIssue;
use SightPress\Module\Block\A11yRule;
use SightPress\Module\Block\A11ySeverity;

/**
 * A video with speech needs captions (WCAG 1.2.2); one without speech needs a text description of what it shows
 * (1.2.1). A transcript that also describes what is shown is recommended for every video (1.2.3, 1.2.5).
 */
final class VideoRule implements A11yRule
{
    public function check(array $data): array
    {
        $noSpeech = ($data['no_speech'] ?? false) === true;
        $transcript = trim(Html::text($data, 'transcript')) !== '';
        $issues = [];
        if (!$noSpeech && !is_int($data['captions'] ?? null)) {
            $issues[] = new A11yIssue('video.captions_missing', 'Add a captions file, or say that the video has no speech.', A11ySeverity::Error, '/captions', ['1.2.2']);
        }
        if ($noSpeech && !$transcript) {
            $issues[] = new A11yIssue('video.description_missing', 'Describe what the video shows in the transcript, for people who can\'t see it.', A11ySeverity::Error, '/transcript', ['1.2.1']);
        } elseif (!$transcript) {
            $issues[] = new A11yIssue('video.transcript_missing', 'Add a transcript of what is said and shown, for people who can\'t see or hear the video.', A11ySeverity::Warning, '/transcript', ['1.2.3', '1.2.5']);
        }

        return $issues;
    }
}
