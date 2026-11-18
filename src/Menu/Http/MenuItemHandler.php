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
use SightPress\Core\Menu\Entity\MenuItem;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\LanguageName;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\Validator\Constraints\Length;

/**
 * `/admin/menus/{id}` — "Change a menu entry": its text in each of the site's languages (for a page, empty means
 * the page's title in that language; a link needs a text in the main language, which the other languages fall back
 * to) and whether only signed-in members see it. Saving goes back to the menus with the result announced and focus
 * on the entry. Needs `menu.manage`.
 */
final readonly class MenuItemHandler implements RequestHandlerInterface
{
    public const string ID_ATTRIBUTE = 'id';

    public function __construct(
        private AdminScreens $screens,
        private MenuEditor $menus,
        private PageLinks $pages,
        private FormValidator $validator,
        private Site $site,
    ) {
    }

    public static function path(int $id): string
    {
        return MenuAdminHandler::PATH . '/' . $id;
    }

    /**
     * The form field of a language's text: `label` for the main language, `label_pt_pt` for the others.
     */
    public static function fieldName(string $locale, string $mainLocale): string
    {
        return $locale === $mainLocale ? 'label' : 'label_' . str_replace('-', '_', strtolower($locale));
    }

    public function form(MenuItem $item): Form
    {
        $page = $item->pageId() !== null;
        $fields = [];
        foreach ($this->site->locales as $locale) {
            $main = $locale === $this->site->defaultLocale;
            $fields[] = new Field(
                self::fieldName($locale, $this->site->defaultLocale),
                new Message('Text in {language}', ['language' => new LanguageName($locale)]),
                hint: match (true) {
                    $page => 'Leave it empty to show the page’s title in this language.',
                    $main => 'Say where the link goes, for example “Opening hours”.',
                    default => 'Leave it empty to show the text in the main language.',
                },
                required: !$page && $main,
                constraints: [new Length(max: MenuItem::LABEL_MAX_LENGTH, maxMessage: '{field} must be {{ limit }} characters or fewer')],
            );
        }
        $fields[] = new Field('members_only', 'Only show this entry to members who are signed in', FieldType::Checkbox, hint: 'Entries for members-only pages are already hidden from people who aren’t signed in.', required: false);

        return Form::create('menu-item', ...$fields);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(MenuAdminHandler::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $id = $request->getAttribute(self::ID_ATTRIBUTE);
        $item = is_string($id) && ctype_digit($id) ? $this->menus->find((int) $id) : null;
        if ($item === null) {
            throw new HttpNotFoundException($request);
        }
        $form = $this->form($item)->withValues($this->values($item));
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $form = $this->validator->submit($this->form($item), is_array($body) ? $body : []);
            if ($form->isValid()) {
                $labels = [];
                foreach ($this->site->locales as $locale) {
                    if ($locale !== $this->site->defaultLocale) {
                        $labels[$locale] = $form->text(self::fieldName($locale, $this->site->defaultLocale));
                    }
                }
                // The form has checked what the entry checks (a link's text, the lengths).
                $this->menus->describe($item, $form->text('label'), $labels, $form->value('members_only') === '1', $actor->userId);
                Session::fromRequest($request)->set(MenuAdminHandler::FOCUS, 'item-' . $item->id());

                return $this->screens->redirect($request, MenuAdminHandler::PATH, new Message('{item} has been changed.', ['item' => $this->name($item)]));
            }
        }

        return $this->screens->render($request, '@menu/item.html.twig', [
            'form' => $form,
            'action' => self::path((int) $item->id()),
            'name' => $this->name($item),
            'page' => $item->pageId() !== null,
            'address' => $item->url(),
            'back' => MenuAdminHandler::PATH,
        ], $form->submitted && !$form->isValid() ? 422 : 200);
    }

    /**
     * @return array<string, string>
     */
    private function values(MenuItem $item): array
    {
        $values = ['label' => $item->label() ?? '', 'members_only' => $item->isMembersOnly() ? '1' : ''];
        foreach ($item->labels() as $locale => $text) {
            $values[self::fieldName($locale, $this->site->defaultLocale)] = $text;
        }

        return $values;
    }

    private function name(MenuItem $item): string
    {
        if ($item->label() !== null) {
            return $item->label();
        }
        $link = $this->pages->find([(int) $item->pageId()], $this->site->defaultLocale)[(int) $item->pageId()] ?? null;

        return $link === null ? 'The entry' : $link->title;
    }
}
