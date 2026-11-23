<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Media\Admin\MediaAdmin;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\MediaEditor;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpNotFoundException;

/**
 * `POST /admin/media/{id}/captions`: adds, replaces or removes a video's WebVTT captions in one of the site's
 * languages. A refused file comes back to the video's page with what is wrong and on which line. Needs `media.upload`.
 */
final readonly class MediaCaptionsHandler implements RequestHandlerInterface
{
    public const string ERROR_KEY = 'media.captions_error';

    public function __construct(
        private AdminScreens $screens,
        private MediaAdmin $admin,
        private MediaEditor $editor,
        private MediaUploader $uploader,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$actor, $media] = $this->admin->target($request);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $index = $body['language'] ?? null;
        $locale = is_string($index) && ctype_digit($index) ? (array_values($this->site->locales)[(int) $index] ?? null) : null;
        if ($media->kind() !== MediaKind::Video || $locale === null) {
            throw new HttpNotFoundException($request);
        }
        $path = MediaAdmin::path($media);
        $canPublish = $actor->has(MediaAdmin::PUBLISH);
        if (($body['remove'] ?? null) === '1') {
            $this->editor->setCaptions($media, $locale, null, $actor->userId, $canPublish);

            return $this->screens->redirect($request, $path, 'The captions file has been removed.');
        }

        $upload = $request->getUploadedFiles()['captions'] ?? null;
        try {
            if (!$upload instanceof UploadedFileInterface) {
                throw new MediaException('Choose a file to upload.');
            }
            $result = $this->uploader->uploadFile($upload, $actor->userId);
            if ($result->media->mimeType() !== 'text/vtt') {
                if (!$result->duplicate) {
                    $this->editor->delete($result->media, $actor->userId);
                }
                throw new MediaException('Choose a captions file in WebVTT format (.vtt).');
            }
            $this->editor->setCaptions($media, $locale, $result->media, $actor->userId, $canPublish);
        } catch (MediaException $exception) {
            Session::fromRequest($request)->set(self::ERROR_KEY, $exception->text()->export());

            return $this->screens->redirect($request, $path);
        }

        return $this->screens->redirect($request, $path, new Message('The captions have been saved.'));
    }
}
