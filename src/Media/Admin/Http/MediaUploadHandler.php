<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Admin\Http;

use SightPress\Core\Template\Message;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Media\Admin\MediaAdmin;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaUploader;

/**
 * `POST /admin/media/upload`: adds a file, then goes to it so the uploader can describe it. A refused file goes back
 * to the library, where the reason is in the error summary (kept in the session, so this is Post/Redirect/Get).
 * When `js/media-upload.js` sent the form (`X-Requested-With: XMLHttpRequest`), the answer is JSON with the
 * address to go to instead of a redirect, so the script can show progress and then follow it.
 */
final readonly class MediaUploadHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/media/upload';

    public function __construct(
        private AdminScreens $screens,
        private MediaUploader $uploader,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = MediaAdmin::actor($request);
        $upload = $request->getUploadedFiles()['file'] ?? null;
        try {
            if (!$upload instanceof UploadedFileInterface) {
                throw new MediaException('Choose a file to upload.');
            }
            $result = $this->uploader->uploadFile($upload, $actor->userId);
        } catch (MediaException $exception) {
            Session::fromRequest($request)->set(MediaLibraryHandler::ERROR_KEY, $exception->text()->export());

            return $this->go($request, MediaAdmin::PATH, null);
        }

        $name = $result->media->originalName();
        $message = $result->duplicate
            ? new Message('{name} is already in the library, so it wasn’t added again. Here it is.', ['name' => $name])
            : new Message('{name} has been uploaded. Describe it below, so everyone can understand it.', ['name' => $name]);

        return $this->go($request, MediaAdmin::path($result->media), $message);
    }

    private function go(ServerRequestInterface $request, string $path, string|Message|null $message): ResponseInterface
    {
        $redirect = $this->screens->redirect($request, $path, $message);
        if ($request->getHeaderLine('X-Requested-With') !== 'XMLHttpRequest') {
            return $redirect;
        }
        $response = $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write((string) json_encode(['location' => $path], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $response;
    }
}
