<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpSpecializedException;

/**
 * 413: the request was larger than the server accepts, so nothing in it (the CSRF token included) could be read.
 * The page says how much the server accepts and links back to the form the person came from.
 */
final class RequestTooLargeException extends HttpSpecializedException
{
    protected string $title = '413 Content Too Large';

    /**
     * @param int|null    $limit bytes the server accepts in one request, when known
     * @param string|null $back  path (and query) of the page on this site that sent the request
     */
    public function __construct(
        ServerRequestInterface $request,
        public readonly ?int $limit = null,
        public readonly ?string $back = null,
    ) {
        parent::__construct($request, 'Request too large.');
        $this->code = 413;
    }

    /**
     * The path and query of the request's `Referer`, if it is a page of the same site; null otherwise.
     */
    public static function backFrom(ServerRequestInterface $request): ?string
    {
        $referer = parse_url($request->getHeaderLine('Referer'));
        if (!is_array($referer) || ($referer['host'] ?? null) !== $request->getUri()->getHost() || !isset($referer['path'])) {
            return null;
        }
        $path = $referer['path'];
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return $path . (isset($referer['query']) ? '?' . $referer['query'] : '');
    }
}
