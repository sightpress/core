<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Page;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Contract\PublishIssue;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\Publish\PublishChecker;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Page\Workflow\PublishBlocked;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tests\Support\KernelSite;

/**
 * The publish checks through a real site container (theme colours, block types, media library): a page with
 * accessibility errors can't be published; once they are fixed it can, with search engine suggestions left.
 */
#[CoversNothing]
final class PublishChecksTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('publish');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testErrorsStopPublishingUntilTheyAreFixed(): void
    {
        $container = $this->site->container();
        $editor = Services::get($container, PageEditor::class);
        $workflow = Services::get($container, PageWorkflow::class);
        $actor = new Actor(1, ['page.edit.any', 'page.submit', 'page.publish']);
        $page = $editor->create('en-GB', new PageContent('Events', null, null, null, [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'This month', 'level' => 3]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p><span style="color: #0a3d91">Every Monday</span></p>']],
        ]));
        $workflow->apply($page, PageTransition::Submit, $actor);
        $workflow->apply($page, PageTransition::Approve, $actor);

        try {
            $workflow->apply($page, PageTransition::Publish, $actor);
            self::fail('Expected publishing to be blocked.');
        } catch (PublishBlocked $blocked) {
            self::assertSame(['page.heading_skipped', 'page.contrast'], array_map(static fn (PublishIssue $issue): string => $issue->rule, $blocked->report->errors()));
            self::assertStringContainsString('in dark mode', (string) $blocked->report->errors()[1]->message);
        }

        $editor->save($page, 'en-GB', new PageContent('Events', null, null, null, [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'This month', 'level' => 2]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p><span style="color: #1a1a1f; background-color: #fff2a8">Every Monday</span></p>']],
        ]));
        self::assertSame(['seo.description_missing', 'seo.summary_missing'], array_map(static fn (PublishIssue $issue): string => $issue->rule, Services::get($container, PublishChecker::class)->check($page)->warnings()));
        $workflow->apply($page, PageTransition::Publish, $actor);
        self::assertSame(PageState::Published, $page->state());
    }
}
