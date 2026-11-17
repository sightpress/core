<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin;

use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use Slim\Exception\HttpNotFoundException;
use Twig\Environment;

/**
 * What the page screens share: their addresses, the words for a page's state, the layouts a page can use and
 * finding the page a request is about.
 */
final readonly class PageAdmin
{
    public const string PATH = '/admin/pages';

    public const string ID_ATTRIBUTE = 'id';

    /** Layouts of the theme, in the order they are offered, with what they are for. */
    public const array LAYOUTS = [
        'default' => 'Standard page',
        'home' => 'Home page: the title and summary as a large introduction',
    ];

    public function __construct(
        private PageRepository $pages,
        private Environment $twig,
    ) {
    }

    public static function editPath(int $id): string
    {
        return self::PATH . '/' . $id;
    }

    public static function workflowPath(int $id): string
    {
        return self::editPath($id) . '/workflow';
    }

    public static function stateLabel(PageState $state): string
    {
        return match ($state) {
            PageState::Draft => 'Draft',
            PageState::InReview => 'Waiting for review',
            PageState::Approved => 'Approved',
            PageState::Published => 'Published',
            PageState::Archived => 'Archived',
            PageState::Trash => 'In the trash',
        };
    }

    /**
     * The page in the request's `{id}`.
     *
     * @throws HttpNotFoundException when there is no such page
     */
    public function page(ServerRequestInterface $request): Page
    {
        $id = $request->getAttribute(self::ID_ATTRIBUTE);
        $page = is_string($id) && ctype_digit($id) ? $this->pages->find((int) $id) : null;

        return $page ?? throw new HttpNotFoundException($request, 'There is no page with that number.');
    }

    /**
     * The layouts the theme has, plus the page's own one if the theme doesn't have it any more.
     *
     * @return array<string, string> name => description
     */
    public function layouts(?string $current = null): array
    {
        $layouts = array_filter(
            self::LAYOUTS,
            fn (string $name): bool => $this->twig->getLoader()->exists('layouts/' . $name . '.html.twig'),
            ARRAY_FILTER_USE_KEY,
        );
        if ($current !== null && !isset($layouts[$current])) {
            $layouts[$current] = sprintf('%s (not in this theme: the standard page is used)', ucfirst($current));
        }

        return $layouts;
    }
}
