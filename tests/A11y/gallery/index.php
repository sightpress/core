<?php

/**
 * Component gallery for the accessibility tests: serves core's form building blocks and the default theme's style
 * guide (`/theme/home`, `/theme/default`) and the chart style guide (`/charts`) through the real middleware
 * (security headers, sessions, CSRF) so Playwright and axe can check them in a browser before any admin screen
 * exists. Started by playwright.config.ts with PHP's built-in web server; never deployed.
 */

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Analytics\Chart\BarChart;
use SightPress\Core\Analytics\Chart\ChartRenderer;
use SightPress\Core\Analytics\Chart\ChartSummary;
use SightPress\Core\Analytics\Chart\ChartView;
use SightPress\Core\Analytics\Chart\LineChart;
use SightPress\Core\Http\Csrf\CsrfMiddleware;
use SightPress\Core\Http\CurrentRequest;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Security\SecurityHeadersMiddleware;
use SightPress\Core\Http\Session\FileSessionStore;
use SightPress\Core\Http\Session\SessionMiddleware;
use SightPress\Core\Infrastructure\Clock\SystemClock;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Template\AssetUrls;
use SightPress\Core\Template\DateFormatter;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\TemplateExtension;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\TwigFactory;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Theme\Http\ThemeAssetHandler;
use SightPress\Core\Theme\ThemeAssets;
use SightPress\Core\Theme\ThemeLoader;
use SightPress\Core\Theme\TokenStylesheet;
use Slim\Factory\AppFactory;
use Slim\ResponseEmitter;
use Symfony\Component\Validator\Validation;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$factory = new Psr17Factory();
$current = new CurrentRequest();
$paths = new TemplatePaths([__DIR__, TemplatePaths::defaultTheme()], TemplatePaths::core());
$assets = new ThemeAssetHandler(
    new ThemeAssets(new ThemeLoader()->load(TemplatePaths::defaultTheme()), $paths, new TokenStylesheet()),
    $factory,
    $factory,
);
$twig = new TwigFactory()->create(
    $paths,
    ModuleRegistry::register([]),
    new TemplateExtension($current, new AssetUrls(), Translations::fromDirectories([]), new DateFormatter(), new Viewer('en-GB', 'UTC')),
    sys_get_temp_dir(),
    true,
);
$templates = new TemplateRenderer($twig, $current, $factory, $factory);

$form = Form::create(
    'profile',
    new Field('full_name', 'Full name', autocomplete: 'name'),
    new Field('email', 'Email address', FieldType::Email, hint: 'We only use it to sign you in.', autocomplete: 'email'),
    new Field('bio', 'Biography', FieldType::Textarea, hint: 'A sentence or two about you.', required: false),
    new Field('role', 'Role', FieldType::Select, choices: ['contributor' => 'Contributor', 'editor' => 'Editor', 'admin' => 'Administrator']),
    new Field('contact', 'How should we contact you?', FieldType::Radios, choices: ['email' => 'By email', 'phone' => 'By phone']),
    new Field('topics', 'Topics you write about', FieldType::Checkboxes, required: false, choices: ['a11y' => 'Accessibility', 'news' => 'News']),
    new Field('terms', 'I agree to the terms of use', FieldType::Checkbox, requiredMessage: 'Agree to the terms of use to continue'),
);

$app = AppFactory::create($factory);
$app->add(new CsrfMiddleware($factory));
$app->addRoutingMiddleware();
$app->add(new SessionMiddleware(new FileSessionStore(sys_get_temp_dir() . '/sightpress-gallery-sessions', new SystemClock(), 3600)));
$app->add(new SecurityHeadersMiddleware());

$menu = [['label' => 'Home layout', 'url' => '/theme/home'], ['label' => 'Default layout', 'url' => '/theme/default'], ['label' => 'Form', 'url' => '/form'], ['label' => 'Charts', 'url' => '/charts']];
$menuAt = static fn (string $path): array => array_map(static fn (array $item): array => $item + ['current' => $item['url'] === $path], $menu);

$app->map(['GET', 'POST'], '/form', static function (ServerRequestInterface $request) use ($form, $templates, $menuAt): ResponseInterface {
    if ($request->getMethod() === 'POST') {
        $body = $request->getParsedBody();
        $form = new FormValidator(Validation::createValidator())->submit($form, is_array($body) ? $body : []);
        if ($form->isValid()) {
            return $templates->response($request, 'saved.html.twig', ['form' => $form, 'title' => 'Profile saved', 'main_menu' => $menuAt('/form')]);
        }

        return $templates->response($request, 'form.html.twig', ['form' => $form, 'title' => 'Your profile', 'main_menu' => $menuAt('/form')], 422);
    }

    return $templates->response($request, 'form.html.twig', ['form' => $form, 'title' => 'Your profile', 'main_menu' => $menuAt('/form')]);
});

$app->get('/theme/{layout:home|default}', static function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($templates, $menuAt): ResponseInterface {
    $layout = $args['layout'] === 'home' ? 'home' : 'default';

    return $templates->response($request, 'theme.html.twig', [
        'layout' => $layout,
        'site_name' => 'Riverside Library',
        'title' => $layout === 'home' ? 'Welcome to Riverside Library' : 'Style guide',
        'lead' => 'Every part of the default theme on one page.',
        'main_menu' => $menuAt('/theme/' . $layout),
        'footer_menu' => [['label' => 'Accessibility statement', 'url' => '/theme/default'], ['label' => 'Privacy', 'url' => '/theme/default']],
        'messages' => isset($request->getQueryParams()['saved']) ? ['Your changes have been saved.'] : [],
    ]);
});
// Chart style guide (AN-2): every kind of chart, including the empty, single-day and all-zero cases.
$loader = $twig->getLoader();
if ($loader instanceof \Twig\Loader\FilesystemLoader) {
    $loader->addPath(dirname(__DIR__, 3) . '/resources/views/analytics', 'analytics');
}
$app->get('/charts', static function (ServerRequestInterface $request) use ($templates, $menuAt): ResponseInterface {
    $translations = Translations::fromDirectories([dirname(__DIR__, 3) . '/resources/translations']);
    $view = new ChartView(new ChartRenderer($translations), new ChartSummary($translations), $translations);
    $visitors = new Message('Visitors');
    $amount = new Message('{count, plural, one {# visitor} other {# visitors}}');
    $days = static fn (string $from, int $count): array => array_map(static fn (int $i): string => new DateTimeImmutable($from)->modify("+{$i} days")->format('Y-m-d'), range(0, $count - 1));
    $month = $days('2026-10-15', 30);
    $values = array_map(static fn (int $i): int => 30 + (($i * 37) % 41) + ($i % 7 === 3 ? 25 : 0), range(0, 29));
    $before = array_map(static fn (int $i): int => 25 + (($i * 23) % 37), range(0, 29));
    $current = new Message('Last 30 days');
    $previous = new Message('The 30 days before');

    return $templates->response($request, 'charts.html.twig', [
        'title' => 'Charts',
        'main_menu' => $menuAt('/charts'),
        'month' => $view->line(new LineChart('visits-month', $visitors, $amount, $month, $values, $before), 'en-GB', $current, $previous),
        'single' => $view->line(new LineChart('visits-today', $visitors, $amount, ['2026-11-13'], [42], [37]), 'en-GB', new Message('Today'), new Message('Yesterday')),
        'zero' => $view->line(new LineChart('visits-zero', $visitors, $amount, $days('2026-11-07', 7), array_fill(0, 7, 0), array_fill(0, 7, 0)), 'en-GB', new Message('Last 7 days'), new Message('The 7 days before')),
        'empty' => $view->line(new LineChart('visits-empty', $visitors, $amount, [], []), 'en-GB', $current, $previous),
        'devices' => $view->bar(new BarChart('devices', new Message('Devices'), $amount, [['label' => 'Computer', 'value' => 812], ['label' => 'Phone', 'value' => 498], ['label' => 'Tablet', 'value' => 39]]), 'en-GB', new Message('Device')),
        'nothing' => $view->bar(new BarChart('languages', new Message('Languages'), $amount, []), 'en-GB', new Message('Language')),
    ]);
});
$app->get('/assets/{path:.+}', static fn (ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    => $assets->handle($request->withAttribute(ThemeAssetHandler::PATH_ATTRIBUTE, $args['path'])));

$factoryForRequest = new ServerRequestCreator($factory, $factory, $factory, $factory);
new ResponseEmitter()->emit($app->handle($factoryForRequest->fromGlobals()));
