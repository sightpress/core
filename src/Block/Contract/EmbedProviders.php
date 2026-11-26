<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

/**
 * The outside services an `embed` block may show (blocks.md §1), and how a link to one becomes the address of its
 * player or map. Only these hosts are allowed, so the site's Content-Security-Policy lists exactly their
 * {@see frameOrigins()}. YouTube uses its privacy-enhanced host and Vimeo is asked not to track.
 */
final class EmbedProviders
{
    /** provider => [label, what it shows, privacy policy, origin of the frame] */
    public const array PROVIDERS = [
        'youtube' => ['YouTube', 'video', 'https://policies.google.com/privacy', 'https://www.youtube-nocookie.com'],
        'vimeo' => ['Vimeo', 'video', 'https://vimeo.com/privacy', 'https://player.vimeo.com'],
        'openstreetmap' => ['OpenStreetMap', 'map', 'https://osmfoundation.org/wiki/Privacy_Policy', 'https://www.openstreetmap.org'],
    ];

    /** The links editors can paste, for the block's schema. */
    public const string URL_PATTERN = '^https://(?:(?:www\.|m\.)?youtube\.com/(?:watch\?|embed/|shorts/)|youtu\.be/|(?:www\.)?vimeo\.com/[0-9]|(?:www\.)?openstreetmap\.org/)';

    /**
     * Where the frames of embeds may come from.
     *
     * @return list<string>
     */
    public static function frameOrigins(): array
    {
        return array_values(array_map(static fn (array $provider): string => $provider[3], self::PROVIDERS));
    }

    /**
     * The provider and the address to show in the frame, or null for a link no provider can show.
     *
     * @return array{provider: key-of<self::PROVIDERS>, src: non-empty-string}|null
     */
    public static function resolve(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return null;
        }
        $host = (string) preg_replace('/^(?:www|m)\./', '', strtolower($parts['host']));
        $path = $parts['path'] ?? '/';
        parse_str($parts['query'] ?? '', $query);

        return match ($host) {
            'youtube.com', 'youtu.be' => self::youtube($host, $path, $query),
            'vimeo.com' => preg_match('#^/(\d{1,12})(?:/([0-9a-f]{6,20}))?/?$#', $path, $match) === 1
                ? ['provider' => 'vimeo', 'src' => 'https://player.vimeo.com/video/' . $match[1] . '?dnt=1' . (isset($match[2]) ? '&h=' . $match[2] : '')]
                : null,
            'openstreetmap.org' => self::map($parts['fragment'] ?? '', $query),
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @return array{provider: 'youtube', src: non-empty-string}|null
     */
    private static function youtube(string $host, string $path, array $query): ?array
    {
        $id = $host === 'youtu.be'
            ? ltrim($path, '/')
            : (is_string($query['v'] ?? null) && $path === '/watch' ? $query['v'] : (string) preg_replace('#^/(?:embed|shorts)/#', '', $path));

        return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 ? ['provider' => 'youtube', 'src' => 'https://www.youtube-nocookie.com/embed/' . $id] : null;
    }

    /**
     * A map around the point of an OpenStreetMap link: `#map=zoom/lat/lon`, or `?mlat=…&mlon=…` with a marker.
     *
     * @param array<array-key, mixed> $query
     *
     * @return array{provider: 'openstreetmap', src: non-empty-string}|null
     */
    private static function map(string $fragment, array $query): ?array
    {
        $zoom = 15;
        $marker = is_numeric($query['mlat'] ?? null) && is_numeric($query['mlon'] ?? null);
        if (preg_match('#(?:^|&)map=(\d{1,2})/(-?\d{1,2}(?:\.\d+)?)/(-?\d{1,3}(?:\.\d+)?)#', $fragment, $match) === 1) {
            [$zoom, $lat, $lon] = [(int) $match[1], (float) $match[2], (float) $match[3]];
        } elseif ($marker) {
            [$lat, $lon] = [(float) $query['mlat'], (float) $query['mlon']];
        } else {
            return null;
        }
        if ($zoom < 1 || $zoom > 19 || abs($lat) > 85 || abs($lon) > 180) {
            return null;
        }
        $width = 360 / 2 ** $zoom;
        $box = [$lon - $width, $lat - $width / 2, $lon + $width, $lat + $width / 2];
        $src = 'https://www.openstreetmap.org/export/embed.html?bbox=' . implode('%2C', array_map(static fn (float $value): string => self::number($value), $box)) . '&layer=mapnik';
        if ($marker) {
            $src .= '&marker=' . self::number($lat) . '%2C' . self::number($lon);
        }

        return ['provider' => 'openstreetmap', 'src' => $src];
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
