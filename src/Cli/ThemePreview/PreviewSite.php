<?php

declare(strict_types=1);

namespace SightPress\Core\Cli\ThemePreview;

use DateTimeImmutable;
use DI\Container;
use Nyholm\Psr7\ServerRequest;
use Psr\Clock\ClockInterface;
use SightPress\Core\Infrastructure\Clock\FrozenClock;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Media\Admin\Provisioning\DemoMedia;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Theme\Preview\PreviewContent;
use SightPress\Core\Theme\Preview\PreviewTheme;
use SightPress\Core\Theme\Preview\StaticPage;
use SightPress\Core\Theme\Preview\ThemePreviewModule;
use SightPress\Core\Theme\ThemeException;
use SightPress\Core\User\UserRepository;
use SightPress\Theme\Preview\PreviewPage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Renders the preview page through the real kernel: a throwaway tenant in a temporary folder with one site (SQLite)
 * named after the made-up site of the page, its starter profile, core's sample media, and the page as its home page
 * in the `home` layout, with the theme applied. The navbar holds one link per section and the footer menu is empty.
 * Then it asks the site for the page and every file the page uses, as a browser would, and returns them ready for a
 * static folder ({@see StaticPage}). The clock is frozen, so every run gives the same bytes.
 */
final readonly class PreviewSite
{
    /** The preview site's address; `.invalid` never resolves, so nothing can leave the folder for it. */
    public const string URL = 'https://preview.invalid';

    /** The time the preview site lives at. */
    public const string NOW = '2026-01-01T09:00:00Z';

    private const string SECRET = 'theme-preview-secret-not-for-real-sites-0123456789';

    private const string ADMIN = 'preview@example.org';

    /**
     * @param string           $workspace an empty folder the throwaway tenant lives in
     * @param string|null      $fallback  the theme folder missing templates come from (default: the default theme)
     */
    public function __construct(private string $workspace, private ?string $fallback = null)
    {
    }

    /**
     * @return array<string, string> file contents by site address (`/` is the page, already static)
     *
     * @throws ThemeException when the throwaway site can't be made or the page can't be rendered
     */
    public function render(PreviewTheme $theme, string $locale): array
    {
        $roles = array_fill_keys(PreviewPage::MEDIA, 0);
        $kernel = $this->kernel(PreviewContent::build($theme, $roles)->button, $theme);
        $this->createSite($kernel, PreviewContent::build($theme, $roles)->siteName, $locale);

        $site = Services::get($kernel->container(), SiteRepository::class)->find('preview') ?? throw new ThemeException('The preview site was not created.');
        $services = Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site);
        $admin = Services::get($services, UserRepository::class)->findByEmail(self::ADMIN)?->id();
        $content = PreviewContent::build($theme, new DemoMedia()->add($services, $locale, $admin), null);

        $home = Services::get($services, PageAddresses::class)->home() ?? throw new ThemeException('The preview site has no home page.');
        Services::get($services, PageEditor::class)->save($home, $locale, new PageContent(
            $theme->name,
            $home->translation($locale)?->slug(),
            $theme->description,
            $theme->description,
            $content->blocks,
        ), $admin);
        Services::get($services, PageRepository::class)->flush();

        $menus = Services::get($services, MenuEditor::class);
        foreach ([MenuName::Main, MenuName::Footer] as $menu) {
            foreach (array_reverse($menus->items($menu)) as $item) {
                $menus->remove($item, $admin);
            }
        }
        foreach ($content->sections as $section) {
            $menus->add(MenuName::Main, null, $section['heading'], '/#' . $section['id'], $admin);
        }

        $html = $this->get($kernel, '/');
        $static = new StaticPage(self::URL);
        $files = [];
        $queue = array_values(array_filter($static->files($html), static fn (string $path): bool => $path !== '/site.webmanifest'));
        while ($queue !== []) {
            $path = array_shift($queue);
            if (!isset($files[$path])) {
                $files[$path] = $this->get($kernel, $path);
                if (str_ends_with($path, '.css')) {
                    array_push($queue, ...$static->cssFiles($files[$path], $path));
                    $files[$path] = $static->rewriteCss($files[$path], $path);
                }
            }
        }

        return $static->stabilise(['/' => $static->rewrite($html, array_keys($files), $content->sections)] + $files);
    }

    /**
     * @param array{label: string, accessible_name: string, url: string} $button
     */
    private function kernel(array $button, PreviewTheme $theme): Kernel
    {
        @mkdir($this->workspace . '/config', 0o777, true);
        file_put_contents($this->workspace . '/config/tenant.php', '<?php return ' . var_export([
            'log' => ['path' => $this->workspace . '/var/log/preview.log'],
            'session' => ['path' => $this->workspace . '/var/sessions'],
        ], true) . ';');
        file_put_contents($this->workspace . '/config/sites.php', '<?php return ' . var_export(['version' => 1, 'sites' => []], true) . ';');

        $config = new TenantConfigLoader()->load($this->workspace, ['APP_ENV' => 'production', 'APP_SECRET' => self::SECRET]);
        $kernel = Kernel::fromConfig($config, [
            ...CoreModules::candidates(),
            new ModuleCandidate(new ThemePreviewModule($button), 'sightpress/core'),
        ]);
        $container = $kernel->container() instanceof Container ? $kernel->container() : throw new ThemeException('The preview can\'t apply the theme: the kernel\'s container can\'t be changed.');
        $themes = array_values(array_unique([$theme->directory, $this->fallback ?? TemplatePaths::defaultTheme()]));
        $container->set(TemplatePaths::class, new TemplatePaths($themes, TemplatePaths::core()));
        $container->set(ClockInterface::class, new FrozenClock(new DateTimeImmutable(self::NOW)));

        return $kernel;
    }

    private function createSite(Kernel $kernel, string $name, string $locale): void
    {
        $console = $kernel->console();
        $console->setAutoExit(false);
        $console->setCatchExceptions(false);
        $status = $console->run(new ArrayInput([
            'command' => 'site:create',
            'slug' => 'preview',
            '--domain' => parse_url(self::URL, PHP_URL_HOST),
            '--db' => 'sqlite:///' . $this->workspace . '/preview.sqlite',
            '--owner-type' => 'organization',
            '--locale' => $locale,
            '--name' => $name,
            '--url' => self::URL,
            '--admin-email' => self::ADMIN,
            '--admin-first-name' => 'Preview',
            '--admin-last-name' => 'Editor',
            '--no-interaction' => true,
        ]), $output = new BufferedOutput());
        if ($status !== 0) {
            throw new ThemeException('The preview site could not be created: ' . trim($output->fetch()));
        }
    }

    private function get(Kernel $kernel, string $path): string
    {
        $response = $kernel->handle(new ServerRequest('GET', self::URL . $path, ['Accept' => 'text/html,*/*'], null, '1.1', [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTPS' => 'on',
        ]));
        $status = $response->getStatusCode();

        return $status === 200 ? (string) $response->getBody() : throw new ThemeException(sprintf('The preview site answered %d for %s.', $status, $path));
    }
}
