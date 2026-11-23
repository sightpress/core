<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

use Psr\Cache\CacheItemPoolInterface;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteS3Storage;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Whether browsers on this site can send files straight to its bucket: asks the bucket the question a browser would
 * (a CORS preflight for `PUT` from the site's address). The answer is kept for ten minutes.
 */
final readonly class DirectUploadCheck
{
    public const string READY = 'ready';

    public const string LOCAL = 'local';

    public const string NO_CORS = 'no_cors';

    public const string UNREACHABLE = 'unreachable';

    private const int KEEP_SECONDS = 600;

    public function __construct(
        private Site $site,
        private HttpClientInterface $http,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param string $origin the site's address as the browser sees it, such as https://example.org
     *
     * @return self::READY|self::LOCAL|self::NO_CORS|self::UNREACHABLE
     */
    public function status(string $origin): string
    {
        $s3 = $this->site->storage->s3;
        if ($s3 === null || !$this->site->storage->isCloud()) {
            return self::LOCAL;
        }
        $item = $this->cache->getItem('direct-upload.' . hash('sha256', implode('|', [$origin, $s3->uploadOrigin(), $s3->bucket, $s3->prefix])));
        $known = $item->get();
        if (in_array($known, [self::READY, self::NO_CORS, self::UNREACHABLE], true)) {
            return $known;
        }
        $status = $this->ask($origin, $s3);
        $this->cache->save($item->set($status)->expiresAfter(self::KEEP_SECONDS));

        return $status;
    }

    /**
     * @return self::READY|self::NO_CORS|self::UNREACHABLE
     */
    private function ask(string $origin, SiteS3Storage $s3): string
    {
        try {
            $path = ($s3->pathStyle ? '/' . $s3->bucket : '') . '/' . $s3->prefix . '/incoming/check';
            $response = $this->http->request('OPTIONS', $s3->uploadOrigin() . $path, [
                'headers' => ['Origin' => $origin, 'Access-Control-Request-Method' => 'PUT'],
            ]);
            $code = $response->getStatusCode();
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface) {
            return self::UNREACHABLE;
        }
        $allowedOrigin = $headers['access-control-allow-origin'][0] ?? '';
        $methods = strtoupper($headers['access-control-allow-methods'][0] ?? '');

        return $code < 300 && in_array($allowedOrigin, [$origin, '*'], true) && str_contains($methods, 'PUT')
            ? self::READY
            : self::NO_CORS;
    }
}
