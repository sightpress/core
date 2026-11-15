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
     * @param array<string, string> $body
     */
    public function request(string $method, string $path, array $body = []): ResponseInterface
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = new ServerRequest($method, $this->origin . $path, [], null, '1.1', ['REMOTE_ADDR' => '192.0.2.30'])
            ->withCookieParams($this->cookies)
            ->withQueryParams($query);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
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
     * Opens a page and sends its form back with the CSRF token and the given fields.
     *
     * @param array<string, string> $fields
     */
    public function submit(string $path, array $fields): ResponseInterface
    {
        $page = (string) $this->request('GET', $path)->getBody();
        preg_match_all('/<input type="hidden" name="(csrf_[a-z]+)" value="([^"]+)">/', $page, $hidden, PREG_SET_ORDER);
        if (count($hidden) !== 2) {
            throw new LogicException(sprintf('The page %s has no form with a CSRF token.', $path));
        }
        foreach ($hidden as [, $name, $value]) {
            $fields[$name] = html_entity_decode($value);
        }

        return $this->request('POST', $path, $fields);
    }
}
