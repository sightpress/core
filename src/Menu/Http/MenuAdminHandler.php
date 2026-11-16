<?php

declare(strict_types=1);

namespace SightPress\Core\Menu\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\Entity\MenuItem;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Menu\MenuException;
use SightPress\Core\Menu\MenuNode;
use SightPress\Core\Page\Contract\PageLink;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Symfony\Component\Validator\Constraints\Length;

/**
 * `/admin/menus`: the main and footer menus as nested lists, each entry with buttons to move it up, down, in (under
 * the entry before it) or out, and to remove it; and two forms to add a page or a link. Every change is a POST
 * that comes back here (Post/Redirect/Get) with a message in the live region and focus on the same button of the
 * entry that moved (or on the entry, when that button is gone). Needs `menu.manage`.
 */
final readonly class MenuAdminHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/menus';

    public const string PERMISSION = 'menu.manage';

    private const string FOCUS = 'menu_admin.focus';

    private const array MOVES = ['up', 'down', 'in', 'out', 'remove'];

    public function __construct(
        private AdminScreens $screens,
        private MenuEditor $menus,
        private PageLinks $pages,
        private FormValidator $validator,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(self::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $pageForm = $this->pageForm();
        $linkForm = $this->linkForm();
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $action = is_string($body['action'] ?? null) ? $body['action'] : '';
            if (in_array($action, self::MOVES, true)) {
                return $this->move($request, $action, $body['item'] ?? null, $actor);
            }
            if ($action === 'add-page' && $pageForm !== null) {
                $pageForm = $this->validator->submit($pageForm, $body);
                if ($pageForm->isValid()) {
                    return $this->add($request, $pageForm, (int) substr($pageForm->text('page'), 5), null, null, $actor);
                }
            } elseif ($action === 'add-link') {
                $linkForm = $this->validator->submit($linkForm, $body);
                if ($linkForm->isValid()) {
                    try {
                        new MenuItem(MenuName::Main, null, null, $linkForm->text('label'), $linkForm->text('url'));

                        return $this->add($request, $linkForm, null, $linkForm->text('label'), $linkForm->text('url'), $actor);
                    } catch (MenuException $exception) {
                        $linkForm = $linkForm->withErrors(['url' => $exception->getMessage()]);
                    }
                }
            } else {
                throw new HttpBadRequestException($request, 'This form isn\'t one of the menu forms.');
            }
        }

        $focus = Session::fromRequest($request)->get(self::FOCUS);
        Session::fromRequest($request)->remove(self::FOCUS);
        $errors = count($pageForm?->errors() ?? []) + count($linkForm->errors());

        return $this->screens->render($request, '@menu/admin.html.twig', [
            'menus' => [MenuName::Main, MenuName::Footer],
            'trees' => [MenuName::Main->value => $this->nodes(MenuName::Main), MenuName::Footer->value => $this->nodes(MenuName::Footer)],
            'page_form' => $pageForm,
            'link_form' => $linkForm,
            'error_count' => $errors,
            'focus' => is_string($focus) ? $focus : null,
            'action' => self::PATH,
        ], $errors > 0 ? 422 : 200);
    }

    private function add(ServerRequestInterface $request, Form $form, ?int $pageId, ?string $label, ?string $url, Actor $actor): ResponseInterface
    {
        $menu = MenuName::from($form->text('menu'));
        $item = $this->menus->add($menu, $pageId, $label, $url, $actor->userId);
        Session::fromRequest($request)->set(self::FOCUS, 'item-' . $item->id());

        return $this->screens->redirect($request, self::PATH, sprintf('%s has been added to the %s.', $this->label($item), mb_strtolower($menu->label())));
    }

    private function move(ServerRequestInterface $request, string $action, mixed $id, Actor $actor): ResponseInterface
    {
        $item = is_string($id) && ctype_digit($id) ? $this->menus->find((int) $id) : null;
        if ($item === null) {
            return $this->screens->redirect($request, self::PATH, 'That menu entry isn\'t there any more.');
        }
        $label = $this->label($item);
        try {
            $template = match ($action) {
                'up' => '%s has been moved up.',
                'down' => '%s has been moved down.',
                'in' => '%s has been moved in, under the entry before it.',
                'out' => '%s has been moved out, after the entry it was under.',
                default => '%s has been removed from the menu.',
            };
            match ($action) {
                'up' => $this->menus->moveUp($item, $actor->userId),
                'down' => $this->menus->moveDown($item, $actor->userId),
                'in' => $this->menus->indent($item, $actor->userId),
                'out' => $this->menus->outdent($item, $actor->userId),
                default => $this->menus->remove($item, $actor->userId),
            };
            $message = sprintf($template, $label);
        } catch (MenuException $exception) {
            $message = $label . ': ' . $exception->getMessage();
        }
        Session::fromRequest($request)->set(self::FOCUS, $action === 'remove' ? 'menu-' . $item->menu()->value : sprintf('item-%d-%s', $item->id(), $action));

        return $this->screens->redirect($request, self::PATH, $message);
    }

    /**
     * @return list<array<string, mixed>> {id, label, link, page, hidden, moves, children}
     */
    private function nodes(MenuName $menu): array
    {
        $tree = $this->menus->tree($menu);

        return $this->view($tree, $this->pages->find(MenuNode::pageIds($tree), $this->site->defaultLocale), 0);
    }

    /**
     * @param list<MenuNode>       $nodes
     * @param array<int, PageLink> $links
     *
     * @return list<array<string, mixed>>
     */
    private function view(array $nodes, array $links, int $depth): array
    {
        $built = [];
        foreach ($nodes as $index => $node) {
            $item = $node->item;
            $link = $item->pageId() === null ? null : ($links[$item->pageId()] ?? null);
            $moves = [];
            $possible = [
                'up' => $index > 0,
                'down' => $index < count($nodes) - 1,
                'in' => $index > 0 && $depth + 1 + self::height($node) <= MenuItem::MAX_DEPTH,
                'out' => $depth > 0,
            ];
            foreach ($possible as $move => $allowed) {
                if ($allowed) {
                    $moves[] = $move;
                }
            }
            $built[] = [
                'id' => (int) $item->id(),
                'label' => $item->label() ?? $link->title ?? sprintf('Missing page (#%d)', (int) $item->pageId()),
                'link' => $item->url() ?? $link?->url,
                'page' => $item->pageId() !== null,
                'hidden' => $item->pageId() !== null && $link?->public !== true,
                'moves' => [...$moves, 'remove'],
                'children' => $this->view($node->children, $links, $depth + 1),
            ];
        }

        return $built;
    }

    /**
     * How many levels an entry takes with the entries under it: 1 without any.
     */
    private static function height(MenuNode $node): int
    {
        $height = 0;
        foreach ($node->children as $child) {
            $height = max($height, self::height($child));
        }

        return $height + 1;
    }

    private function label(MenuItem $item): string
    {
        if ($item->label() !== null) {
            return $item->label();
        }
        $link = $this->pages->find([(int) $item->pageId()], $this->site->defaultLocale)[(int) $item->pageId()] ?? null;

        return $link === null ? 'The entry' : $link->title;
    }

    /**
     * Null while the site has no pages.
     */
    private function pageForm(): ?Form
    {
        $choices = [];
        foreach ($this->pages->all($this->site->defaultLocale) as $link) {
            $choices['page-' . $link->id] = str_repeat('— ', $link->depth) . $link->title . ($link->public ? '' : ' (not published)');
        }

        return $choices === [] ? null : Form::create(
            'add-page',
            self::menuField(),
            new Field('page', 'Page', FieldType::Select, hint: 'Pages that aren\'t published yet appear in the menu once they are.', choices: $choices),
        );
    }

    private function linkForm(): Form
    {
        return Form::create(
            'add-link',
            self::menuField(),
            new Field('label', 'Link text', hint: 'Say where the link goes, for example "Opening hours".', constraints: [new Length(max: MenuItem::LABEL_MAX_LENGTH)]),
            new Field('url', 'Address', hint: 'A full web address such as https://example.org, or an address on this site such as /events.', constraints: [new Length(max: MenuItem::URL_MAX_LENGTH)]),
        );
    }

    private static function menuField(): Field
    {
        return new Field('menu', 'Menu', FieldType::Radios, choices: [MenuName::Main->value => MenuName::Main->label(), MenuName::Footer->value => MenuName::Footer->label()]);
    }
}
