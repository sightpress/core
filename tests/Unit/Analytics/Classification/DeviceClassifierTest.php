<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics\Classification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\Classification\DeviceClassifier;
use SightPress\Core\Tests\Support\Analytics\AnalyticsFixture;

#[CoversClass(DeviceClassifier::class)]
final class DeviceClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function devices(): iterable
    {
        yield 'a computer' => [[], DeviceClassifier::COMPUTER];
        yield 'the mobile client hint' => [['Sec-CH-UA-Mobile' => '?1', 'User-Agent' => 'Mozilla/5.0 (Linux; Android 10; K)'], DeviceClassifier::PHONE];
        yield 'not mobile by the hint, but an Android tablet' => [['Sec-CH-UA-Mobile' => '?0', 'User-Agent' => 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 Chrome/131.0 Safari/537.36'], DeviceClassifier::TABLET];
        yield 'an Android phone' => [['User-Agent' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36'], DeviceClassifier::PHONE];
        yield 'an iPhone' => [['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148'], DeviceClassifier::PHONE];
        yield 'an iPad' => [['User-Agent' => 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148'], DeviceClassifier::TABLET];
        yield 'a Kindle' => [['User-Agent' => 'Mozilla/5.0 (Linux; U; en-us; KFTT Build/IML74K) AppleWebKit/535.19 Silk/3.4 Mobile Safari/535.19'], DeviceClassifier::TABLET];
        yield 'Windows' => [['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36'], DeviceClassifier::COMPUTER];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('devices')]
    public function testTellsPhonesTabletsAndComputersApart(array $headers, string $device): void
    {
        self::assertSame($device, new DeviceClassifier()->classify(AnalyticsFixture::request('/', $headers)));
    }
}
