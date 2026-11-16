<?php

/**
 * A real site for the accessibility tests of the content blocks: the whole kernel with core's modules, one site on a
 * SQLite file (domain 127.0.0.1) and a published page, `/visit`, with every core block type, rendered in the default
 * theme's layout (a stand-in for the public page router, C-6.2). The picture, captions and document are uploaded
 * through the media library; the video and audio entries have no file behind them, which doesn't matter to axe.
 * Started by playwright.config.ts with PHP's built-in web server; never deployed.
 *
 * `/_test/reset` starts again from an empty site (forgets sessions and rate limits).
 */

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Block\Contract\BlockContexts;
use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Kernel;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Page\Contract\PublicPages;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const SECRET = 'a11y-secret-0123456789abcdef0123456789abcdef';

/**
 * Renders the published page at the request's path in the default layout.
 */
final readonly class A11yBlockPage implements RequestHandlerInterface
{
    public function __construct(
        private PublicPages $pages,
        private BlockContexts $contexts,
        private BlockRenderer $renderer,
        private TemplateRenderer $templates,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->pages->findByUrl($request->getUri()->getPath()) ?? throw new LogicException('No page.');
        $context = $this->contexts->create($page->locale, 'http://' . $request->getUri()->getAuthority(), $request);

        return $this->templates->response($request, '@a11yblocks/page.html.twig', [
            'title' => $page->title,
            'site_name' => 'Riverside Library',
            'content' => $this->renderer->html($page->blocks, $context),
        ])->withHeader('Cache-Control', 'no-store');
    }
}

$project = sys_get_temp_dir() . '/sightpress-a11y-blocks';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
$environment = ['APP_ENV' => 'test', 'APP_SECRET' => SECRET];

if ($path === '/_test/reset') {
    exec('rm -rf ' . escapeshellarg($project));
    http_response_code(204);

    return;
}

$module = new FakeModule('acme.a11y_blocks', register: static function (ModuleRegistrar $r): void {
    $r->views()->add('a11yblocks', __DIR__ . '/templates');
    $r->services()->autowire(A11yBlockPage::class);
    $r->routes()->add(new Route('acme.visit', '/visit', A11yBlockPage::class));
});
$kernel = static fn (): Kernel => Kernel::fromConfig(
    new TenantConfigLoader()->load($project, $environment),
    [...CoreModules::candidates(), new ModuleCandidate($module, 'acme/a11y-blocks')],
);

if (!is_file($project . '/config/sites.php')) {
    @mkdir($project . '/config', 0o777, true);
    file_put_contents($project . '/config/tenant.php', '<?php return ' . var_export([
        'log' => ['path' => $project . '/var/log/test.log'],
        'session' => ['path' => $project . '/var/sessions'],
    ], true) . ';');
    file_put_contents($project . '/config/sites.php', '<?php return ' . var_export(['version' => 1, 'sites' => ['demo' => [
        'id' => '3f0c2b9e-5d7a-4c1e-8b2f-0a6d9e4c7b1b',
        'name' => 'Riverside Library',
        'status' => 'enabled',
        'owner_type' => 'organization',
        'domains' => ['127.0.0.1'],
        'db_dsn' => new SecretBox(SECRET)->encrypt('sqlite:///' . $project . '/demo.sqlite'),
        'storage' => ['adapter' => 'local', 'path' => $project . '/storage'],
        'default_locale' => 'en-GB',
        'locales' => ['en-GB'],
        'default_timezone' => 'Europe/London',
        'created_at' => '2026-11-15T09:00:00Z',
    ]]], true) . ';');

    $site = site($kernel());
    $dependencies = DependencyFactory::fromConnection(
        new ExistingConfiguration(new MigrationConfigurationFactory()->create($kernel()->modules())),
        new ExistingConnection(Services::get($site, Connection::class)),
    );
    $dependencies->getMetadataStorage()->ensureInitialized();
    $dependencies->getMigrator()->migrate(
        $dependencies->getMigrationPlanCalculator()->getPlanUntilVersion(
            $dependencies->getVersionAliasResolver()->resolveVersionAlias('latest'),
        ),
        new MigratorConfiguration(),
    );
    seed($site, $project);
}

$kernel()->run();

function site(Kernel $kernel): ContainerInterface
{
    $site = Services::get($kernel->container(), SiteRepository::class)->find('demo') ?? throw new LogicException('No site.');

    return Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site);
}

function seed(ContainerInterface $site, string $project): void
{
    $now = new DateTimeImmutable();
    $uploader = Services::get($site, MediaUploader::class);
    $media = Services::get($site, MediaRepository::class);
    $upload = static function (string $name, string $contents) use ($uploader, $media, $project, $now): int {
        $file = $project . '/' . $name;
        file_put_contents($file, $contents);
        $item = $uploader->upload($file, $name, null)->media;
        $item->publish($now);
        $media->save($item);

        return (int) $item->id();
    };
    $entry = static function (MediaFormat $format, string $name) use ($media, $now): int {
        $item = new Media(bin2hex(random_bytes(16)), hash('sha256', $name), $format, $name, $name, 4_200_000, null, null, [], null, $now);
        $item->publish($now);
        $media->save($item);

        return (int) $item->id();
    };
    $picture = $upload('harbour.jpg', MediaFixture::jpeg(1200, 800));
    $captions = $upload('tour.vtt', "WEBVTT\n\n00:00.000 --> 00:03.000\nWelcome to the library.\n");
    $report = $upload('annual-report.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    $video = $entry(new MediaFormat('video/mp4', MediaKind::Video, 'mp4', 'MP4'), 'tour.mp4');
    $audio = $entry(new MediaFormat('audio/mpeg', MediaKind::Audio, 'mp3', 'MP3'), 'talk.mp3');

    $editor = Services::get($site, PageEditor::class);
    $page = $editor->create('en-GB', new PageContent('Visit the library', 'visit', null, null, [
        ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>We are open every day. Find out <a href="/about">who we are</a> and <strong>what we do</strong>.</p><ul><li>Books</li><li>Music</li></ul>']],
        ['type' => 'call_to_action', 'version' => 1, 'data' => ['text' => 'Book a visit', 'url' => '/book']],
        ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Around the library', 'level' => 2]],
        ['type' => 'image', 'version' => 1, 'data' => ['media' => $picture, 'alt' => 'The harbour seen from the reading room', 'caption' => 'The view from the first floor']],
        ['type' => 'gallery', 'version' => 1, 'data' => ['images' => [
            ['media' => $picture, 'alt' => 'Boats in the harbour', 'caption' => 'Morning'],
            ['media' => $picture, 'decorative' => true],
        ], 'caption' => 'The harbour through the day']],
        ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [
            ['blocks' => [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'A tour', 'level' => 3]], ['type' => 'video', 'version' => 1, 'data' => ['media' => $video, 'title' => 'A tour of the library', 'captions' => $captions, 'transcript' => "Welcome to the library.\n\nThe reading room is on the first floor."]]]],
            ['blocks' => [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'A talk', 'level' => 3]], ['type' => 'audio', 'version' => 1, 'data' => ['media' => $audio, 'title' => 'Opening talk', 'transcript' => 'Good evening, and welcome.']]]],
        ]]],
        ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Documents and contact', 'level' => 2]],
        ['type' => 'file_download', 'version' => 1, 'data' => ['media' => $report, 'text' => 'Annual report 2025']],
        ['type' => 'contact_form', 'version' => 1, 'data' => ['recipient' => 'office@example.org']],
    ]));
    $page->changeState(PageState::Published, $now);
    Services::get($site, PageRepository::class)->flush();
}
