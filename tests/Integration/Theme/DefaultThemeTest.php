<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Theme;

use Dom\HTMLDocument;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\CurrentRequest;
use SightPress\Core\Http\Security\CspNonce;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Template\AssetUrls;
use SightPress\Core\Template\DateFormatter;
use SightPress\Core\Template\TemplateExtension;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\TwigFactory;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Tests\Support\TemporaryDirectory;

/**
 * The default theme's master template, layouts and partials, rendered for real: landmarks, skip link, language and
 * direction, names of icons and controls, and escaping.
 */
#[CoversNothing]
final class DefaultThemeTest extends TestCase
{
    private TemporaryDirectory $directory;

    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->directory->write('templates/everything.html.twig', <<<'TWIG'
            {% extends 'layouts/' ~ layout ~ '.html.twig' %}
            {% import 'partials/buttons.html.twig' as buttons %}
            {% import 'partials/alerts.html.twig' as alerts %}
            {% import 'partials/cards.html.twig' as cards %}
            {% import 'partials/tables.html.twig' as tables %}
            {% import 'partials/pagination.html.twig' as pagination %}
            {% import 'partials/disclosure.html.twig' as disclosure %}
            {% import 'partials/dialog.html.twig' as dialog %}
            {% import 'partials/icons.html.twig' as icons %}
            {% block content %}
            {{ buttons.button('Save', {type: 'submit', name: 'do', value: 'save'}) }}
            {{ buttons.link('Read the guide', '/guide', {variant: 'secondary'}) }}
            {{ alerts.alert('error', 'Page not saved', 'Fix the title.') }}
            {{ alerts.alert('success', 'Saved', null, {live: true}) }}
            {{ alerts.alert('info', 'Note', null, {live: true}) }}
            <ul class="cards"><li>{{ cards.card({title: 'Hours <b>', href: '/hours', text: 'When we are open.', level: 3}) }}</li></ul>
            {{ tables.table('Opening hours', ['Day', 'Opens'], [['Monday', '9:00']], {row_headers: true, id: 'hours'}) }}
            {{ pagination.nav(5, 10, '/news?page=%d', 'News pages') }}
            {{ pagination.nav(1, 1, '/x?page=%d', 'Never shown') }}
            {% set answer %}<p>Thirty days.</p>{% endset %}
            {{ disclosure.details('What happens to my data?', answer) }}
            {{ dialog.opener('help', 'Help') }}
            {{ dialog.dialog('help', 'Choosing a layout', answer) }}
            {{ icons.icon('external', 'opens in a new tab') }}
            {% endblock %}
            TWIG);

        $factory = new Psr17Factory();
        $current = new CurrentRequest();
        $twig = new TwigFactory()->create(
            new TemplatePaths([$this->directory->path, TemplatePaths::defaultTheme()], TemplatePaths::core()),
            ModuleRegistry::register([]),
            new TemplateExtension($current, new AssetUrls('/assets/', '7'), Translations::fromDirectories([]), new DateFormatter(), new Viewer('en-GB', 'UTC')),
            $this->directory->path . '/cache',
            true,
        );
        $this->renderer = new TemplateRenderer($twig, $current, $factory, $factory);
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testTheMasterTemplateHasTheSkipLinkLandmarksAndLanguage(): void
    {
        $doc = $this->render('default', [
            'site_name' => 'Riverside Library',
            'title' => 'Opening hours',
            'lead' => 'When to visit.',
            'main_menu' => [['label' => 'Home', 'url' => '/'], ['label' => 'Hours', 'url' => '/hours', 'current' => true]],
            'footer_menu' => [['label' => 'Privacy', 'url' => '/privacy']],
            'messages' => ['Saved.'],
        ]);

        self::assertSame('en-GB', $doc->documentElement?->getAttribute('lang'));
        self::assertSame('ltr', $doc->documentElement->getAttribute('dir'));
        self::assertSame('Opening hours - Riverside Library', $doc->title);
        $first = $doc->body?->firstElementChild;
        self::assertSame('A', $first?->tagName);
        self::assertSame('#content', $first->getAttribute('href'));
        self::assertSame('Skip to main content', $first->textContent);
        self::assertCount(1, $doc->querySelectorAll('body > header'));
        self::assertCount(1, $doc->querySelectorAll('main#content'));
        self::assertCount(1, $doc->querySelectorAll('body > footer'));
        self::assertCount(1, $doc->querySelectorAll('h1'));
        self::assertSame('Main', $doc->querySelector('header nav')?->getAttribute('aria-label'));
        self::assertSame('Footer', $doc->querySelector('footer nav')?->getAttribute('aria-label'));
        self::assertSame('Hours', $doc->querySelector('[aria-current="page"]')?->textContent);
        self::assertSame('status', $doc->querySelector('main > .messages')?->getAttribute('role'));
        self::assertSame('Saved.', trim((string) $doc->querySelector('.messages')?->textContent));
        self::assertSame('/assets/tokens.css?v=7', $doc->querySelector('link[rel=stylesheet]')?->getAttribute('href'));
        self::assertSame('/assets/js/site.js?v=7', $doc->querySelector('script[defer]')?->getAttribute('src'));
    }

    public function testWithoutMenusThereAreNoEmptyNavigationLandmarks(): void
    {
        $doc = $this->render('home', ['title' => 'Welcome', 'locale' => 'ar']);

        self::assertSame('ar', $doc->documentElement?->getAttribute('lang'));
        self::assertSame('rtl', $doc->documentElement->getAttribute('dir'));
        self::assertSame('Welcome - SightPress', $doc->title);
        self::assertSame('Welcome', $doc->querySelector('.hero h1')?->textContent);
        self::assertNull($doc->querySelector('header nav, footer nav'));
        self::assertNull($doc->querySelector('.lead'));
    }

    public function testPartialsHaveNamesAndSayTheirMeaningInWords(): void
    {
        $doc = $this->render('default', ['title' => 'Everything']);

        $button = $doc->querySelector('button[name=do]');
        self::assertSame('submit', $button?->getAttribute('type'));
        self::assertSame('save', $button->getAttribute('value'));
        self::assertSame('/guide', $doc->querySelector('a.button--secondary')?->getAttribute('href'));

        $error = $doc->querySelector('.alert--error');
        self::assertFalse($error?->hasAttribute('role'), 'alerts on the page at load are not live regions');
        self::assertSame('Error: Page not saved', trim((string) $error->querySelector('.alert__title')?->textContent));
        self::assertSame('status', $doc->querySelector('.alert--success')?->getAttribute('role'));
        self::assertSame('true', $doc->querySelector('.alert--error svg')?->getAttribute('aria-hidden'));

        self::assertSame('Hours <b>', $doc->querySelector('.card h3 a')?->textContent, 'escaped');

        $region = $doc->querySelector('.table-wrap');
        self::assertSame('region', $region?->getAttribute('role'));
        self::assertSame('0', $region->getAttribute('tabindex'));
        self::assertSame('Opening hours', $doc->getElementById((string) $region->getAttribute('aria-labelledby'))?->textContent);
        self::assertSame('row', $doc->querySelector('tbody th')?->getAttribute('scope'));
        self::assertSame('col', $doc->querySelector('thead th')?->getAttribute('scope'));

        self::assertCount(1, $doc->querySelectorAll('nav.pagination'));
        $pages = $doc->querySelector('nav.pagination');
        self::assertSame('News pages', $pages?->getAttribute('aria-label'));
        $links = array_map(static fn ($a) => trim(preg_replace('/\s+/', ' ', (string) $a->textContent) ?? ''), iterator_to_array($pages->querySelectorAll('a')));
        self::assertSame(['Previous page', 'Page 1', 'Page 4', 'Page 5', 'Page 6', 'Page 10', 'Next page'], $links);
        self::assertSame('/news?page=5', $pages->querySelector('[aria-current=page]')?->getAttribute('href'));
        self::assertCount(2, $pages->querySelectorAll('li[aria-hidden=true]'));

        self::assertSame('What happens to my data?', $doc->querySelector('details > summary')?->textContent);
        self::assertSame('Thirty days.', $doc->querySelector('details p')?->textContent, 'captured HTML is not escaped twice');

        $opener = $doc->querySelector('button[commandfor=help]');
        self::assertSame('show-modal', $opener?->getAttribute('command'));
        $dialog = $doc->getElementById('help');
        self::assertSame('DIALOG', $dialog?->tagName);
        self::assertSame('Choosing a layout', $doc->getElementById((string) $dialog->getAttribute('aria-labelledby'))?->textContent);
        self::assertSame('close', $dialog->querySelector('button[autofocus]')?->getAttribute('command'));

        $icon = $doc->querySelector('svg[role=img]');
        self::assertSame('opens in a new tab', $icon?->getAttribute('aria-label'));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $layout, array $context): HTMLDocument
    {
        $request = new ServerRequest('GET', '/')->withAttribute(CspNonce::class, new CspNonce('n'));
        $html = $this->renderer->render($request, 'everything.html.twig', ['layout' => $layout] + $context);

        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }
}
