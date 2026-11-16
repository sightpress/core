<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Seo\RobotsPolicy;
use SightPress\Core\Seo\SeoMeta;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\Tenant\TenantConfigLoader;

#[CoversClass(SeoMeta::class)]
#[CoversClass(RobotsPolicy::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
final class SeoMetaTest extends TestCase
{
    public function testTheTitleFallsBackToThePageTitleAndTheSiteName(): void
    {
        self::assertSame('Meet the team', SeoMeta::title('Meet the team', 'Team', 'Riverside'));
        self::assertSame('Team - Riverside', SeoMeta::title(null, 'Team', 'Riverside'));
        self::assertSame('Welcome to riverside', SeoMeta::title(null, 'Welcome to riverside', 'Riverside'));
    }

    public function testTheDescriptionFallsBackToTheSummaryThenTheFirstWords(): void
    {
        self::assertSame('Own words.', SeoMeta::description('Own words.', 'Summary.', '<p>Text</p>'));
        self::assertSame('Summary.', SeoMeta::description(null, 'Summary.', '<p>Text</p>'));
        self::assertSame('Opening hours Every day & night. Books Music', SeoMeta::description(null, null, "<h2>Opening hours</h2><p>Every day &amp; night.</p><ul><li>Books</li><li>Music</li></ul><form><label>Name</label></form><script>x()</script>"));
        self::assertNull(SeoMeta::description(null, null, '<p> </p>'));
    }

    public function testLongTextIsCutAtAWord(): void
    {
        $text = str_repeat('word ', 40);
        $trimmed = SeoMeta::trim($text, 160);
        self::assertSame(160, mb_strlen($trimmed), 'never longer than the limit, the … included');
        self::assertStringEndsWith('word…', $trimmed);
        self::assertSame('Short, but fine.', SeoMeta::trim("  Short,\n but fine. ", 160));
        self::assertSame(str_repeat('a', 19) . '…', SeoMeta::trim(str_repeat('a', 30), 20), 'one long word is cut in the middle');
        self::assertSame('Ends with a…', SeoMeta::trim('Ends with a, comma and more words', 13));
    }

    public function testTheRobotsRulesDependOnTheInstallAndThePage(): void
    {
        $project = new TestProject();
        $policy = static fn (string $environment): RobotsPolicy => new RobotsPolicy(new TenantConfigLoader()->load($project->path(), ['APP_ENV' => $environment]));

        foreach (['production' => [null, 'noindex', 'noindex, nofollow', 'noindex, nofollow'], 'staging' => array_fill(0, 4, 'noindex, nofollow'), 'test' => array_fill(0, 4, 'noindex, nofollow')] as $environment => $expected) {
            $robots = $policy($environment);
            self::assertSame($environment === 'production', $robots->isProduction());
            self::assertSame($expected, [$robots->forPage(true, false), $robots->forPage(true, true), $robots->forPage(false, false), $robots->forPage(false, true)], $environment);
        }
        $project->remove();
    }
}
