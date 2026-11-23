<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Http;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaAccess;
use SightPress\Core\Media\MediaFiles;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaUrlSigner;
use SightPress\Core\Media\OriginMediaUrlGenerator;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpNotFoundException;

/**
 * `/media/{key}/{name}` and `/media/{key}/{width}w/{name}`: streams a file of the media library from the site's
 * storage (origin mode).
 *
 * - Unpublished files are only sent to people who can manage media ({@see MediaAccess}) or with a valid signature
 *   ({@see MediaUrlSigner}); everyone else gets "not found", so nobody learns that the file exists.
 * - Anything private (unpublished, on a members-only site, or opened by signature) is never cached
 *   (`private, no-store`), so a CDN can't serve it to someone else.
 * - Published files of a public site are cached for a year (`immutable`). With a CDN, for five minutes, so that
 *   unpublishing a file or making the site members-only reaches the CDN within that time.
 * - `ETag` and `Last-Modified` answer revalidations with 304; a single `Range` is answered with 206 (video and audio
 *   players seek this way), a range past the end with 416, `If-Range` is honoured.
 * - Pictures, video and audio are shown in the browser (`inline`); documents are downloaded (`attachment`), with
 *   the original file name.
 * - A wrong or old file name in the address (or a width written differently, `0640w`) redirects to the right one.
 * - Files opened on their own get a policy that lets them run and load nothing.
 */
final readonly class MediaFileHandler implements RequestHandlerInterface
{
    public const string KEY_ATTRIBUTE = 'key';
    public const string WIDTH_ATTRIBUTE = 'width';
    public const string NAME_ATTRIBUTE = 'name';

    private const string PUBLIC_CACHE = 'public, max-age=31536000, immutable';
    private const string PRIVATE_CACHE = 'private, no-store';
    private const string CDN_CACHE = 'public, max-age=300, s-maxage=300';

    /** RFC 9110 §5.6.7, always in GMT (`DATE_RFC7231` is deprecated since PHP 8.5). */
    private const string HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    /** A file opened on its own runs nothing and loads nothing. */
    private const string CONTENT_SECURITY_POLICY = "default-src 'none'; sandbox";

    public function __construct(
        private MediaRepository $media,
        private MediaFiles $files,
        private MediaAccess $access,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private LoggerInterface $logger,
        private MediaUrlSigner $signer,
        private SiteSettings $settings,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $key = $request->getAttribute(self::KEY_ATTRIBUTE);
        $media = is_string($key) ? $this->media->findByKey($key) : null;
        $rawWidth = $request->getAttribute(self::WIDTH_ATTRIBUTE);
        $width = is_string($rawWidth) ? (int) $rawWidth : null;
        if (
            $media === null
            || ($width !== null && !$media->hasVariant($width))
        ) {
            throw new HttpNotFoundException($request);
        }
        $signed = $this->signer->verifies($media->key(), $width, $request->getQueryParams());
        if (!$signed && !$this->access->canView(Actor::fromRequest($request), $media)) {
            throw new HttpNotFoundException($request);
        }

        $cache = $this->cache($media, $signed);
        if ($request->getAttribute(self::NAME_ATTRIBUTE) !== $media->fileName() || ($width !== null && $rawWidth !== (string) $width)) {
            // Stays on this host and keeps the query, so a signature still applies after the redirect.
            $query = $request->getUri()->getQuery();

            return $this->responses->createResponse(301)
                ->withHeader('Location', new OriginMediaUrlGenerator()->url($media, $width) . ($query === '' ? '' : '?' . $query))
                ->withHeader('Cache-Control', $cache);
        }

        $size = $this->files->size($media, $width);
        $stream = $size === null ? null : $this->files->readStream($media, $width);
        if ($size === null || $stream === null) {
            $this->logger->error('Media file {key} ({width}) is in the library but missing from the site\'s storage.', [
                'key' => $media->key(),
                'width' => $width === null ? 'original' : $width . ' px',
            ]);

            throw new HttpNotFoundException($request);
        }

        $etag = sprintf('"%s%s"', $media->key(), $width === null ? '' : '-' . $width);
        // Whole seconds, as HTTP dates have no fractions.
        $lastModified = new DateTimeImmutable('@' . $media->createdAt()->getTimestamp());
        $response = $this->responses->createResponse()
            ->withHeader('ETag', $etag)
            ->withHeader('Last-Modified', $lastModified->format(self::HTTP_DATE))
            ->withHeader('Cache-Control', $cache)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);

        if (self::notModified($request, $etag, $lastModified)) {
            fclose($stream);

            return $response->withStatus(304);
        }

        $response = $response
            ->withHeader('Content-Type', $media->mimeType())
            ->withHeader('Content-Disposition', self::disposition($media));

        try {
            $range = self::rangeApplies($request, $etag, $lastModified)
                ? ByteRange::parse($request->getHeaderLine('Range'), $size)
                : null;
        } catch (UnsatisfiableRange) {
            fclose($stream);

            return $response->withStatus(416)->withHeader('Content-Range', sprintf('bytes */%d', $size));
        }

        if ($range !== null) {
            $response = $response
                ->withStatus(206)
                ->withHeader('Content-Range', $range->contentRange($size))
                ->withHeader('Content-Length', (string) $range->length());
            $body = $request->getMethod() === 'HEAD' ? null : new RangeStream($stream, $range->start, $range->length());
        } else {
            $response = $response->withHeader('Content-Length', (string) $size);
            $body = $request->getMethod() === 'HEAD' ? null : $this->streams->createStreamFromResource($stream);
        }

        if ($body === null) {
            fclose($stream);

            return $response;
        }

        return $response->withBody($body);
    }

    private function cache(Media $media, bool $signed): string
    {
        if ($signed || !$media->isPublished() || SiteVisibility::isMembersOnly($this->settings)) {
            return self::PRIVATE_CACHE;
        }

        return $this->site->storage->cdnUrl === null ? self::PUBLIC_CACHE : self::CDN_CACHE;
    }

    /**
     * `If-None-Match` wins over `If-Modified-Since` (RFC 9110 §13.2.2).
     */
    private static function notModified(ServerRequestInterface $request, string $etag, DateTimeImmutable $lastModified): bool
    {
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '') {
            $tags = array_map(static fn (string $tag): string => (string) preg_replace('#^W/#', '', trim($tag)), explode(',', $ifNoneMatch));

            return in_array($etag, $tags, true) || in_array('*', $tags, true);
        }

        $since = self::date($request->getHeaderLine('If-Modified-Since'));

        return $since !== null && $lastModified <= $since;
    }

    /**
     * A `Range` is used only when `If-Range` is absent or still matches the file.
     */
    private static function rangeApplies(ServerRequestInterface $request, string $etag, DateTimeImmutable $lastModified): bool
    {
        if (!$request->hasHeader('Range')) {
            return false;
        }
        $ifRange = trim($request->getHeaderLine('If-Range'));
        if ($ifRange === '' || $ifRange === $etag) {
            return true;
        }
        $date = self::date($ifRange);

        return $date?->getTimestamp() === $lastModified->getTimestamp();
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::HTTP_DATE, trim($value), new DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }

    private static function disposition(Media $media): string
    {
        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $media->kind()->isInline() ? 'inline' : 'attachment',
            $media->fileName(),
            rawurlencode($media->originalName()),
        );
    }
}
