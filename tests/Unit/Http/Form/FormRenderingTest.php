<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Form;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Csrf\CsrfTokens;
use SightPress\Core\Http\CurrentRequest;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Session;
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
use Symfony\Component\Validator\Validation;

/**
 * Renders the core form macros and checks the accessible structure of the HTML.
 */
#[CoversClass(TemplateExtension::class)]
#[UsesClass(Form::class)]
#[UsesClass(Field::class)]
#[UsesClass(FieldType::class)]
#[UsesClass(FormValidator::class)]
#[UsesClass(CurrentRequest::class)]
#[UsesClass(Session::class)]
#[UsesClass(CsrfTokens::class)]
#[UsesClass(\SightPress\Core\Http\Csrf\CsrfToken::class)]
#[UsesClass(ModuleRegistry::class)]
#[UsesClass(AssetUrls::class)]
#[UsesClass(DateFormatter::class)]
#[UsesClass(TemplatePaths::class)]
#[UsesClass(TemplateRenderer::class)]
#[UsesClass(Translations::class)]
#[UsesClass(TwigFactory::class)]
#[UsesClass(Viewer::class)]
final class FormRenderingTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->directory->write('theme/templates/page.twig', <<<'TWIG'
            {% import 'form/macros.html.twig' as f %}
            <main>
            {{ f.error_summary(form) }}
            {{ f.start(form, '/save') }}
            {% for field in form.fields %}{{ f.field(form, field.name) }}{% endfor %}
            {{ f.submit('Save') }}
            {{ f.end() }}
            {{ f.start(form, '/search', 'get') }}{{ f.end() }}
            </main>
            TWIG);
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testAFreshFormHasLabelsHintsAndNoErrors(): void
    {
        $xpath = $this->render($this->form()->withValues(['email' => 'ada@example.org', 'role' => 'admin', 'topics' => ['seo']]));

        self::assertSame(0, $this->countNodes($xpath, '//section[contains(@class, "error-summary")]'));
        self::assertSame(0, $this->countNodes($xpath, '//*[@aria-invalid]'));

        $email = $this->element($xpath, '//input[@id="profile-email"]');
        self::assertSame('email', $email->getAttribute('type'));
        self::assertSame('email', $email->getAttribute('name'));
        self::assertSame('ada@example.org', $email->getAttribute('value'));
        self::assertSame('email', $email->getAttribute('autocomplete'));
        self::assertSame('profile-email-hint', $email->getAttribute('aria-describedby'));
        self::assertSame('We only use it to sign you in.', trim($this->element($xpath, '//p[@id="profile-email-hint"]')->textContent));
        self::assertSame('Email address', trim($this->element($xpath, '//label[@for="profile-email"]')->textContent));
        self::assertSame('Biography (optional)', trim($this->element($xpath, '//label[@for="profile-bio"]')->textContent));
        self::assertFalse($this->element($xpath, '//textarea[@id="profile-bio"]')->hasAttribute('aria-describedby'));

        self::assertSame('', $this->element($xpath, '//input[@id="profile-password"]')->getAttribute('value'));
        self::assertTrue($this->element($xpath, '//select[@id="profile-role"]/option[@value="admin"]')->hasAttribute('selected'));
        self::assertSame('', $this->element($xpath, '//select[@id="profile-role"]/option[1]')->getAttribute('value'));

        self::assertSame('Topics (optional)', trim($this->element($xpath, '//fieldset[.//input[@name="topics[]"]]/legend')->textContent));
        self::assertTrue($this->element($xpath, '//input[@id="profile-topics-2"]')->hasAttribute('checked'));
        self::assertSame('Search', trim($this->element($xpath, '//label[@for="profile-topics-2"]')->textContent));
        self::assertSame('radio', $this->element($xpath, '//input[@id="profile-plan"]')->getAttribute('type'));

        $form = $this->element($xpath, '//form[@action="/save"]');
        self::assertSame('post', $form->getAttribute('method'));
        self::assertTrue($form->hasAttribute('novalidate'));
        self::assertSame(2, $this->countNodes($xpath, '//form[@action="/save"]/input[@type="hidden"][@name="csrf_name" or @name="csrf_value"]'));
        self::assertSame(0, $this->countNodes($xpath, '//form[@action="/search"]//input'), 'GET forms carry no token');
        self::assertSame('Save', trim($this->element($xpath, '//button[@type="submit"]')->textContent));
    }

    public function testErrorsAreLinkedToTheirFieldsAndSummarisedAtTheTop(): void
    {
        $form = new FormValidator(Validation::createValidator())->submit($this->form(), [
            'email' => 'ada@',
            'password' => 'secret',
            'role' => '',
            'plan' => '',
            'terms' => '',
        ]);

        $xpath = $this->render($form);

        $summary = $this->element($xpath, '//main/section[1]');
        self::assertSame('error-summary', $summary->getAttribute('class'));
        self::assertSame('-1', $summary->getAttribute('tabindex'));
        self::assertTrue($summary->hasAttribute('autofocus'));
        self::assertSame('There is a problem', trim($this->element($xpath, '//section/h2[@id=' . $this->quote($summary->getAttribute('aria-labelledby')) . ']')->textContent));
        $links = [];
        foreach ($this->nodes($xpath, '//section//li/a') as $link) {
            self::assertInstanceOf(DOMElement::class, $link);
            $links[$link->getAttribute('href')] = trim($link->textContent);
        }
        self::assertSame([
            '#profile-email' => 'Enter an email address in the right format, like name@example.com',
            '#profile-role' => 'Select role',
            '#profile-plan' => 'Select plan',
            '#profile-terms' => 'Agree to the terms',
        ], $links);

        $email = $this->element($xpath, '//input[@id="profile-email"]');
        self::assertSame('true', $email->getAttribute('aria-invalid'));
        self::assertSame('profile-email-hint profile-email-error', $email->getAttribute('aria-describedby'));
        self::assertSame(
            'Error: Enter an email address in the right format, like name@example.com',
            trim(preg_replace('/\s+/', ' ', $this->element($xpath, '//p[@id="profile-email-error"]')->textContent) ?? ''),
        );
        self::assertSame('', $this->element($xpath, '//input[@id="profile-password"]')->getAttribute('value'), 'passwords are never sent back');

        self::assertSame('profile-plan-error', $this->element($xpath, '//fieldset[.//input[@name="plan"]]')->getAttribute('aria-describedby'));
        self::assertSame('true', $this->element($xpath, '//input[@id="profile-plan-2"]')->getAttribute('aria-invalid'));
        self::assertSame('profile-terms-error', $this->element($xpath, '//input[@id="profile-terms"]')->getAttribute('aria-describedby'));
        self::assertSame(0, $this->countNodes($xpath, '//input[@id="profile-bio"][@aria-invalid]'));
    }

    private function form(): Form
    {
        return Form::create(
            'profile',
            new Field('email', 'Email address', FieldType::Email, hint: 'We only use it to sign you in.', autocomplete: 'email'),
            new Field('password', 'Password', FieldType::Password, autocomplete: 'new-password'),
            new Field('bio', 'Biography', FieldType::Textarea, required: false),
            new Field('role', 'Role', FieldType::Select, choices: ['editor' => 'Editor', 'admin' => 'Admin']),
            new Field('plan', 'Plan', FieldType::Radios, choices: ['free' => 'Free', 'paid' => 'Paid']),
            new Field('topics', 'Topics', FieldType::Checkboxes, required: false, choices: ['a11y' => 'Accessibility', 'seo' => 'Search']),
            new Field('terms', 'I agree to the terms', FieldType::Checkbox, requiredMessage: 'Agree to the terms'),
        );
    }

    private function render(Form $form): DOMXPath
    {
        $current = new CurrentRequest();
        $extension = new TemplateExtension(
            $current,
            new AssetUrls(),
            Translations::fromDirectories([]),
            new DateFormatter(),
            new Viewer('en-GB', 'UTC'),
        );
        $factory = new Psr17Factory();
        $twig = new TwigFactory()->create(
            new TemplatePaths([$this->directory->path . '/theme'], TemplatePaths::core()),
            ModuleRegistry::register([]),
            $extension,
            $this->directory->path . '/cache',
            true,
        );
        $request = new ServerRequest('GET', '/')
            ->withAttribute(CsrfTokens::class, new CsrfTokens(new Session(), $factory));

        $html = new TemplateRenderer($twig, $current, $factory, $factory)->render($request, 'page.twig', ['form' => $form]);

        $document = new DOMDocument();
        self::assertTrue($document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR));

        return new DOMXPath($document);
    }

    /**
     * @return \DOMNodeList<\DOMNameSpaceNode|\DOMNode>
     */
    private function nodes(DOMXPath $xpath, string $query): \DOMNodeList
    {
        $nodes = $xpath->query($query);
        self::assertInstanceOf(\DOMNodeList::class, $nodes);

        return $nodes;
    }

    private function countNodes(DOMXPath $xpath, string $query): int
    {
        return $this->nodes($xpath, $query)->length;
    }

    private function element(DOMXPath $xpath, string $query): DOMElement
    {
        $nodes = $this->nodes($xpath, $query);
        self::assertSame(1, $nodes->length, $query);
        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    private function quote(string $value): string
    {
        return '"' . $value . '"';
    }
}
