<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageSearchDocuments;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Module\Search\SearchDocument;
use SightPress\Module\Search\SearchVisibility;

#[CoversClass(PageSearchDocuments::class)]
#[UsesNamespace('SightPress\Core')]
final class PageSearchDocumentsTest extends TestCase
{
    private PageFixture $pages;

    private BlockFixture $blocks;

    protected function setUp(): void
    {
        $this->pages = new PageFixture();
        $this->blocks = new BlockFixture([], $this->pages->database);
    }

    public function testEveryPublishedTranslationIsADocumentWithItsTextAndBreadcrumb(): void
    {
        $picture = $this->blocks->addMedia();
        $file = $this->blocks->media()->find($picture);
        self::assertNotNull($file);
        $file->describe('en-GB', 'A lighthouse at dawn', 'The north light', new DateTimeImmutable());
        $this->blocks->media()->save($file);
        $about = $this->pages->published('About us');
        $team = $this->pages->published('Our team', [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Who we are', 'level' => 2]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>Read <a href="/news">our <strong>news</strong></a>.</p>']],
            ['type' => 'image', 'version' => 1, 'data' => ['media' => $picture]],
        ], $about);
        $this->pages->translated($about, 'pt-PT', new PageContent('Sobre nós', 'sobre', 'Quem somos'));
        $this->pages->translated($team, 'pt-PT', new PageContent('A equipa', 'equipa'));
        $draft = $this->pages->editor()->create('en-GB', new PageContent('Draft'));
        $hidden = $this->pages->editor()->create('en-GB', new PageContent('Hidden', 'hidden', excludeFromSiteSearch: true));
        $hidden->changeState(PageState::Published, new DateTimeImmutable());
        $this->pages->repository()->flush();
        $provider = $this->pages->searchDocuments();

        self::assertSame('page', $provider->source());
        $english = self::keyed($provider->documents('en-GB'));
        $portuguese = self::keyed($provider->documents('pt-PT'));
        self::assertSame([self::ref($about), self::ref($team)], array_map(strval(...), array_keys($english)));
        $document = $english[self::ref($team)] ?? self::fail('no team page');
        self::assertSame(['/about-us/our-team', 'Our team', '', ['About us'], SearchVisibility::Public], [$document->path, $document->title, $document->summary, $document->breadcrumb, $document->visibility]);
        self::assertSame('Who we are Read our news. A lighthouse at dawn The north light', $document->body);
        self::assertSame(['Quem somos', ['Sobre nós']], [($portuguese[self::ref($about)] ?? null)?->summary, ($portuguese[self::ref($team)] ?? null)?->breadcrumb]);
        self::assertSame([], self::keyed($provider->documents('fr')));

        self::assertEquals($document, $provider->document(self::ref($team), 'en-GB'));
        self::assertSame('/pt-pt', $provider->document(self::ref($about), 'pt-PT')?->path, 'the home page');
        foreach ([[self::ref($draft), 'en-GB'], [self::ref($hidden), 'en-GB'], ['999', 'en-GB'], ['0', 'en-GB'], ['1x', 'en-GB'], [self::ref($team), 'fr']] as [$ref, $locale]) {
            self::assertNull($provider->document($ref, $locale), $ref . ' ' . $locale);
        }
    }

    public function testMembersPagesAndMembersSitesAreForMembers(): void
    {
        $public = $this->pages->published('Opening hours');
        $members = $this->pages->published('Committee');
        $members->setPublic(false, new DateTimeImmutable());
        $this->pages->repository()->flush();
        $provider = $this->pages->searchDocuments();

        self::assertSame(SearchVisibility::Public, $provider->document(self::ref($public), 'en-GB')?->visibility);
        self::assertSame(SearchVisibility::Members, $provider->document(self::ref($members), 'en-GB')?->visibility);

        $this->pages->database->settings()->set(SiteVisibility::SETTING, SiteVisibility::MEMBERS);
        self::assertSame(SearchVisibility::Members, $provider->document(self::ref($public), 'en-GB')?->visibility);
    }

    public function testFindsThePagesBelowAPageAndThePagesThatShowAFile(): void
    {
        $picture = $this->blocks->addMedia();
        $other = $this->blocks->addMedia();
        $top = $this->pages->published('Top');
        $child = $this->pages->published('Child', [['type' => 'columns', 'version' => 1, 'data' => ['columns' => [
            ['blocks' => [['type' => 'image', 'version' => 1, 'data' => ['media' => $picture, 'alt' => 'A boat']]]],
            ['blocks' => []],
        ]]]], $top);
        $grandchild = $this->pages->published('Grandchild', [], $child);
        $this->pages->translated($top, 'pt-PT', new PageContent('Topo', 'topo'));
        $this->pages->translated($child, 'pt-PT', new PageContent('Filho', 'filho'));
        $this->pages->translated($grandchild, 'pt-PT', new PageContent('Neto', 'neto', null, null, [['type' => 'image', 'version' => 1, 'data' => ['media' => $other, 'alt' => 'Um barco']]]));
        $provider = $this->pages->searchDocuments();

        self::assertSame([(int) $top->id(), (int) $child->id(), (int) $grandchild->id()], $provider->withPagesBelow((int) $top->id()));
        self::assertSame([(int) $grandchild->id()], $provider->withPagesBelow((int) $grandchild->id()));
        self::assertSame([], $provider->withPagesBelow(999));
        self::assertSame([(int) $child->id()], $provider->usingMedia($picture));
        self::assertSame([(int) $grandchild->id()], $provider->usingMedia($other));
        self::assertSame([], $provider->usingMedia(999));
    }

    /**
     * @return non-empty-string
     */
    private static function ref(\SightPress\Core\Page\Entity\Page $page): string
    {
        $ref = (string) $page->id();
        if ($ref === '') {
            self::fail('The page has no id.');
        }

        return $ref;
    }

    /**
     * @param iterable<SearchDocument> $documents
     *
     * @return array<string, SearchDocument>
     */
    private static function keyed(iterable $documents): array
    {
        $keyed = [];
        foreach ($documents as $document) {
            $keyed[$document->ref] = $document;
        }

        return $keyed;
    }
}
