<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Media\Privacy\StorageProcessors;
use SightPress\Core\Media\Privacy\UploadData;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;
use SightPress\Core\Media\Contract\MediaFormats;
use SightPress\Core\Media\Contract\MediaLibrary;
use SightPress\Core\Media\Contract\MediaPictures;
use SightPress\Core\Media\Contract\MediaUrlGenerator;
use SightPress\Core\Http\Upload\RequestTooLargeHandler;
use SightPress\Core\Media\Command\SiteMediaImportCommand;
use SightPress\Core\Media\Command\SiteMediaLimitsCommand;
use SightPress\Core\Media\Command\SiteStorageCdnCommand;
use SightPress\Core\Media\Command\SystemCheckCommand;
use SightPress\Core\Media\Command\SiteMediaUploadsCommand;
use SightPress\Core\Media\Http\MediaFileHandler;
use SightPress\Core\Http\Contract\RequestAnswered;
use SightPress\Core\Media\Upload\BucketUploads;
use SightPress\Core\Media\Upload\ChunkedUploads;
use SightPress\Core\Media\Upload\DirectUploadAfterResponse;
use SightPress\Core\Media\Upload\DirectUploads;
use SightPress\Core\Media\Upload\UploadCleanupJob;
use SightPress\Core\Media\Upload\UploadFolder;
use SightPress\Core\Media\Upload\UploadSessions;
use SightPress\Core\Site\Contract\ScheduleDue;
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
 *
 * Uploads can't be larger than the server lets one request carry: {@see UploadAllowance} gives the largest file of
 * each kind, {@see \SightPress\Core\Http\Upload\ServerLimitsCheck} the server settings to change (shown to admins and by `system:check`), and
 * `/_sightpress/request-too-large` is the page the web server shows when it refuses a request for its size.
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
        foreach ([UploadData::class, StorageProcessors::class] as $class) {
            $services->autowire($class);
        }
        $registrar->privacy()->addProvider(UploadData::class);
        $registrar->privacy()->addProcessors(StorageProcessors::class);
        $services->factory(
            ImageManagerInterface::class,
            static fn (): ImageManagerInterface => new ImageManager(Driver::class, autoOrientation: true, decodeAnimation: true, strip: true),
        );
        foreach ([MediaTypes::class, ImageProcessor::class, MediaFiles::class] as $class) {
            $services->autowire($class);
        }
        $services->autowire(MediaRepository::class, public: true);
        $services->autowire(MediaRepository::class, MediaLibrary::class, public: true);
        $services->autowire(MediaTypes::class, MediaFormats::class, public: true);
        $services->autowire(MediaUploader::class, public: true);
        $services->autowire(MediaPictureReader::class, MediaPictures::class, public: true);
        $services->autowire(UploadAllowance::class, public: true);
        $services->autowire(UploadSessions::class, public: true);
        $services->autowire(UploadFolder::class);
        $services->autowire(ChunkedUploads::class, public: true);
        $services->autowire(BucketUploads::class);
        $services->autowire(DirectUploads::class, public: true);
        $services->autowire(DirectUploadAfterResponse::class);
        $registrar->subscribers()->listen(RequestAnswered::class, DirectUploadAfterResponse::class);
        $services->autowire(UploadCleanupJob::class);
        $registrar->subscribers()->listen(ScheduleDue::class, UploadCleanupJob::class);
        $services->autowire(RequestTooLargeHandler::class);
        $registrar->commands()->add(SystemCheckCommand::class);
        $registrar->commands()->add(SiteMediaLimitsCommand::class);
        $registrar->commands()->add(SiteStorageCdnCommand::class);
        $registrar->commands()->add(SiteMediaImportCommand::class);
        $registrar->commands()->add(SiteMediaUploadsCommand::class);
        $services->autowire(DeliveryMediaUrlGenerator::class, MediaUrlGenerator::class, public: true);
        $services->autowire(MediaAccess::class);
        $services->autowire(MediaFileHandler::class);

        $routes = $registrar->routes();
        $routes->add(new Route('media.request_too_large', RequestTooLargeHandler::PATH, RequestTooLargeHandler::class, ['GET', 'HEAD']));
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
