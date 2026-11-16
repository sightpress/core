<?php

declare(strict_types=1);

namespace SightPress\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Picks between HTML and JSON (errors) or HTML and Markdown (pages) from the `Accept` header. HTML wins ties and
 * anything unclear, because browsers and assistive technology always need a readable page.
 */
final readonly class AcceptNegotiator
{
    public function prefersJson(ServerRequestInterface $request): bool
    {
        [$html, $other] = self::qualities($request, static fn (string $type): bool => $type === 'application/json' || str_ends_with($type, '+json'));

        return $other > $html;
    }

    public function prefersMarkdown(ServerRequestInterface $request): bool
    {
        [$html, $other] = self::qualities($request, static fn (string $type): bool => $type === 'text/markdown' || $type === 'text/x-markdown');

        return $other > $html;
    }

    /**
     * @param callable(string): bool $isOther
     *
     * @return array{float, float} the highest quality given to HTML and to the other type
     */
    private static function qualities(ServerRequestInterface $request, callable $isOther): array
    {
        $html = 0.0;
        $other = 0.0;

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
            } elseif ($isOther($type)) {
                $other = max($other, $quality);
            }
        }

        return [$html, $other];
    }
}
