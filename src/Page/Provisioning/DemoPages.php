<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Provisioning;

use Psr\Clock\ClockInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Site\Contract\SiteProvisioning;

/**
 * Demo content, step 3 of 4: a published page with one block of every kind core has, using the demo media files,
 * and a published page under it written by the demo contributor. Shares `demo.page` and `demo.subpage`.
 */
final readonly class DemoPages
{
    public const int PRIORITY = SiteProvisioning::DEMO - 20;

    public function __invoke(SiteProvisioning $event): void
    {
        if (!$event->demo) {
            return;
        }
        $editor = Services::get($event->services, PageEditor::class);
        $now = Services::get($event->services, ClockInterface::class)->now();
        $locale = $event->site->defaultLocale;
        $media = static fn (string $name): int => $event->shared('demo.' . $name) ?? throw new \LogicException(sprintf('The demo %s is missing.', $name));
        $text = static fn (string $html): array => ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => $html]];
        $heading = static fn (string $title): array => ['type' => 'heading', 'version' => 1, 'data' => ['text' => $title, 'level' => 2]];

        $page = $editor->create($locale, new PageContent(
            'Every kind of block',
            'every-block',
            'A demo page with one block of every kind, to try the site out.',
            'A demo page that shows every kind of block: text, pictures, a gallery, a video with captions, a recording with its transcript, a file and a contact form.',
            [
                $heading('Text'),
                $text('<p>A paragraph with <strong>strong</strong> and <em>emphasised</em> words, and <a href="/">a link to the home page</a>.</p><ul><li>A list</li><li>with two items</li></ul>'),
                $heading('A picture'),
                ['type' => 'image', 'version' => 1, 'data' => ['media' => $media('picture'), 'alt' => 'A test pattern: coloured bars, a gradient and circles.', 'caption' => 'The demo picture.']],
                $heading('A button'),
                ['type' => 'call_to_action', 'version' => 1, 'data' => ['text' => 'Go to the home page', 'url' => '/', 'style' => 'primary']],
                $heading('Two columns'),
                ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [
                    ['blocks' => [$text('<p>The first column.</p>')]],
                    ['blocks' => [$text('<p>The second column.</p>')]],
                ]]],
                $heading('A gallery'),
                ['type' => 'gallery', 'version' => 1, 'data' => ['images' => [
                    ['media' => $media('picture'), 'alt' => 'The test pattern again.'],
                    ['media' => $media('picture'), 'decorative' => true],
                ], 'caption' => 'A gallery of two pictures.']],
                $heading('A video'),
                ['type' => 'video', 'version' => 1, 'data' => ['media' => $media('video'), 'title' => 'Test pattern with a tone', 'captions' => $media('captions')]],
                $heading('A recording'),
                ['type' => 'audio', 'version' => 1, 'data' => ['media' => $media('audio'), 'title' => 'A tone', 'transcript' => 'A single tone plays for two seconds. Nobody speaks.']],
                $heading('A file'),
                ['type' => 'file_download', 'version' => 1, 'data' => ['media' => $media('document'), 'text' => 'Opening hours']],
                $heading('A contact form'),
                ['type' => 'contact_form', 'version' => 1, 'data' => ['recipient' => $event->admin->email, 'subject' => 'Message from the demo page']],
            ],
        ), null, $event->adminId());
        $page->changeState(PageState::Published, $now);
        $subpage = $editor->create($locale, new PageContent(
            'A page under it',
            'under',
            'A demo page that sits under the page with every kind of block.',
            'A demo page that sits under the page with every kind of block.',
            [$text('<p>Pages can sit under other pages; their addresses follow.</p>')],
        ), $page, $event->shared('demo.contributor'));
        $subpage->changeState(PageState::Published, $now);
        Services::get($event->services, PageRepository::class)->flush();
        $event->share('demo.page', (int) $page->id());
        $event->share('demo.subpage', (int) $subpage->id());
        $event->report('Added the demo page "Every kind of block" (/every-block) and a page under it.');
    }
}
