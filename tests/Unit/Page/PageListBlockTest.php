<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageListBlock;
use SightPress\Core\Page\PageListQuery;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\PlainBlockContext;
use SightPress\Module\Block\BlockContext;
use Nyholm\Psr7\ServerRequest;

#[CoversClass(PageListBlock::class)]
#[CoversClass(PageListQuery::class)]
#[UsesNamespace('SightPress\Core')]
final class PageListBlockTest extends TestCase
{
    private PageFixture $pages;

    private PageListBlock $type;

    private Page $services;

    protected function setUp(): void
    {
        $this->pages = new PageFixture();
        $this->pages->published('Welcome');
        $this->services = $this->page('Services', 'What we do.');
        $this->page('Repairs', 'We fix bikes.', $this->services);
        $this->page('Lessons', null, $this->services);
        $members = $this->page('Members area', 'Only for members.', $this->services);
        $members->setPublic(false, $this->pages->clock->now());
        $this->pages->editor()->create('en-GB', new PageContent('Draft', null, 'Not yet.'), $this->services);
        $this->page('Contact', 'Find us.');
        $this->pages->repository()->flush();
        $this->type = new PageListBlock(new PageListQuery($this->pages->database->entityManager, $this->pages->addresses(), $this->pages->database->settings()));
    }

    public function testDescribesItself(): void
    {
        self::assertSame(['page_list', 'List of pages', 1, []], [$this->type->name(), $this->type->label(), $this->type->version(), $this->type->a11yRules()]);
        self::assertSame(['source'], $this->type->schema()['required']);
        self::assertSame(['source' => 'x'], $this->type->upgradeData(1, ['source' => 'x']));
        self::assertSame(['list', 'cards'], array_map(static fn ($variant): string => $variant->name, $this->type->styles()->variants));
    }

    public function testListsThePagesUnderAPageOneLinkEachWithoutMembersOnlyOrDraftPages(): void
    {
        $data = ['source' => PageListBlock::CHILDREN, 'parent' => '/services', 'show_summaries' => true];

        self::assertSame(
            '<ul class="block-page-list">'
            . '<li class="block-page-list__item"><a class="block-page-list__link" href="/services/repairs">Repairs</a><p class="block-page-list__summary">We fix bikes.</p></li>'
            . '<li class="block-page-list__item"><a class="block-page-list__link" href="/services/lessons">Lessons</a></li>'
            . '</ul>',
            $this->type->render($data, $this->context()),
        );
        self::assertSame(
            "- [Repairs](https://demo.example/services/repairs): We fix bikes.\n- [Lessons](https://demo.example/services/lessons)",
            $this->type->renderMarkdown($data, $this->context()),
        );
        self::assertSame(['@type' => 'ItemList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Repairs', 'url' => 'https://demo.example/services/repairs'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Lessons', 'url' => 'https://demo.example/services/lessons'],
        ]], $this->type->structuredData($data, $this->context()));
        self::assertStringNotContainsString('Members area', $this->type->render($data, $this->context()));
        self::assertStringNotContainsString('<p', $this->type->render(['show_summaries' => false] + $data, $this->context()));
    }

    public function testListsTheTopLevelPagesWithoutTheHomePageOrThePageItIsOn(): void
    {
        $top = ['source' => PageListBlock::CHILDREN, 'parent' => ' '];

        self::assertSame("- [Services](https://demo.example/services)\n- [Contact](https://demo.example/contact)", $this->type->renderMarkdown($top, $this->context()));
        self::assertSame('- [Contact](https://demo.example/contact)', $this->type->renderMarkdown($top, $this->context('/services')));
        self::assertSame('- [Services](https://demo.example/services)', $this->type->renderMarkdown(['count' => 1] + $top, $this->context()));
        self::assertSame('', $this->type->render(['source' => PageListBlock::CHILDREN, 'parent' => '/nowhere'], $this->context()));
        self::assertNull($this->type->structuredData(['source' => PageListBlock::CHILDREN, 'parent' => '/nowhere'], $this->context()));
    }

    public function testListsTheLatestPages(): void
    {
        $latest = ['source' => PageListBlock::LATEST, 'count' => 2];

        self::assertSame("- [Contact](https://demo.example/contact)\n- [Lessons](https://demo.example/services/lessons)", $this->type->renderMarkdown($latest, $this->context()));
        self::assertCount(4, explode("\n", $this->type->renderMarkdown(['source' => PageListBlock::LATEST], $this->context())), 'up to the default number, ' . PageListBlock::DEFAULT_COUNT);
    }

    public function testListsNothingOnAMembersOnlySite(): void
    {
        $this->pages->database->settings()->set(SiteVisibility::SETTING, 'members');

        self::assertSame('', $this->type->render(['source' => PageListBlock::LATEST], $this->context()));
        self::assertSame('', $this->type->renderMarkdown(['source' => PageListBlock::LATEST], $this->context()));
        self::assertNull($this->type->structuredData(['source' => PageListBlock::LATEST], $this->context()));
    }

    public function testKeepsTheLanguageOfTheAddress(): void
    {
        $this->pages->translated($this->services, 'pt-PT', new PageContent('Serviços', 'servicos', 'O que fazemos.'));

        self::assertSame(
            '- [Serviços](https://demo.example/pt-pt/servicos): O que fazemos.',
            $this->type->renderMarkdown(['source' => PageListBlock::CHILDREN, 'show_summaries' => true], $this->context('/pt-pt', 'pt-PT')),
        );
        self::assertSame("- [Services](https://plain.example/services)\n- [Contact](https://plain.example/contact)", $this->type->renderMarkdown(['source' => PageListBlock::CHILDREN], new PlainBlockContext()), 'without a request nothing is left out');
    }

    private function page(string $title, ?string $summary, ?Page $parent = null): Page
    {
        $page = $this->pages->editor()->create('en-GB', new PageContent($title, null, $summary), $parent);
        $page->changeState(PageState::Published, $this->pages->clock->now());
        $this->pages->repository()->flush();

        return $page;
    }

    private function context(string $path = '/elsewhere', string $locale = 'en-GB'): BlockContext
    {
        return new BlockFixture([], $this->pages->database)->contexts($this->pages->site)->create($locale, 'https://demo.example', new ServerRequest('GET', 'https://demo.example' . $path));
    }
}
