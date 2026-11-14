<?php

/**
 * Component gallery for the accessibility tests: serves core's form building blocks through the real middleware
 * (security headers, sessions, CSRF) so Playwright and axe can check them in a browser before any admin screen
 * exists. Started by playwright.config.ts with PHP's built-in web server; never deployed.
 */

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
use SightPress\Core\Template\TemplateExtension;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\TwigFactory;
use SightPress\Core\Template\Viewer;
use Slim\Factory\AppFactory;
use Slim\ResponseEmitter;
use Symfony\Component\Validator\Validation;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$factory = new Psr17Factory();
$current = new CurrentRequest();
$twig = new TwigFactory()->create(
    new TemplatePaths([__DIR__], TemplatePaths::core()),
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

$app->map(['GET', 'POST'], '/form', static function (ServerRequestInterface $request) use ($form, $templates): ResponseInterface {
    if ($request->getMethod() === 'POST') {
        $body = $request->getParsedBody();
        $form = new FormValidator(Validation::createValidator())->submit($form, is_array($body) ? $body : []);
        if ($form->isValid()) {
            return $templates->response($request, 'saved.html.twig', ['form' => $form]);
        }

        return $templates->response($request, 'form.html.twig', ['form' => $form], 422);
    }

    return $templates->response($request, 'form.html.twig', ['form' => $form]);
});

$factoryForRequest = new ServerRequestCreator($factory, $factory, $factory, $factory);
new ResponseEmitter()->emit($app->handle($factoryForRequest->fromGlobals()));
