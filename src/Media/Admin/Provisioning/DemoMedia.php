<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Admin\Provisioning;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Media\MediaEditor;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Site\Contract\SiteProvisioning;

/**
 * Demo content, step 1 of 4: puts a picture, a video with its captions, a recording and a PDF in the media library,
 * described and published, and shares their ids (`demo.picture`, `demo.video`, `demo.captions`, `demo.audio`,
 * `demo.document`) with the next steps.
 */
final readonly class DemoMedia
{
    public const int PRIORITY = SiteProvisioning::DEMO;

    /** Share name => [file in resources/demo, alt text, transcript]. */
    public const array FILES = [
        'picture' => ['harbour.jpg', 'A test pattern: coloured bars, a gradient and circles.', null],
        'captions' => ['tour.vtt', null, null],
        'video' => ['tour.mp4', null, 'A gentle tone plays for two seconds over moving coloured test patterns. Nobody speaks.'],
        'audio' => ['talk.mp3', null, 'A single tone plays for two seconds. Nobody speaks.'],
        'document' => ['opening-hours.pdf', null, null],
    ];

    /**
     * @param string|null $directory where the files are (default: core's resources/demo)
     */
    public function __construct(private ?string $directory = null)
    {
    }

    public function __invoke(SiteProvisioning $event): void
    {
        if (!$event->demo) {
            return;
        }
        $uploader = Services::get($event->services, MediaUploader::class);
        $editor = Services::get($event->services, MediaEditor::class);
        $by = $event->adminId();
        foreach (self::FILES as $name => [$file, $alt, $transcript]) {
            $copy = tempnam(sys_get_temp_dir(), 'sp-demo-');
            if ($copy === false || !@copy(($this->directory ?? self::directory()) . '/' . $file, $copy)) {
                throw new MediaException(sprintf('The demo file %s could not be copied.', $file));
            }
            try {
                $media = $uploader->upload($copy, $file, $by)->media;
            } finally {
                @unlink($copy);
            }
            $editor->update(
                $media,
                $alt === null ? [] : [$event->site->defaultLocale => ['alt' => $alt, 'caption' => null]],
                $transcript === null ? null : [$event->site->defaultLocale => $transcript],
                true,
                $by,
                $event->site->defaultLocale,
            );
            $event->share('demo.' . $name, (int) $media->id());
        }
        $event->report(sprintf('Added %d demo files to the media library, described and published.', count(self::FILES)));
    }

    public static function directory(): string
    {
        return dirname(__DIR__, 4) . '/resources/demo';
    }
}
