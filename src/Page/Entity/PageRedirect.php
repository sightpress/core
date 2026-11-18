<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SightPress\Core\Page\PageException;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\LocaleCode;

/**
 * An old address of a page in one locale and where it leads now (301), or nothing when the page was deleted for
 * good (410). Addresses are written like a translation's path: without the leading slash and the locale prefix
 * (`about/team`). There is at most one per address, and it always leads straight to the end (no chains).
 */
#[ORM\Entity]
#[ORM\Table(name: 'page_redirects')]
#[ORM\UniqueConstraint(name: 'page_redirects_locale_source_unique', columns: ['locale', 'source_path'])]
#[ORM\Index(name: 'page_redirects_locale_target_idx', columns: ['locale', 'target_path'])]
final class PageRedirect
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(name: 'target_path', type: Types::STRING, length: PageTranslation::PATH_MAX_LENGTH, nullable: true)]
    private ?string $target;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 35)]
        private string $locale,
        #[ORM\Column(name: 'source_path', type: Types::STRING, length: PageTranslation::PATH_MAX_LENGTH)]
        private string $source,
        ?string $target,
        DateTimeImmutable $now,
    ) {
        if (!LocaleCode::isValid($locale)) {
            throw new PageException(new Message('"{locale}" is not a locale code such as "en-GB".', ['locale' => $locale]));
        }
        self::check($source);
        $this->retarget($target, $now);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * The old address, for example `about/team`.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * The address it leads to, or null when the page is gone for good.
     */
    public function target(): ?string
    {
        return $this->target;
    }

    public function isGone(): bool
    {
        return $this->target === null;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @internal for {@see \SightPress\Core\Page\PageRedirects}
     */
    public function retarget(?string $target, DateTimeImmutable $now): void
    {
        if ($target !== null) {
            self::check($target);
            if ($target === $this->source) {
                throw new PageException('An address can’t lead to itself.');
            }
        }
        $this->target = $target;
        $this->updatedAt = $now;
    }

    private static function check(string $path): void
    {
        if (strlen($path) > PageTranslation::PATH_MAX_LENGTH) {
            throw new PageException(new Message('The full address must be {max} characters or fewer.', ['max' => (string) PageTranslation::PATH_MAX_LENGTH]));
        }
    }
}
