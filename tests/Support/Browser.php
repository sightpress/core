<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use LogicException;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Kernel;

/**
 * Sends requests to a kernel like a browser on one host would: it keeps the cookies the responses set and sends
 * forms back with their CSRF token.
 */
final class Browser
{
    /** @var array<string, string> */
    public array $cookies = [];

    public function __construct(
        private readonly Kernel $kernel,
        private readonly string $origin,
    ) {
    }

    /**
     * @param array<string, mixed>                               $body
     * @param array<string, string>                              $headers
     * @param array<string, \Psr\Http\Message\UploadedFileInterface> $files
     */
    public function request(string $method, string $path, array $body = [], array $headers = [], array $files = []): ResponseInterface
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = new ServerRequest($method, $this->origin . $path, $headers, null, '1.1', ['REMOTE_ADDR' => '192.0.2.30'])
            ->withCookieParams($this->cookies)
            ->withQueryParams($query);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }
        if ($files !== []) {
            $request = $request->withUploadedFiles($files);
        }

        $response = $this->kernel->handle($request);
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = explode('=', $pair, 2);
            if ($value === '' || str_contains($cookie, 'Max-Age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return $response;
    }

    /**
     * Opens a page and sends a form back to `$path` (or `$action`) with the CSRF token and the given fields.
     *
     * @param array<string, mixed>                               $fields form fields; nested arrays for names such as `blocks[0][type]`
     * @param array<string, \Psr\Http\Message\UploadedFileInterface> $files
     * @param array<string, string>                              $headers
     */
    public function submit(string $path, array $fields, ?string $action = null, array $files = [], array $headers = []): ResponseInterface
    {
        $page = (string) $this->request('GET', $path)->getBody();
        preg_match_all('/<input type="hidden" name="(csrf_[a-z]+)" value="([^"]+)">/', $page, $hidden, PREG_SET_ORDER);
        if (count($hidden) < 2) {
            throw new LogicException(sprintf('The page %s has no form with a CSRF token.', $path));
        }
        // Every form of a page carries the same token: take the first pair.
        foreach (array_slice($hidden, 0, 2) as [, $name, $value]) {
            $fields[$name] = html_entity_decode($value);
        }

        return $this->request('POST', $action ?? $path, $fields, $headers, $files);
    }
}
