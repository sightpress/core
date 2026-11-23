<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Publish;

use SightPress\Core\Tests\Support\Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Contract\PublishIssue;
use SightPress\Core\Page\Contract\PublishReport;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\Publish\PublishChecker;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\VersionedBlockType;
use SightPress\Core\Theme\ThemeLoader;
use SightPress\Core\Theme\ThemePageColours;
use SightPress\Module\Block\A11ySeverity;

#[CoversClass(PublishChecker::class)]
#[CoversClass(PublishReport::class)]
#[CoversClass(PublishIssue::class)]
#[UsesNamespace('SightPress\Core')]
final class PublishCheckerTest extends TestCase
{
    private PageFixture $pages;

    private BlockFixture $blocks;

    protected function setUp(): void
    {
        $this->pages = new PageFixture();
        $this->blocks = new BlockFixture([], $this->pages->database);
    }

    public function testAGoodPageCanBePublishedWithoutSuggestions(): void
    {
        $picture = $this->blocks->addMedia();
        $page = $this->pages->editor()->create('en-GB', new PageContent('Opening times', null, 'When the library is open.', 'Opening times of the Riverside Library, every day of the week.', [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Weekdays', 'level' => 2]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<h3>Mornings</h3><p>From 9am. <a href="/contact">Contact the front desk</a>. <span lang="fr">Bienvenue</span>.</p>']],
            ['type' => 'image', 'version' => 1, 'data' => ['media' => $picture, 'alt' => 'The front desk']],
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Weekends', 'level' => 2]],
        ]));

        $report = $this->checker()->check($page);

        self::assertTrue($report->canPublish());
        self::assertSame([], Text::of($report->issues));
    }

    public function testAltTextTheAssistantSuggestedStopsPublishingUntilSomeoneChecksIt(): void
    {
        $picture = $this->blocks->addMedia();
        $media = $this->blocks->media()->find($picture) ?? self::fail('No picture.');
        $media->describe('en-GB', 'A reading room', null, new \DateTimeImmutable());
        $media->markAltSuggested('en-GB', true);
        $this->blocks->media()->save($media);
        $page = $this->pages->editor()->create('en-GB', new PageContent('Our rooms', null, 'The rooms of the library.', 'The rooms of the Riverside Library, with pictures of each one.', [
            ['type' => 'image', 'version' => 1, 'data' => ['media' => $picture]],
            ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [['blocks' => [['type' => 'image', 'version' => 1, 'data' => ['media' => $picture]]]], ['blocks' => [['type' => 'image', 'version' => 1, 'data' => ['media' => $picture, 'alt' => 'Written here']]]]]]],
            ['type' => 'image', 'version' => 1, 'data' => ['media' => $picture, 'decorative' => true]],
        ]));

        $report = $this->checker()->check($page);
        self::assertSame(
            [['media.alt_unchecked', '/blocks/0/data/media'], ['media.alt_unchecked', '/blocks/1/data/columns/0/blocks/0/data/media']],
            array_values(array_map(static fn (PublishIssue $issue): array => [$issue->rule, $issue->pointer], array_filter($report->errors(), static fn (PublishIssue $issue): bool => $issue->rule === 'media.alt_unchecked'))),
            'only where the library’s text shows: not with the block’s own alt text, nor when decorative',
        );
        self::assertSame(['1.1.1'], array_values(array_filter($report->errors(), static fn (PublishIssue $issue): bool => $issue->rule === 'media.alt_unchecked'))[0]->wcag);

        $media->markAltSuggested('en-GB', false);
        $this->blocks->media()->save($media);
        self::assertSame([], array_filter($this->checker()->check($page)->errors(), static fn (PublishIssue $issue): bool => $issue->rule === 'media.alt_unchecked'));
    }

    public function testATranscriptTheAssistantTidiedStopsPublishingUntilSomeoneChecksIt(): void
    {
        $this->pages = new PageFixture(null, [new \SightPress\Core\Block\Type\AudioBlock()]);
        $this->blocks = new BlockFixture([new \SightPress\Core\Block\Type\AudioBlock()], $this->pages->database);
        $id = $this->blocks->addMedia(kind: \SightPress\Core\Media\Contract\MediaKind::Audio, fileName: 'talk.mp3');
        $audio = $this->blocks->media()->find($id) ?? self::fail('No audio.');
        $audio->setTranscript('en-GB', 'Hello, everyone.', new \DateTimeImmutable());
        $audio->markTranscriptSuggested('en-GB', true);
        $this->blocks->media()->save($audio);
        $page = $this->pages->editor()->create('en-GB', new PageContent('Our talk', null, 'A talk at the library.', 'A recording of the talk at the Riverside Library, with its transcript.', [
            ['type' => 'audio', 'version' => 1, 'data' => ['media' => $id, 'title' => 'Library talk']],
            ['type' => 'audio', 'version' => 1, 'data' => ['media' => $id, 'title' => 'Own text', 'transcript' => 'Written here.']],
        ]));
        $unchecked = static fn (PublishIssue $issue): bool => $issue->rule === 'media.transcript_unchecked';

        $found = array_values(array_filter($this->checker()->check($page)->errors(), $unchecked));
        self::assertSame(['/blocks/0/data/media'], array_map(static fn (PublishIssue $issue): string => $issue->pointer, $found));
        self::assertSame(['1.2.1'], $found[0]->wcag);

        $audio->markTranscriptSuggested('en-GB', false);
        $this->blocks->media()->save($audio);
        self::assertSame([], array_filter($this->checker()->check($page)->errors(), $unchecked));
    }

    public function testFindsWhatStopsPublishingAndWhatIsWorthFixing(): void
    {
        $this->pages->editor()->create('en-GB', new PageContent('Visit us'));
        $draft = $this->blocks->addMedia(published: false);
        $page = $this->pages->editor()->create('en-GB', new PageContent('visit US', 'visit-us-again', null, null, [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Too deep', 'level' => 4]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p><a href="/a">here</a> <span lang="english">Hello</span> <span style="color: #0a3d91">Dark blue</span></p>']],
            ['type' => 'image', 'version' => 1, 'data' => ['media' => $draft]],
        ]));
        $this->pages->translated($page, 'pt-PT', new PageContent('Visite', null, 'Resumo', str_repeat('Descrição longa. ', 12)));

        $report = $this->checker()->check($page);

        self::assertFalse($report->canPublish());
        self::assertSame(
            [
                ['en-GB', 'image.alt_missing', '/blocks/2/data/alt'],
                ['en-GB', 'media.unpublished', '/blocks/2/data/media'],
                ['en-GB', 'page.heading_skipped', '/blocks/0'],
                ['en-GB', 'page.lang_invalid', '/blocks/1'],
                ['en-GB', 'page.contrast', '/blocks/1'],
            ],
            Text::of(array_map(static fn (PublishIssue $issue): array => [$issue->locale, $issue->rule, $issue->pointer], $report->errors())),
        );
        self::assertSame(
            [
                ['en-GB', 'rich_text.link_vague', '/blocks/1/data/html', 'accessibility'],
                ['en-GB', 'seo.description_missing', '/description', 'seo'],
                ['en-GB', 'seo.summary_missing', '/summary', 'seo'],
                ['en-GB', 'seo.title_duplicate', '/title', 'seo'],
                ['pt-PT', 'seo.description_long', '/description', 'seo'],
            ],
            Text::of(array_map(static fn (PublishIssue $issue): array => [$issue->locale, $issue->rule, $issue->pointer, $issue->kind], $report->warnings())),
        );
        self::assertSame('Another page is also called "visit US". Give this page a title of its own, so people can tell them apart in search results.', Text::of($report->warnings()[3]->message));
        self::assertSame('Search engines show about 160 characters of the description; this one has 203. Shorten it.', Text::of($report->warnings()[4]->message));
        self::assertSame(A11ySeverity::Error, $report->issues[0]->severity, 'errors come first');
    }

    public function testBlocksThatAreNoLongerValidStopPublishing(): void
    {
        $pages = new PageFixture($this->pages->database, [new VersionedBlockType()]);
        $page = $pages->editor()->create('en-GB', new PageContent('Badges', null, 'S', 'D', [['type' => 'acme.badge', 'version' => 2, 'data' => ['label' => 'New']]]));

        $report = $this->checker()->check($page);

        self::assertSame([['blocks.invalid', '/blocks/0/type']], Text::of(array_map(static fn (PublishIssue $issue): array => [$issue->rule, $issue->pointer], $report->errors())));
    }

    public function testALanguageTheSiteNoLongerHasWaitsAndCantBePublishedOnItsOwn(): void
    {
        $page = $this->pages->editor()->create('en-GB', new PageContent('Home', null, 'S', 'D'));
        $this->pages->translated($page, 'pt-PT', new PageContent('Início', null, 'S', 'D'));
        $checker = $this->checker(PageFixture::site(['en-GB']));

        self::assertSame([], Text::of($checker->check($page)->issues), 'a removed language doesn\'t stop the page');
        $report = $checker->check($page, 'pt-PT');
        self::assertSame([['pt-PT', 'page.language', '']], Text::of(array_map(static fn (PublishIssue $issue): array => [$issue->locale, $issue->rule, $issue->pointer], $report->issues)));
        self::assertSame(['3.1.1'], Text::of($report->issues[0]->wcag));
    }

    public function testADraftTranslationIsCheckedWhenItIsPublished(): void
    {
        $page = $this->pages->editor()->create('en-GB', new PageContent('Home', null, 'S', 'D'));
        $this->pages->editor()->save($page, 'pt-PT', new PageContent('Início'));

        self::assertSame(['en-GB'], Text::of(array_values(array_unique(array_map(static fn (PublishIssue $issue): string => $issue->locale, [...$this->checker()->check($page)->issues, new PublishIssue('en-GB', A11ySeverity::Warning, PublishIssue::SEO, 'x', 'x', '')])))));
        self::assertSame(['pt-PT'], Text::of(array_values(array_unique(array_map(static fn (PublishIssue $issue): string => $issue->locale, $this->checker()->check($page, 'pt-PT')->issues)))));
    }

    public function testIssuesLinkToTheirFieldInTheEditor(): void
    {
        $issue = new PublishIssue('pt-BR', A11ySeverity::Warning, PublishIssue::SEO, 'seo.description_missing', 'Add one.', '/description');

        self::assertSame('pt-br-description', Text::of($issue->anchor()));
        self::assertSame('en-gb-blocks-3-data-columns-1-blocks-0-data-alt', Text::of(PublishIssue::anchorFor('en-GB', '/blocks/3/data/columns/1/blocks/0/data/alt')));
        self::assertSame('en-gb-blocks-0-data-a-b-c', Text::of(PublishIssue::anchorFor('en-GB', '/blocks/0/data/A~1b~0c')));
        self::assertSame('en-gb', Text::of(PublishIssue::anchorFor('en-GB', '')));
        self::assertSame('portugués (Brasil)', Text::of($issue->languageName('es')));
        self::assertSame('Portuguese (Brazil)', Text::of($issue->languageName()));
        self::assertSame('zz', Text::of(new PublishIssue('zz', A11ySeverity::Warning, PublishIssue::SEO, 'x.y', 'M', '')->languageName('zz')));
        self::assertFalse($issue->isError());
        self::assertTrue(new PublishReport([$issue])->canPublish());
    }

    private function checker(?Site $site = null): PublishChecker
    {
        $site ??= $this->pages->site;

        return new PublishChecker(
            $this->blocks->validator(),
            $this->blocks->renderer(),
            $this->blocks->contexts($site),
            $this->blocks->media(),
            $this->pages->repository(),
            new ThemePageColours(new ThemeLoader()->load(TemplatePaths::defaultTheme())),
            $site,
            $this->blocks->texts($site),
        );
    }
}
