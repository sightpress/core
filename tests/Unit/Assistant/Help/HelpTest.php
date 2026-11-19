<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant\Help;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Help\HelpIndex;
use SightPress\Core\Assistant\Help\HelpIndexBuilder;
use SightPress\Core\Assistant\InputSchema;
use SightPress\Core\Assistant\SiteAssistantContext;
use SightPress\Core\Assistant\Task\HelpTask;
use SightPress\Core\Assistant\Task\Prompts;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\TokenUsage;

/**
 * The help index (A-5): built the same way every time from the documentation's Markdown, searched by keywords, and
 * never showing a person a screen they can't open; the `help` task's answers always link to the documentation.
 */
#[CoversClass(HelpIndexBuilder::class)]
#[CoversClass(HelpIndex::class)]
#[CoversClass(HelpTask::class)]
#[UsesNamespace('SightPress\Core')]
final class HelpTest extends TestCase
{
    private TemporaryDirectory $docs;

    private string $index;

    protected function setUp(): void
    {
        $this->docs = new TemporaryDirectory();
        $this->docs->write('index.md', "# SightPress\n\nA CMS for everyone.\n");
        $this->docs->write('how-to/turn-features-on-or-off.md', <<<'MD'
            # Turn features on or off

            You need to be an **admin**. In the admin menu, choose **Features**.

            ## Turn a feature off

            1. On the *Features* screen, choose **Turn off**.

            ```bash
            # Not a heading: a comment in a command
            bin/sightpress site:module:disable demo search
            ```
            MD);
        $this->docs->write('how-to/write-and-publish-a-page.md', "# Write and publish a page\n\nOpen **Pages** and add a page.\n\n## Add the page to a menu\n\nOpen **Menus** to add the page to the main menu.\n\n### Can't see `Menus`?\n\nAsk an admin.\n");
        $this->docs->write('adr/0001-x.md', "# Decision\n\nFeatures features features.\n");
        $this->docs->write('notes.txt', 'Not Markdown.');
        $this->index = $this->docs->write('help-index.json', HelpIndexBuilder::json(new HelpIndexBuilder()->build(dirname($this->docs->write('index.md', "# SightPress\n\nA CMS for everyone.\n")))));
    }

    protected function tearDown(): void
    {
        $this->docs->remove();
    }

    public function testBuildsTheSameIndexEveryTime(): void
    {
        $root = dirname($this->index);
        $first = HelpIndexBuilder::json(new HelpIndexBuilder()->build($root));
        self::assertSame($first, HelpIndexBuilder::json(new HelpIndexBuilder()->build($root . '/')));
        $chunks = new HelpIndexBuilder()->build($root);
        self::assertSame(
            [
                ['Turn features on or off', '', 'https://docs.sightpress.org/latest/how-to/turn-features-on-or-off/', ['module.manage']],
                ['Turn features on or off', 'Turn a feature off', 'https://docs.sightpress.org/latest/how-to/turn-features-on-or-off/#turn-a-feature-off', ['module.manage', 'settings.manage']],
                ['Write and publish a page', '', 'https://docs.sightpress.org/latest/how-to/write-and-publish-a-page/', []],
                ['Write and publish a page', 'Add the page to a menu', 'https://docs.sightpress.org/latest/how-to/write-and-publish-a-page/#add-the-page-to-a-menu', ['menu.manage']],
                ['Write and publish a page', "Can't see `Menus`?", 'https://docs.sightpress.org/latest/how-to/write-and-publish-a-page/#cant-see-menus', []],
                ['SightPress', '', 'https://docs.sightpress.org/latest/', []],
            ],
            array_map(static fn (array $chunk): array => [$chunk['page'], $chunk['heading'], $chunk['url'], $chunk['needs']], $chunks),
            'sorted by file; adr/ and other files left out; a # in a code block is no heading',
        );
        self::assertStringContainsString('# Not a heading: a comment in a command', $chunks[1]['text']);
        self::assertSame(12, strlen($chunks[0]['id']));
        $untitled = new HelpIndexBuilder()->page('untitled.md', "No title.

## Empty
");
        self::assertSame([['untitled.md', '', 'https://docs.sightpress.org/latest/untitled/', 'No title.']], array_map(static fn (array $chunk): array => [$chunk['page'], $chunk['heading'], $chunk['url'], $chunk['text']], $untitled), 'no title: the file; empty sections left out');
        self::assertSame('a-b-c-d', HelpIndexBuilder::anchor('A *b* — c & `d`'));
    }

    public function testSearchesByKeywordsAndLeavesOutWhatThePersonCantUse(): void
    {
        $index = new HelpIndex($this->index);
        $all = static fn (string $permission): bool => true;
        $contributor = new Actor(3, ['admin.access', 'page.create', 'page.edit.own'])->has(...);

        self::assertSame('Turn a feature off', $index->search('How do I turn off a feature?', $all)[0]['heading'] ?? null);
        self::assertSame([], array_filter($index->search('How do I turn off a feature?', $contributor), static fn (array $chunk): bool => str_contains($chunk['url'], 'features')), 'a contributor is never pointed at the Features screen');
        self::assertSame('Add the page to a menu', $index->search('add my page to the main menu', $all)[0]['heading'] ?? null);
        self::assertSame([], $index->search('the of and', $all));
        self::assertSame([], $index->search('zebra', $all));
        self::assertCount(1, $index->search('page', $all, 1));
        self::assertSame(['page', 'menu', 'pass', 'it2'], HelpIndex::words('Pages, menus, PASS, it2 and a b'));
        self::assertSame([], new HelpIndex('/no/such/file.json')->chunks());
        self::assertSame([], new HelpIndex($this->docs->write('bad.json', '{"chunks": [3, {"url": 1}]}'))->chunks());
        self::assertSame([['id' => '', 'page' => '', 'heading' => '', 'url' => 'https://x', 'text' => 't', 'needs' => ['a']]], new HelpIndex($this->docs->write('lean.json', '{"chunks": [{"url": "https://x", "text": "t", "needs": ["a", 3]}]}'))->chunks());
    }

    public function testTheHelpTaskAnswersFromTheDocumentationWithALink(): void
    {
        $task = new HelpTask(new Prompts(), new HelpIndex($this->index));
        $editor = new SiteAssistantContext('en-GB', new Actor(3, ['admin.access', 'page.edit.any', 'menu.manage']), 'page_editor');
        $input = ['question' => 'How do I add my page to the main menu?', 'permissions' => ['admin.access', 'menu.manage', 'module.manage']];
        self::assertSame([], InputSchema::problems($task->inputSchema(), $input));
        self::assertSame(['help', 'Help with SightPress', 'assistant.use', false], [$task->id(), $task->label(), $task->permission(), $task->needsVision()]);

        [$system, $user] = $task->buildPrompt($input, $editor);
        self::assertStringContainsString('The person is on the screen "page_editor". They may: admin.access, menu.manage.', $system->text, 'only permissions they really have');
        self::assertStringContainsString("<data name=\"documentation\">\nWrite and publish a page — Add the page to a menu\nhttps://docs.sightpress.org/latest/how-to/write-and-publish-a-page/#add-the-page-to-a-menu", $user->text);
        self::assertStringContainsString("<data name=\"question\">\nHow do I add my page to the main menu?\n</data>", $user->text);
        $nothing = $task->buildPrompt(['question' => 'zebra'], new SiteAssistantContext('en-GB'));
        self::assertStringContainsString('Nothing in the documentation matches this question.', $nothing[1]->text);
        self::assertStringContainsString('The person is on the screen "admin". They may: use the admin panel.', $nothing[0]->text);

        $linked = $task->validateOutput(new AssistantResponse('Open Menus. https://docs.sightpress.org/latest/how-to/write-and-publish-a-page/', 'm', new TokenUsage()), $input, $editor);
        self::assertSame('Open Menus. https://docs.sightpress.org/latest/how-to/write-and-publish-a-page/', $linked->text);
        $unlinked = $task->validateOutput(new AssistantResponse('Open Menus.', 'm', new TokenUsage()), $input, $editor);
        self::assertSame("Open Menus.\n\nhttps://docs.sightpress.org/latest/how-to/write-and-publish-a-page/#add-the-page-to-a-menu", $unlinked->text, 'the best part’s link is added');
        self::assertSame("I don’t know.\n\nhttps://docs.sightpress.org/latest/", $task->validateOutput(new AssistantResponse('I don’t know.', 'm', new TokenUsage()), ['question' => 'zebra'], $editor)->text);
    }
}
