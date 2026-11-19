<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\Slug;
use SightPress\Core\Tenant\LocaleCode;

#[CoversClass(PageTranslation::class)]
#[UsesClass(Page::class)]
#[UsesClass(PageContent::class)]
#[UsesClass(Slug::class)]
#[UsesClass(LocaleCode::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
final class PageTranslationTest extends TestCase
{
    private DateTimeImmutable $now;

    private Page $page;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-16 10:00:00 UTC');
        $this->page = new Page(null, 0, null, $this->now);
    }

    public function testIsReadByVisitorsOnlyWhenThePageIsPublishedAndItIsntADraft(): void
    {
        $translation = new PageTranslation($this->page, 'pt-PT', new PageContent('Sobre'), 'sobre', $this->now, true);
        self::assertTrue($translation->isDraft());
        self::assertFalse($translation->isPublished());

        $this->page->changeState(\SightPress\Core\Rbac\Contract\PageState::Published, $this->now);
        self::assertFalse($translation->isPublished());
        $later = new DateTimeImmutable('2026-11-18 10:00:00 UTC');
        $translation->setDraft(false, $later);
        self::assertTrue($translation->isPublished());
        self::assertSame($later, $translation->updatedAt());
        self::assertFalse($translation->snapshot()['draft']);
    }

    public function testHoldsThePageInOneLocale(): void
    {
        $blocks = [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Hi']]];
        $translation = new PageTranslation($this->page, 'en-GB', new PageContent('Team', null, 'Who', 'Desc', $blocks), 'about/team', $this->now);

        self::assertNull($translation->id());
        self::assertSame($this->page, $translation->page());
        self::assertSame(['en-GB', 'Team', 'team', 'about/team', 'Who', 'Desc', $blocks, $this->now], [
            $translation->locale(),
            $translation->title(),
            $translation->slug(),
            $translation->path(),
            $translation->summary(),
            $translation->description(),
            $translation->blocks(),
            $translation->updatedAt(),
        ]);
        self::assertEquals(new PageContent('Team', 'team', 'Who', 'Desc', $blocks), $translation->content());
        self::assertSame(
            ['title' => 'Team', 'slug' => 'team', 'path' => 'about/team', 'summary' => 'Who', 'description' => 'Desc', 'blocks' => $blocks, 'social_image' => null, 'seo_title' => null, 'noindex' => false, 'canonical_url' => null, 'exclude_from_site_search' => false, 'draft' => false, 'machine_drafted' => false],
            $translation->snapshot(),
        );
        self::assertNull($translation->socialImage());
        $translation->rewrite(new PageContent('Team', 'team', null, null, [], 9), 'about/team', $this->now);
        self::assertSame(9, $translation->socialImage());
        self::assertSame(9, $translation->content()->socialImage);
        $translation->rewrite(new PageContent('Team', 'team', null, null, [], null, 'Our team', true, 'https://example.org/team'), 'about/team', $this->now);
        self::assertSame(['Our team', true, 'https://example.org/team'], [$translation->seoTitle(), $translation->isNoindex(), $translation->canonicalUrl()]);
        self::assertEquals(new PageContent('Team', 'team', null, null, [], null, 'Our team', true, 'https://example.org/team'), $translation->content());
        self::assertFalse($translation->isExcludedFromSiteSearch());
        $translation->rewrite(new PageContent('Team', 'team', excludeFromSiteSearch: true), 'about/team', $this->now);
        self::assertTrue($translation->isExcludedFromSiteSearch());
        self::assertTrue($translation->content()->excludeFromSiteSearch);
        self::assertTrue($translation->snapshot()['exclude_from_site_search']);
    }

    public function testRefusesALocaleThatIsNotACode(): void
    {
        $this->expectException(PageException::class);
        $this->expectExceptionMessage('"english" is not a locale code such as "en-GB".');

        new PageTranslation($this->page, 'english', new PageContent('Team'), 'team', $this->now);
    }

    public function testThePathEndsWithTheSlug(): void
    {
        $this->expectException(PageException::class);
        $this->expectExceptionMessage('The address "about/staff" must end with the slug "team".');

        new PageTranslation($this->page, 'en-GB', new PageContent('Team'), 'about/staff', $this->now);
    }

    public function testThePathHasAMaximumLength(): void
    {
        $this->expectException(PageException::class);
        $this->expectExceptionMessage('The full address must be 620 characters or fewer.');

        new PageTranslation($this->page, 'en-GB', new PageContent('Team'), str_repeat('a/', 310) . 'team', $this->now);
    }

    public function testRebasesWhenAPageAboveMoves(): void
    {
        $translation = new PageTranslation($this->page, 'en-GB', new PageContent('Team'), 'about/people/team', $this->now);

        $translation->rebase('about', 'company/info');

        self::assertSame('company/info/people/team', $translation->path());

        $this->expectException(PageException::class);
        $this->expectExceptionMessage('The address "company/info/people/team" is not below "company/in".');
        $translation->rebase('company/in', 'x');
    }
}
