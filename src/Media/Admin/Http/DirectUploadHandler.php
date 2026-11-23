<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Admin\Http;

use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Infrastructure\Storage\StorageException;
use SightPress\Core\Media\Admin\MediaAdmin;
use SightPress\Core\Media\Upload\DirectUploads;
use SightPress\Core\Media\Upload\UploadRefused;
use SightPress\Core\Media\Upload\UploadSession;
use SightPress\Core\Template\Message;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * `/admin/media/direct`: what the browser needs to send a large file straight to the bucket. Starts an upload, signs
 * part addresses in batches, says which parts arrived, joins them and reports the check. Only small JSON passes through
 * here. An answer of `{"fallback": true}` means this site uses the temporary folder.
 */
final readonly class DirectUploadHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/media/direct';

    public const string ID_ATTRIBUTE = 'upload';

    /** Enough for the ETags of the largest number of parts. */
    private const int MAX_BODY = 1024 * 1024;

    public function __construct(
        private AdminScreens $screens,
        private DirectUploads $uploads,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = MediaAdmin::actor($request);
        $userId = $actor->userId ?? throw new HttpForbiddenException($request);
        try {
            $id = $request->getAttribute(self::ID_ATTRIBUTE);
            if (!is_string($id)) {
                return $this->start($request, $userId);
            }
            $session = $this->uploads->find($id, $userId) ?? throw new HttpNotFoundException($request);
            $path = $request->getUri()->getPath();

            return match (true) {
                $request->getMethod() === 'DELETE' => $this->cancel($session),
                $request->getMethod() === 'GET' => $this->status($request, $session),
                str_ends_with($path, '/urls') => $this->urls($request, $session),
                default => $this->complete($request, $session),
            };
        } catch (UploadRefused $refused) {
            if ($refused->status === 409) {
                return $this->json(['retry' => true], 409);
            }
            Session::fromRequest($request)->set(MediaLibraryHandler::ERROR_KEY, $refused->text->export());

            return $this->json(['location' => MediaAdmin::PATH], $refused->status);
        } catch (StorageException) {
            return $this->json(['retry' => true], 503);
        }
    }

    private function start(ServerRequestInterface $request, int $userId): ResponseInterface
    {
        $body = self::body($request);
        $size = $body['size'] ?? null;
        $name = $body['name'] ?? null;
        $type = $body['type'] ?? '';
        if (!is_int($size) || !is_string($name) || !is_string($type)) {
            return $this->json(['error' => 'bad request'], 400);
        }
        $uri = $request->getUri();
        $port = $uri->getPort();
        $origin = $uri->getScheme() . '://' . $uri->getHost() . ($port === null ? '' : ':' . $port);
        $session = $this->uploads->start($userId, $name, $type, $size, $origin);
        if ($session === null) {
            return $this->json(['fallback' => true]);
        }

        return $this->json(['id' => $session->id, 'partBytes' => $session->partBytes, 'parts' => $session->partCount(), 'batch' => DirectUploads::URL_BATCH], 201);
    }

    private function status(ServerRequestInterface $request, UploadSession $session): ResponseInterface
    {
        if ($session->isComplete()) {
            $path = MediaAdmin::PATH . '/' . $session->mediaId;
            $this->screens->redirect($request, $path, new Message('{name} has been uploaded. Describe it below, so everyone can understand it.', ['name' => $session->fileName]));

            return $this->json(['state' => 'done', 'location' => $path]);
        }
        if ($session->state === UploadSession::REFUSED) {
            Session::fromRequest($request)->set(MediaLibraryHandler::ERROR_KEY, $session->refusal);
            $this->uploads->cancel($session);

            return $this->json(['state' => 'refused', 'location' => MediaAdmin::PATH]);
        }
        if ($session->state !== UploadSession::RECEIVING) {
            return $this->json(['state' => 'validating']);
        }

        return $this->json(['state' => 'receiving', 'partBytes' => $session->partBytes, 'parts' => $session->partCount(), 'batch' => DirectUploads::URL_BATCH, 'received' => (object) $this->uploads->received($session)]);
    }

    private function urls(ServerRequestInterface $request, UploadSession $session): ResponseInterface
    {
        $numbers = self::body($request)['parts'] ?? null;
        if (!is_array($numbers) || !array_is_list($numbers) || array_filter($numbers, is_int(...)) !== $numbers) {
            return $this->json(['error' => 'bad request'], 400);
        }

        /** @var list<int> $numbers */
        return $this->json(['urls' => (object) $this->uploads->urls($session, $numbers)]);
    }

    private function complete(ServerRequestInterface $request, UploadSession $session): ResponseInterface
    {
        $parts = self::body($request)['parts'] ?? null;
        if (!is_array($parts)) {
            return $this->json(['error' => 'bad request'], 400);
        }
        $etags = [];
        foreach ($parts as $number => $etag) {
            if (is_int($number) && is_string($etag)) {
                $etags[$number] = $etag;
            }
        }
        $this->uploads->complete($session, $etags);

        return $this->json(['state' => 'validating'], 202);
    }

    private function cancel(UploadSession $session): ResponseInterface
    {
        $this->uploads->cancel($session);

        return $this->responses->createResponse(204)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array<mixed>
     */
    private static function body(ServerRequestInterface $request): array
    {
        // One read can return less than was asked for (8 KB from PHP's input), so read until the end.
        $stream = $request->getBody();
        $text = '';
        while (!$stream->eof() && strlen($text) <= self::MAX_BODY) {
            $text .= $stream->read(self::MAX_BODY + 1 - strlen($text));
        }
        if (strlen($text) > self::MAX_BODY) {
            return [];
        }
        try {
            $data = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write((string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $response;
    }
}
