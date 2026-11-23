<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Admin\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Media\Admin\MediaAdmin;
use SightPress\Core\Media\Upload\ChunkedUploads;
use SightPress\Core\Media\Upload\UploadRefused;
use SightPress\Core\Media\Upload\UploadSession;
use SightPress\Core\Media\UploadResult;
use SightPress\Core\Template\Message;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * `/admin/media/uploads`: the tus 1.0 protocol (creation, termination and checksum extensions) for large files. A
 * session belongs to the person who started it. When an upload ends, for good or with a refusal, `X-Media-Location`
 * names the page to open; a refusal's reason waits there in the error summary, translated.
 */
final readonly class TusUploadHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/media/uploads';

    public const string ID_ATTRIBUTE = 'upload';

    public const string VERSION = '1.0.0';

    private const array RETRYABLE = [409, 460];

    public function __construct(
        private AdminScreens $screens,
        private ChunkedUploads $uploads,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = MediaAdmin::actor($request);
        $userId = $actor->userId ?? throw new HttpForbiddenException($request);
        $method = $request->getMethod();
        if ($method === 'OPTIONS') {
            return $this->answer(204)
                ->withHeader('Tus-Version', self::VERSION)
                ->withHeader('Tus-Extension', 'creation,termination,checksum')
                ->withHeader('Tus-Checksum-Algorithm', 'sha256')
                ->withHeader('Tus-Max-Size', (string) $this->uploads->maxBytes());
        }
        if ($request->getHeaderLine('Tus-Resumable') !== self::VERSION) {
            return $this->answer(412)->withHeader('Tus-Version', self::VERSION);
        }

        try {
            if ($method === 'POST') {
                return $this->create($request, $userId);
            }
            $id = $request->getAttribute(self::ID_ATTRIBUTE);
            $session = is_string($id) ? $this->uploads->find($id, $userId) : null;
            if ($session === null) {
                throw new HttpNotFoundException($request);
            }

            return match ($method) {
                'HEAD' => $this->status($session),
                'PATCH' => $this->append($request, $session),
                default => $this->cancel($session),
            };
        } catch (UploadRefused $refused) {
            return $this->refuse($request, $refused);
        }
    }

    private function create(ServerRequestInterface $request, int $userId): ResponseInterface
    {
        $length = $request->getHeaderLine('Upload-Length');
        if (preg_match('/^\d{1,18}$/', $length) !== 1) {
            return $this->answer(400);
        }
        $metadata = self::metadata($request->getHeaderLine('Upload-Metadata'));
        $session = $this->uploads->create($userId, $metadata['filename'] ?? '', $metadata['filetype'] ?? '', (int) $length);

        return $this->answer(201)
            ->withHeader('Location', self::PATH . '/' . $session->id)
            ->withHeader('Upload-Offset', '0')
            ->withHeader('X-Chunk-Bytes', (string) $this->uploads->chunkBytes());
    }

    private function status(UploadSession $session): ResponseInterface
    {
        $response = $this->answer(200)
            ->withHeader('Upload-Offset', (string) $session->receivedBytes)
            ->withHeader('Upload-Length', (string) $session->totalBytes)
            ->withHeader('X-Chunk-Bytes', (string) $this->uploads->chunkBytes());

        return $session->mediaId === null ? $response : $response->withHeader('X-Media-Location', MediaAdmin::PATH . '/' . $session->mediaId);
    }

    private function append(ServerRequestInterface $request, UploadSession $session): ResponseInterface
    {
        if ($request->getHeaderLine('Content-Type') !== 'application/offset+octet-stream') {
            return $this->answer(415);
        }
        $offset = $request->getHeaderLine('Upload-Offset');
        if (preg_match('/^\d{1,18}$/', $offset) !== 1) {
            return $this->answer(400);
        }
        $checksum = null;
        if ($request->hasHeader('Upload-Checksum')) {
            $parts = explode(' ', $request->getHeaderLine('Upload-Checksum'), 2);
            $checksum = count($parts) === 2 ? base64_decode($parts[1], true) : false;
            if ($parts[0] !== 'sha256' || $checksum === false || strlen($checksum) !== 32) {
                return $this->answer(400);
            }
        }
        $length = $request->getHeaderLine('Content-Length');
        ['session' => $updated, 'result' => $result] = $this->uploads->append($session, (int) $offset, $request->getBody(), ctype_digit($length) ? (int) $length : null, $checksum);
        $response = $this->answer(204)->withHeader('Upload-Offset', (string) $updated->receivedBytes);
        if (!$result instanceof UploadResult) {
            return $response;
        }
        $name = $result->media->originalName();
        $path = MediaAdmin::path($result->media);
        $this->screens->redirect($request, $path, $result->duplicate
            ? new Message('{name} is already in the library, so it wasn’t added again. Here it is.', ['name' => $name])
            : new Message('{name} has been uploaded. Describe it below, so everyone can understand it.', ['name' => $name]));

        return $response->withHeader('X-Media-Location', $path);
    }

    private function cancel(UploadSession $session): ResponseInterface
    {
        $this->uploads->cancel($session);

        return $this->answer(204);
    }

    private function refuse(ServerRequestInterface $request, UploadRefused $refused): ResponseInterface
    {
        $response = $this->answer($refused->status);
        if (in_array($refused->status, self::RETRYABLE, true)) {
            return $response;
        }
        Session::fromRequest($request)->set(MediaLibraryHandler::ERROR_KEY, $refused->text->export());

        return $response->withHeader('X-Media-Location', MediaAdmin::PATH);
    }

    private function answer(int $status): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Tus-Resumable', self::VERSION)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * `Upload-Metadata`: comma-separated `key base64value` pairs.
     *
     * @return array<string, string>
     */
    private static function metadata(string $header): array
    {
        $metadata = [];
        foreach (explode(',', $header) as $pair) {
            $parts = explode(' ', trim($pair), 2);
            $value = base64_decode($parts[1] ?? '', true);
            if ($parts[0] !== '' && $value !== false) {
                $metadata[$parts[0]] = $value;
            }
        }

        return $metadata;
    }
}
