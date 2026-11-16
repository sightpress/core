<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Media\Admin\MediaAdmin;
use SightPress\Core\Media\MediaEditor;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/media/{id}/delete`: asks whether to delete the file (GET), then deletes it (POST) and goes back to the
 * library. Only for people who may publish media, or the uploader while the file is unpublished.
 */
final readonly class MediaDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminScreens $screens,
        private MediaAdmin $admin,
        private MediaEditor $editor,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$actor, $media] = $this->admin->target($request);
        if (!MediaAdmin::canDelete($actor, $media)) {
            throw new HttpForbiddenException($request);
        }
        if ($request->getMethod() !== 'POST') {
            return $this->screens->render($request, '@media_admin/delete.html.twig', [
                'media' => $media,
                'back' => MediaAdmin::path($media),
                'action' => MediaAdmin::path($media, '/delete'),
            ]);
        }

        $name = $media->originalName();
        $this->editor->delete($media, $actor->userId);

        return $this->screens->redirect($request, MediaAdmin::PATH, sprintf('%s has been deleted.', $name));
    }
}
