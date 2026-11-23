<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

use SightPress\Core\Media\UploadRefusals;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Template\Message;

/**
 * The notice about server limits that admins see: the {@see ServerLimitsCheck} problems and the uploads the web server
 * refused in the last 7 days. An admin can hide it until the result changes. Free space and the number of refusals
 * move all the time, so they don't count as a change.
 */
final readonly class ServerNotice
{
    public const string HIDDEN_SETTING = 'media.limits_notice_hidden';

    private const array VOLATILE = ['free', 'count'];

    public function __construct(
        private ServerLimitsCheck $check,
        private UploadRefusals $refusals,
        private SiteSettings $settings,
    ) {
    }

    /**
     * @return list<Message>
     */
    public function problems(): array
    {
        $problems = $this->check->problems();
        $refused = $this->refusals->lastSevenDays();
        if ($refused > 0) {
            $problems[] = new Message(
                '{count, plural, one {# upload was} other {# uploads were}} refused by the web server in the last 7 days because the file was too large. Check the limits, or tell the people uploading how large a file can be.',
                ['count' => $refused],
            );
        }

        return $problems;
    }

    /**
     * What to show now: nothing while the notice is hidden and the result is the same.
     *
     * @return list<Message>
     */
    public function visible(): array
    {
        $problems = $this->problems();

        return $problems !== [] && $this->settings->get(self::HIDDEN_SETTING) === self::fingerprint($problems) ? [] : $problems;
    }

    public function hide(): void
    {
        $this->settings->set(self::HIDDEN_SETTING, self::fingerprint($this->problems()));
    }

    /**
     * @param list<Message> $problems
     */
    private static function fingerprint(array $problems): string
    {
        return hash('sha256', json_encode(array_map(
            static fn (Message $problem): array => [$problem->text, array_diff_key($problem->export()['parameters'], array_flip(self::VOLATILE))],
            $problems,
        ), JSON_THROW_ON_ERROR));
    }
}
