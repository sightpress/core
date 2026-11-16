<?php

declare(strict_types=1);

namespace SightPress\Core\User\Http;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * `/avatars/{file}`: sends an avatar from the site's storage. File names are random and change with every new
 * picture, so they are cached for a year. Generated SVGs get a policy that lets them run and load nothing.
 */
final readonly class AvatarFileHandler implements RequestHandlerInterface
{
    public const string FILE_ATTRIBUTE = 'file';

    private const string PATTERN = '/^[0-9a-f]{32}(?:\.svg|-(?:64|128|256)\.(?:jpg|png|webp))$/';

    private const array TYPES = [
        'svg' => 'image/svg+xml',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __construct(
        private FilesystemOperator $storage,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $file = $request->getAttribute(self::FILE_ATTRIBUTE);
        if (!is_string($file) || preg_match(self::PATTERN, $file) !== 1) {
            throw new HttpNotFoundException($request);
        }
        try {
            $contents = $this->storage->read('avatars/' . $file);
        } catch (FilesystemException) {
            throw new HttpNotFoundException($request);
        }

        $type = self::TYPES[pathinfo($file, PATHINFO_EXTENSION)];
        $response = $this->responses->createResponse()
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Length', (string) strlen($contents))
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        if ($type === 'image/svg+xml') {
            $response = $response->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");
        }

        return $request->getMethod() === 'HEAD' ? $response : $response->withBody($this->streams->createStream($contents));
    }
}
