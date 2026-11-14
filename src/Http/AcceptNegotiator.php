<?php

declare(strict_types=1);

namespace SightPress\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Picks between HTML and JSON from the `Accept` header. HTML wins ties and anything unclear, because browsers and
 * assistive technology always need a readable page.
 */
final readonly class AcceptNegotiator
{
    public function prefersJson(ServerRequestInterface $request): bool
    {
        $html = 0.0;
        $json = 0.0;

        foreach (explode(',', $request->getHeaderLine('Accept')) as $part) {
            $pieces = array_map(trim(...), explode(';', $part));
            $type = strtolower(array_shift($pieces));
            $quality = 1.0;
            foreach ($pieces as $parameter) {
                if (preg_match('/^q\s*=\s*([01](?:\.\d{1,3})?)$/i', $parameter, $match) === 1) {
                    $quality = (float) $match[1];
                }
            }

            if ($type === 'text/html' || $type === 'application/xhtml+xml') {
                $html = max($html, $quality);
            } elseif ($type === 'application/json' || str_ends_with($type, '+json')) {
                $json = max($json, $quality);
            }
        }

        return $json > $html;
    }
}
