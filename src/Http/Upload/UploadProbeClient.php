<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds the largest request the web server really lets through, to the nearest megabyte: it doubles the size until one
 * is refused, then narrows it down. A web server refuses on the `Content-Length`, so only accepted sizes are sent.
 */
final readonly class UploadProbeClient
{
    private const int STEP = 1024 * 1024;

    private const int TIMEOUT = 300;

    public function __construct(
        private HttpClientInterface $http,
        private UploadProbe $probe,
    ) {
    }

    /**
     * @param string       $baseUrl the address of the site, as the web server is reached
     * @param int          $most    the largest size worth trying
     *
     * @return array{accepted: int, refused: int|null} the largest size accepted (0: none), and the smallest refused
     *
     * @throws RuntimeException in plain words, when the probe can't tell
     */
    public function measure(string $baseUrl, int $most): array
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || !isset($parts['host'])) {
            throw new RuntimeException('The probe address must start with http:// or https://, for example http://nginx.');
        }
        if (!$this->probe->canSign()) {
            throw new RuntimeException('The probe needs APP_SECRET to be set.');
        }
        $url = rtrim($baseUrl, '/') . UploadProbe::PATH;
        $most = max(self::STEP, $most);

        $accepted = 0;
        $size = self::STEP;
        while ($this->send($url, $size)) {
            $accepted = $size;
            if ($size === $most) {
                return ['accepted' => $accepted, 'refused' => null];
            }
            $size = min($most, $size * 2);
        }
        $refused = $size;
        while ($refused - $accepted > self::STEP) {
            $middle = max(1, $accepted + (int) (ceil(($refused - $accepted) / 2 / self::STEP) * self::STEP));
            if ($this->send($url, $middle)) {
                $accepted = $middle;
            } else {
                $refused = $middle;
            }
        }

        return ['accepted' => $accepted, 'refused' => $refused];
    }

    /**
     * @param positive-int $bytes
     */
    private function send(string $url, int $bytes): bool
    {
        $left = $bytes;
        try {
            $response = $this->http->request('POST', $url . '?' . $this->probe->query($bytes), [
                'headers' => ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $bytes, 'Expect' => '100-continue'],
                'body' => static function (int $size) use (&$left): string {
                    $chunk = str_repeat("\0", min($size, $left));
                    $left -= strlen($chunk);

                    return $chunk;
                },
                'timeout' => self::TIMEOUT,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException('The probe couldn’t reach the web server at that address.', previous: $exception);
        }
        if ($status === 413) {
            return false;
        }
        if ($status !== 204 || ($headers[strtolower(UploadProbe::ANSWER_HEADER)][0] ?? null) !== 'accepted') {
            throw new RuntimeException(sprintf('The probe got the answer %d from that address, so it isn’t reaching this SightPress, or APP_SECRET differs.', $status));
        }

        return true;
    }
}
