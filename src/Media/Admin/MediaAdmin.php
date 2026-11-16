<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Admin;

use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Rbac\Contract\Actor;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * Who may do what in the admin's media library, and finding the file a request is about.
 *
 * - `media.upload` (contributors, editors, admins): see the library, upload, and describe files;
 * - `media.publish` (editors, admins): publish and unpublish, and delete any file;
 * - uploaders may also delete their own files while they are unpublished.
 */
final readonly class MediaAdmin
{
    public const string UPLOAD = 'media.upload';
    public const string PUBLISH = 'media.publish';
    public const string PATH = '/admin/media';
    public const string ID_ATTRIBUTE = 'id';

    public function __construct(private MediaRepository $media)
    {
    }

    public static function path(Media $media, string $suffix = ''): string
    {
        return self::PATH . '/' . $media->id() . $suffix;
    }

    /**
     * The actor, after checking they may use the library.
     */
    public static function actor(ServerRequestInterface $request): Actor
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(self::UPLOAD)) {
            throw new HttpForbiddenException($request);
        }

        return $actor;
    }

    public static function canDelete(Actor $actor, Media $media): bool
    {
        return $actor->has(self::PUBLISH)
            || (!$media->isPublished() && $actor->userId !== null && $media->uploadedBy() === $actor->userId);
    }

    /**
     * The actor and the file the request is about.
     *
     * @return array{Actor, Media}
     */
    public function target(ServerRequestInterface $request): array
    {
        $actor = self::actor($request);
        $id = $request->getAttribute(self::ID_ATTRIBUTE);
        $media = is_numeric($id) ? $this->media->find((int) $id) : null;

        return [$actor, $media ?? throw new HttpNotFoundException($request)];
    }
}
