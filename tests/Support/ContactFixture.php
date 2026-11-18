<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Block\DefaultBlockFinder;
use SightPress\Core\Contact\ContactFormBlock;
use SightPress\Core\Contact\ContactMailer;
use SightPress\Core\Contact\Http\ContactFormHandler;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PublishedPages;
use SightPress\Module\Block\BlockContext;

/**
 * Contact forms for tests: pages (with the contact form block) in the auth fixture's database, its templates, rate
 * limits and outbox, and the handler built on them.
 */
final class ContactFixture
{
    public const array FORM = ['recipient' => 'office@example.org', 'subject' => 'Website message'];

    public readonly AuthFixture $auth;

    public readonly PageFixture $pages;

    public readonly BlockFixture $blocks;

    public readonly ContactFormBlock $type;

    public function __construct()
    {
        $this->auth = new AuthFixture();
        $this->type = new ContactFormBlock($this->auth->templates);
        $this->pages = new PageFixture($this->auth->database, [$this->type]);
        $this->blocks = new BlockFixture([$this->type], $this->auth->database);
    }

    /**
     * A published page with a heading and, in a column, a contact form with {@see FORM}. On an empty site a home
     * page comes first, so the form's page has an address of its own.
     *
     * @param array<string, mixed> $form
     */
    public function page(string $title = 'Contact', array $form = self::FORM): Page
    {
        if ($this->pages->repository()->children(null) === []) {
            $this->pages->published('Home');
        }

        return $this->pages->published($title, [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Write to us', 'level' => 2]],
            ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [
                ['blocks' => [['type' => 'contact_form', 'version' => 1, 'data' => $form]]],
                ['blocks' => []],
            ]]],
        ]);
    }

    public function handler(): ContactFormHandler
    {
        return new ContactFormHandler(
            new PublishedPages($this->pages->addresses()),
            new DefaultBlockFinder($this->blocks->types),
            $this->auth->validator(),
            $this->auth->limiters,
            new ContactMailer($this->auth, $this->auth->templates, $this->pages->site, $this->auth->translations),
            $this->auth->http,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    public function post(array $body, ?Session $session = null): ServerRequestInterface
    {
        return $this->auth->request('POST', 'https://demo.example/contact-form', [], [], $session)->withParsedBody($body);
    }

    public function context(ServerRequestInterface $request): BlockContext
    {
        return $this->blocks->contexts($this->pages->site)->create('en-GB', 'https://demo.example', $request);
    }
}
