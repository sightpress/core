<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Contract;

use SightPress\Module\Event\LocaleRemoved;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Template\LanguageName;
use SightPress\Core\Template\Message;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\Site;

/**
 * The languages a site is written in, and its default one, as its admins change them (in the admin panel or with
 * `site:locale:*`). They are stored in the site's settings, which win over the registry ({@see SiteProfile::display()}).
 *
 * Adding a language that was removed before brings its translations back: removing one never deletes what was
 * written in it, it only makes it unreachable ({@see LocaleRemoved}). The default language can't be removed; make
 * another one the default first.
 */
final class SiteLanguages
{
    /** @var non-empty-list<non-empty-string> */
    private array $locales;

    /** @var non-empty-string */
    private string $default;

    public function __construct(
        Site $site,
        private readonly SiteSettings $settings,
        private readonly EventDispatcherInterface $events,
    ) {
        $this->locales = $site->locales;
        $this->default = $site->defaultLocale;
    }

    /**
     * @return non-empty-list<non-empty-string> the default language first, then the others in the order they were added
     */
    public function locales(): array
    {
        return array_values(array_unique([$this->default, ...$this->locales]));
    }

    /**
     * @return non-empty-string
     */
    public function defaultLocale(): string
    {
        return $this->default;
    }

    public function has(string $locale): bool
    {
        return in_array($locale, $this->locales, true);
    }

    /**
     * Adds a language, written the way SightPress writes locales (`pt_pt` becomes `pt-PT`).
     *
     * @return non-empty-string the locale as added
     *
     * @throws SiteLanguageException when it is not a locale, or the site is already written in it
     */
    public function add(string $locale, ?int $actorId = null): string
    {
        try {
            $locale = LocaleCode::normalise($locale);
        } catch (InvalidArgumentException $exception) {
            throw new SiteLanguageException($exception->getMessage(), 0, $exception);
        }
        if ($locale === '' || $this->has($locale)) {
            throw new SiteLanguageException(new Message('The site is already written in {locale} ({locale2}).', ['locale' => new LanguageName($locale), 'locale2' => $locale]));
        }
        $this->locales[] = $locale;
        $this->store([SiteProfile::LOCALES], $actorId);

        return $locale;
    }

    /**
     * Removes a language. Its translations stay in the database, unreachable, until it is added again.
     *
     * @throws SiteLanguageException when the site isn't written in it, or it is the default language
     */
    public function remove(string $locale, ?int $actorId = null): void
    {
        $this->checkRemovable($locale);
        $kept = array_values(array_filter($this->locales, static fn (string $other): bool => $other !== $locale));
        $this->locales = [$this->default, ...array_values(array_diff($kept, [$this->default]))];
        $this->store([SiteProfile::LOCALES], $actorId);
        $this->events->dispatch(new LocaleRemoved($locale, $actorId));
    }

    /**
     * @phpstan-assert non-empty-string $locale
     *
     * @throws SiteLanguageException when the site isn't written in it, or it is the default language
     */
    public function checkRemovable(string $locale): void
    {
        $this->check($locale);
        if ($locale === $this->default) {
            throw new SiteLanguageException(new Message('{locale} ({locale2}) is the main language, so it can’t be removed. Make another language the main one first.', ['locale' => new LanguageName($locale), 'locale2' => $locale]));
        }
    }

    /**
     * Makes one of the site's languages the default one: its pages then have addresses without a language part, and
     * the old default's pages get one.
     *
     * @return bool whether anything changed
     *
     * @throws SiteLanguageException when the site isn't written in it
     */
    public function makeDefault(string $locale, ?int $actorId = null): bool
    {
        $this->check($locale);
        if ($locale === $this->default) {
            return false;
        }
        $this->default = $locale;
        $this->store([SiteProfile::DEFAULT_LOCALE], $actorId);

        return true;
    }

    /**
     * @phpstan-assert non-empty-string $locale
     */
    private function check(string $locale): void
    {
        if ($locale === '' || !$this->has($locale)) {
            throw new SiteLanguageException(new Message('The site isn’t written in “{locale}”. Its languages are: {locales}.', ['locale' => $locale, 'locales' => implode(', ', $this->locales())]));
        }
    }

    /**
     * @param list<string> $keys
     */
    private function store(array $keys, ?int $actorId): void
    {
        $this->settings->set(SiteProfile::LOCALES, implode(',', $this->locales()));
        $this->settings->set(SiteProfile::DEFAULT_LOCALE, $this->default);
        $this->events->dispatch(new SettingsChanged($keys, $actorId));
    }
}
