<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Classification;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The kind of device a person reads on: `phone`, `tablet` or `computer`, from the `Sec-CH-UA-Mobile` client hint
 * and a few user agent rules (no user agent library, nothing else kept).
 */
final readonly class DeviceClassifier
{
    public const string PHONE = 'phone';
    public const string TABLET = 'tablet';
    public const string COMPUTER = 'computer';

    public function classify(ServerRequestInterface $request): string
    {
        if (trim($request->getHeaderLine('Sec-CH-UA-Mobile')) === '?1') {
            return self::PHONE;
        }
        $agent = $request->getHeaderLine('User-Agent');

        return match (true) {
            preg_match('/iPad|Tablet|Kindle|Silk\/|PlayBook|Nexus (7|9|10)\b/i', $agent) === 1,
            preg_match('/Android/i', $agent) === 1 && preg_match('/Mobile/i', $agent) !== 1 => self::TABLET,
            preg_match('/Mobi|iPhone|iPod|Android|Windows Phone|BlackBerry|Opera Mini/i', $agent) === 1 => self::PHONE,
            default => self::COMPUTER,
        };
    }
}
