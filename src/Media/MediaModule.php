<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;
use SightPress\Core\Media\Contract\MediaUrlGenerator;
use SightPress\Core\Media\Http\MediaFileHandler;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * The media library: the `media` table, the site's stored files under `media/`, the {@see MediaUploader} that
 * checks, cleans and stores uploads, and `/media/…`, which serves the files ({@see MediaFileHandler}; addresses come
 * from the public {@see MediaUrlGenerator}). Always on. Its permissions (`media.upload`, `media.publish`) are in core's
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
        $services->autowire(OriginMediaUrlGenerator::class, MediaUrlGenerator::class, public: true);
        $services->autowire(MediaAccess::class);
        $services->autowire(MediaFileHandler::class);

        $routes = $registrar->routes();
        $routes->add(new Route(
            'media.file',
            OriginMediaUrlGenerator::PREFIX . '{' . MediaFileHandler::KEY_ATTRIBUTE . ':[0-9a-f]{32}}/{' . MediaFileHandler::NAME_ATTRIBUTE . '}',
            MediaFileHandler::class,
            ['GET', 'HEAD'],
        ));
        $routes->add(new Route(
            'media.variant',
            OriginMediaUrlGenerator::PREFIX . '{' . MediaFileHandler::KEY_ATTRIBUTE . ':[0-9a-f]{32}}/{' . MediaFileHandler::WIDTH_ATTRIBUTE
                . ':\d{1,5}}w/{' . MediaFileHandler::NAME_ATTRIBUTE . '}',
            MediaFileHandler::class,
            ['GET', 'HEAD'],
        ));
    }
}
