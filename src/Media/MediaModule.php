<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;

/**
 * The media library: the `media` table, the site's stored files under `media/` and the {@see MediaUploader} that
 * checks, cleans and stores uploads. Always on. Its permissions (`media.upload`, `media.publish`) are in core's
 * catalogue.
 */
final class MediaModule implements ModuleInterface
{
    public const string ID = 'media';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::ID, '0.1.0', [new ModuleRequirement('site', '^0.1')], required: true);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new MediaMigrations());

        $services = $registrar->services();
        $services->factory(
            ImageManagerInterface::class,
            static fn (): ImageManagerInterface => new ImageManager(Driver::class, autoOrientation: true, decodeAnimation: true, strip: true),
        );
        foreach ([MediaTypes::class, MediaLimits::class, ImageProcessor::class, MediaFiles::class] as $class) {
            $services->autowire($class);
        }
        $services->autowire(MediaRepository::class, public: true);
        $services->autowire(MediaUploader::class, public: true);
    }
}
