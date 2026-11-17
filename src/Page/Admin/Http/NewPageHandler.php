<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpForbiddenException;
use Symfony\Component\Validator\Constraints\Length;

/**
 * `/admin/pages/new`: starts a page as a draft, with its title, the page it goes under and its layout, then opens
 * it in the editor. Needs `page.create`.
 */
final readonly class NewPageHandler implements RequestHandlerInterface
{
    public const string PATH = PageAdmin::PATH . '/new';

    public const string TOP = 'top';

    public function __construct(
        private AdminScreens $screens,
        private FormValidator $validator,
        private PageRepository $pages,
        private PageEditor $editor,
        private PageAdmin $admin,
        private Site $site,
    ) {
    }

    public function form(): Form
    {
        $parents = [self::TOP => 'None: a top-level page'];
        foreach ($this->pages->all() as $page) {
            $translation = $page->translation($this->site->defaultLocale);
            if ($translation !== null && $page->depth() < Page::MAX_DEPTH - 1) {
                $parents['page-' . $page->id()] = str_repeat('— ', $page->depth()) . $translation->title();
            }
        }

        return Form::create(
            'new-page',
            new Field('title', 'Title', hint: 'You can change it later.', constraints: [new Length(max: PageContent::TITLE_MAX_LENGTH, maxMessage: sprintf('The title must be %d characters or fewer', PageContent::TITLE_MAX_LENGTH))]),
            new Field('parent', 'Put it under', FieldType::Select, hint: 'The page this one belongs to. Its address starts with that page’s address.', choices: $parents),
            new Field('layout', 'Layout', FieldType::Radios, choices: $this->admin->layouts()),
        )->withValues(['parent' => self::TOP, 'layout' => 'default']);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has('page.create')) {
            throw new HttpForbiddenException($request);
        }
        $form = $this->form();
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $form = $this->validator->submit($form, is_array($body) ? $body : []);
            if ($form->isValid()) {
                $parentValue = $form->text('parent');
                $parent = $parentValue === self::TOP ? null : $this->pages->find((int) substr($parentValue, 5));
                try {
                    $page = $this->editor->create($this->site->defaultLocale, new PageContent($form->text('title')), $parent, $actor->userId);
                    if ($form->text('layout') !== $page->layout()) {
                        $page->setLayout($form->text('layout'), $page->createdAt());
                        $this->pages->flush();
                    }

                    return $this->screens->redirect($request, PageAdmin::editPath((int) $page->id()), sprintf(
                        '“%s” has been started as a draft. Add its content, then save it.',
                        $form->text('title'),
                    ));
                } catch (PageException $exception) {
                    $form = $form->withErrors(['title' => $exception->getMessage()]);
                }
            }
        }

        return $this->screens->render($request, '@page_admin/new.html.twig', [
            'form' => $form,
            'action' => self::PATH,
        ], $form->submitted && !$form->isValid() ? 422 : 200);
    }
}
