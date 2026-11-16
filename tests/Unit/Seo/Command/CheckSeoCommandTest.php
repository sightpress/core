<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Seo\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Seo\Command\CheckSeoCommand;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;

#[CoversClass(CheckSeoCommand::class)]
#[UsesNamespace('SightPress\Core')]
final class CheckSeoCommandTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('seo_check', environment: 'production');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testReportsLongTitlesAndDescriptionsAndPassesWithoutProblems(): void
    {
        $editor = Services::get($this->site->container(), PageEditor::class);
        $editor->create('en-GB', new PageContent('Home', 'home', 'The library.'))->changeState(PageState::Published, new DateTimeImmutable());
        $editor->create('en-GB', new PageContent(str_repeat('Very long title ', 4), 'long', null, str_repeat('word ', 40)))->changeState(PageState::Published, new DateTimeImmutable());
        Services::get($this->site->container(), PageRepository::class)->flush();
        $command = $this->command();

        [$status, $output] = $this->execute($command, 'demo');

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([
            'Warning: /long: the title for search engines has 70 characters; they show about 60.',
            'Warning: /long: the description has 199 characters and is cut at 160.',
            '2 pages in the sitemap; no problems found.',
        ], $output->lines);
        self::assertSame(['site:seo:check', 1], [$command->name(), count($command->parameters())]);
        self::assertNotSame('', $command->description());
    }

    public function testCountsProblemsAndRefusesUnknownSites(): void
    {
        $editor = Services::get($this->site->container(), PageEditor::class);
        foreach (['twin-a', 'twin-b', 'twin-c', 'twin-d'] as $slug) {
            $editor->create('en-GB', new PageContent(in_array($slug, ['twin-a', 'twin-b'], true) ? 'Twins' : 'Others', $slug, 'Same.'))->changeState(PageState::Published, new DateTimeImmutable());
        }
        Services::get($this->site->container(), PageRepository::class)->flush();

        [$status, $output] = $this->execute($this->command(), 'demo');
        self::assertSame(Command::FAILURE, $status);
        self::assertCount(2, $output->errors);
        self::assertSame('4 pages in the sitemap; 2 problems to fix.', end($output->lines));

        [$status, $output] = $this->execute($this->command(), 'shop');
        self::assertSame([Command::FAILURE, ['There is no site "shop". See the sites with site:list.']], [$status, $output->errors]);
    }

    public function testNamesWhatIsLeftOutAndAnInstallThatIsNotInProduction(): void
    {
        $this->site->remove();
        $this->site = new KernelSite('seo_check_test');
        $editor = Services::get($this->site->container(), PageEditor::class);
        $now = new DateTimeImmutable();
        $editor->create('en-GB', new PageContent('Home'))->changeState(PageState::Published, $now);
        $editor->create('en-GB', new PageContent('Hidden', 'hidden', 'x', null, [], null, null, true))->changeState(PageState::Published, $now);
        $editor->create('en-GB', new PageContent('Copy', 'copy', 'x', null, [], null, null, false, 'https://example.org/original'))->changeState(PageState::Published, $now);
        $members = $editor->create('en-GB', new PageContent('Members', 'members', 'x'));
        $members->changeState(PageState::Published, $now);
        $members->setPublic(false, $now);
        Services::get($this->site->container(), PageRepository::class)->flush();

        self::assertSame([
            'Note: this install is not in production (APP_ENV), so every page asks search engines not to index it.',
            'Warning: / has no description or summary; search engines get its first words.',
            'Left out of search: /hidden (noindex).',
            'Left out of search: /copy (a copy of https://example.org/original).',
            'Left out of search: /members (members only).',
            '1 page in the sitemap; no problems found.',
        ], $this->execute($this->command(), 'demo')[1]->lines);
    }

    public function testOnePage(): void
    {
        Services::get($this->site->container(), PageEditor::class)->create('en-GB', new PageContent('Home', 'home', 'Hi.'))->changeState(PageState::Published, new DateTimeImmutable());
        Services::get($this->site->container(), PageRepository::class)->flush();

        self::assertSame(['1 page in the sitemap; no problems found.'], $this->execute($this->command(), 'demo')[1]->lines);
    }

    private function command(): CheckSeoCommand
    {
        $tenant = $this->site->kernel->container();

        return new CheckSeoCommand(Services::get($tenant, SiteRepository::class), Services::get($tenant, SiteContainerFactory::class));
    }

    /**
     * @return array{int, RecordingOutput}
     */
    private function execute(Command $command, string $slug): array
    {
        $input = new class ($slug) implements CommandInput {
            public function __construct(private string $slug)
            {
            }

            public function argument(string $name): string
            {
                return $this->slug;
            }

            public function option(string $name): ?string
            {
                return null;
            }

            public function flag(string $name): bool
            {
                return false;
            }
        };
        $output = new RecordingOutput();

        return [$command->run($input, $output), $output];
    }
}
