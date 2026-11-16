<?php

declare(strict_types=1);

namespace SightPress\Core\Menu\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\MenuException;

/**
 * One entry of a menu: a page of the site (shown with the page's title in the visitor's language, unless it has a
 * label of its own) or a link with a label. Entries can be nested up to {@see MAX_DEPTH} levels.
 */
#[ORM\Entity]
#[ORM\Table(name: 'menu_items')]
final class MenuItem
{
    public const int MAX_DEPTH = 3;

    public const int LABEL_MAX_LENGTH = 100;

    public const int URL_MAX_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER)]
    private int $position = 0;

    #[ORM\Column(type: Types::STRING, length: self::LABEL_MAX_LENGTH, nullable: true)]
    private ?string $label;

    #[ORM\Column(type: Types::STRING, length: self::URL_MAX_LENGTH, nullable: true)]
    private ?string $url;

    /**
     * @param ?int $pageId a page of the site; pages live in another module's table, so there is no foreign key
     */
    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 20, enumType: MenuName::class)]
        private MenuName $menu,
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
        private ?MenuItem $parent,
        #[ORM\Column(name: 'page_id', type: Types::INTEGER, nullable: true)]
        private ?int $pageId,
        ?string $label,
        ?string $url,
    ) {
        $label = $label === null ? null : trim((string) preg_replace('/\s+/u', ' ', $label));
        $this->label = $label === '' ? null : $label;
        $this->url = $url === null || trim($url) === '' ? null : trim($url);
        if (($this->pageId === null) === ($this->url === null)) {
            throw new MenuException('A menu entry is either a page or a link.');
        }
        if ($this->url !== null && $this->label === null) {
            throw new MenuException('Give the link a label.');
        }
        if ($this->label !== null && mb_strlen($this->label) > self::LABEL_MAX_LENGTH) {
            throw new MenuException(sprintf('The label must be %d characters or fewer.', self::LABEL_MAX_LENGTH));
        }
        if ($this->url !== null && (strlen($this->url) > self::URL_MAX_LENGTH || !self::isLink($this->url))) {
            throw new MenuException('The address must start with https://, http://, mailto:, tel: or / (an address on this site).');
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function menu(): MenuName
    {
        return $this->menu;
    }

    public function parent(): ?MenuItem
    {
        return $this->parent;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function pageId(): ?int
    {
        return $this->pageId;
    }

    public function label(): ?string
    {
        return $this->label;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    /**
     * @internal for {@see \SightPress\Core\Menu\MenuEditor}
     */
    public function place(?MenuItem $parent, int $position): void
    {
        $this->parent = $parent;
        $this->position = $position;
    }

    private static function isLink(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//') && preg_match('/\s/', $url) !== 1;
        }

        return preg_match('#^(https?://[^\s/]+[^\s]*|mailto:[^\s]+|tel:[0-9+() -]+)$#i', $url) === 1;
    }
}
