<?php

declare(strict_types=1);

namespace SightPress\Core\Menu\Entity;

use SightPress\Core\Template\Message;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\MenuException;

/**
 * One entry of a menu: a page of the site (shown with the page's title in the visitor's language, unless it has a
 * label of its own in that language) or a link with a label (in each language, or its main one). An entry can be for
 * signed-in members only. Entries can be nested up to {@see MAX_DEPTH} levels.
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

    /** @var array<string, string>|null the entry's text in other languages, locale => text */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $labels = null;

    #[ORM\Column(name: 'members_only', type: Types::BOOLEAN)]
    private bool $membersOnly = false;

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
        $this->label = self::text($label);
        $this->url = $url === null || trim($url) === '' ? null : trim($url);
        if (($this->pageId === null) === ($this->url === null)) {
            throw new MenuException('A menu entry is either a page or a link.');
        }
        if ($this->url !== null && $this->label === null) {
            throw new MenuException('Give the link a label.');
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
     * The entry's own text in a language: its text in that language, else (in the site's main language, or for a
     * link) its main text. Null means a page entry shows the page's title in that language.
     */
    public function labelIn(string $locale, string $mainLocale): ?string
    {
        $own = $this->labels[$locale] ?? null;
        if ($own !== null) {
            return $own;
        }

        return $locale === $mainLocale || $this->pageId === null ? $this->label : null;
    }

    /**
     * @return array<string, string> the entry's text in other languages, locale => text
     */
    public function labels(): array
    {
        return $this->labels ?? [];
    }

    /**
     * Whether only signed-in members see the entry.
     */
    public function isMembersOnly(): bool
    {
        return $this->membersOnly;
    }

    /**
     * Changes the entry's text (in the main language and in others; empty texts are dropped) and who sees it.
     *
     * @param array<string, string|null> $labels locale => text
     */
    public function describe(?string $label, array $labels, bool $membersOnly): void
    {
        $label = self::text($label);
        if ($this->url !== null && $label === null) {
            throw new MenuException('Give the link a label.');
        }
        $texts = [];
        foreach ($labels as $locale => $text) {
            $text = self::text($text);
            if ($text !== null) {
                $texts[$locale] = $text;
            }
        }
        ksort($texts);
        $this->label = $label;
        $this->labels = $texts === [] ? null : $texts;
        $this->membersOnly = $membersOnly;
    }

    /**
     * @internal for {@see \SightPress\Core\Menu\MenuEditor}
     */
    public function place(?MenuItem $parent, int $position): void
    {
        $this->parent = $parent;
        $this->position = $position;
    }

    /**
     * A text with its spaces tidied, or null when empty.
     *
     * @throws MenuException when it is too long
     */
    private static function text(?string $text): ?string
    {
        $text = $text === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) > self::LABEL_MAX_LENGTH) {
            throw new MenuException(new Message('The label must be {max} characters or fewer.', ['max' => (string) self::LABEL_MAX_LENGTH]));
        }

        return $text === '' ? null : $text;
    }

    private static function isLink(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//') && preg_match('/\s/', $url) !== 1;
        }

        return preg_match('#^(https?://[^\s/]+[^\s]*|mailto:[^\s]+|tel:[0-9+() -]+)$#i', $url) === 1;
    }
}
