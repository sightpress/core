<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use IntlDateFormatter;
use Psr\Clock\ClockInterface;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;
use SightPress\Core\Tenant\LocaleFallback;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tenant\EnabledModules;
use SightPress\Core\Tenant\Site;
use Twig\Environment;

/**
 * The starter pages every new site gets (C-6.4, I-4) — Home (the home page, in the `home` layout), About, Contact
 * (with a contact form that writes to the first admin), Privacy, Terms and an Accessibility statement — written in a
 * language from the templates for a person or an organization (`@page/starter/<owner type>/<locale>/`). Their titles
 * and descriptions come from the interface catalogues, their addresses are the language's own (`/pt-pt/sobre`) and
 * their links point at the pages in the same language.
 *
 * A language without templates of its own uses the closest one there is, otherwise British English, and says so. The
 * pages are remembered in the site setting {@see SETTING}, so a language added later can get them too, as drafts
 * ({@see writeIn()}).
 */
final readonly class StarterContent
{
    public const string TEMPLATES = '@page/starter/';

    /** The starter pages, their owner and address and who gets the contact form's messages, as JSON. */
    public const string SETTING = 'page.starter';

    public const array SLUGS = ['home', 'about', 'contact', 'privacy', 'terms', 'accessibility'];

    /** The languages the starter pages are written in, British English first. */
    public const array LOCALES = ['en-GB', 'pt-PT', 'es'];

    /** The pages' addresses in the languages that have templates; others use these English ones. */
    private const array ADDRESSES = [
        'pt-PT' => ['home' => 'inicio', 'about' => 'sobre', 'contact' => 'contacto', 'privacy' => 'privacidade', 'terms' => 'termos', 'accessibility' => 'acessibilidade'],
        'es' => ['home' => 'inicio', 'about' => 'sobre', 'contact' => 'contacto', 'privacy' => 'privacidad', 'terms' => 'condiciones', 'accessibility' => 'accesibilidad'],
    ];

    public function __construct(
        private PageEditor $editor,
        private PageRepository $pages,
        private PageAddresses $addresses,
        private SiteSettings $settings,
        private Translations $translations,
        private Environment $twig,
        private ClockInterface $clock,
        private Site $site,
        private EnabledModules $modules,
    ) {
    }

    /**
     * Writes the starter pages of a new site in every one of its languages, published, and makes Home the home page.
     *
     * @param string $owner        the first admin's name, the owner of a person's site
     * @param string $baseUrl      the site's address, for the accessibility statement
     * @param string $contactEmail who gets the contact form's messages
     */
    public function create(string $owner, string $baseUrl, string $contactEmail, ?int $authorId): StarterReport
    {
        $this->settings->set(self::SETTING, (string) json_encode(['owner' => $owner, 'url' => rtrim($baseUrl, '/'), 'contact' => $contactEmail, 'pages' => []]));
        $now = $this->clock->now();
        $created = [];
        $default = $this->site->defaultLocale;
        foreach (self::SLUGS as $slug) {
            $page = $this->editor->create($default, $this->content($slug, $default), null, $authorId);
            if ($slug === 'home') {
                $page->setLayout('home', $now);
            }
            $page->changeState(PageState::Published, $now);
            $created[$slug] = $page;
        }
        $this->pages->flush();
        $this->addresses->setHome($created['home']);
        $this->remember($created);

        $titles = array_map(static fn (Page $page): string => (string) $page->translation($default)?->title(), array_values($created));
        $fallbacks = $this->fallback($default) === null ? [] : [$default];
        foreach ($this->site->locales as $locale) {
            if ($locale !== $default) {
                $fallbacks = [...$fallbacks, ...$this->writeIn($locale, $authorId, true)->fallbacks];
            }
        }

        return new StarterReport($titles, $this->site->locales, $fallbacks);
    }

    /**
     * Whether some starter page isn't written in the language yet (and so {@see writeIn()} has something to do).
     */
    public function missingIn(string $locale): bool
    {
        foreach ($this->remembered() as $page) {
            if ($page->translation($locale) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writes the starter pages that aren't written in a language yet, in that language: as drafts of their own (for
     * a language added later; they are never published here), or published (when the site is made).
     */
    public function writeIn(string $locale, ?int $authorId, bool $publish = false): StarterReport
    {
        $titles = [];
        foreach ($this->remembered() as $slug => $page) {
            if ($page->translation($locale) !== null) {
                continue;
            }
            $content = $this->content($slug, $locale);
            $this->editor->save($page, $locale, $content, $authorId);
            if ($publish) {
                $this->editor->publishTranslation($page, $locale, true, $authorId);
            }
            $titles[] = $content->title;
        }

        return new StarterReport($titles, [$locale], $titles !== [] && $this->fallback($locale) !== null ? [$locale] : []);
    }

    /**
     * The language whose templates a language uses when it has none of its own, or null when it has its own.
     */
    public function fallback(string $locale): ?string
    {
        $templates = $this->templates($locale);

        return $templates === $locale ? null : $templates;
    }

    private function content(string $slug, string $locale): PageContent
    {
        $stored = $this->stored();
        $person = $this->site->ownerType === OwnerType::Person;
        $owner = is_string($stored['owner'] ?? null) ? $stored['owner'] : '';
        $name = mb_substr($this->site->name, 0, 150);
        $who = $person ? $owner : $name;
        [$title, $description] = match ($slug) {
            'home' => [new Message('Welcome to {name}', ['name' => $name]), new Message('The website of {name}.', ['name' => $who])],
            'about' => $person ? [new Message('About me'), new Message('Who {name} is and what they do.', ['name' => $owner])] : [new Message('About us'), new Message('Who {name} is and what we do.', ['name' => $name])],
            'contact' => [$person ? new Message('Contact me') : new Message('Contact us'), new Message('Send {name} a message.', ['name' => $who])],
            'privacy' => [new Message('Privacy notice'), new Message('What personal information this website collects and what is done with it.')],
            'terms' => [new Message('Terms of use'), new Message('The terms for using this website.')],
            default => [new Message('Accessibility statement'), new Message('How accessible this website is, and how to report a problem.')],
        };
        $templates = $this->templates($locale);
        $html = $this->twig->render(self::TEMPLATES . ($person ? 'person' : 'organization') . '/' . $templates . '/' . $slug . '.html.twig', [
            'site_name' => $this->site->name,
            'owner_name' => $owner,
            'site_url' => is_string($stored['url'] ?? null) ? $stored['url'] : '',
            'date' => (string) new IntlDateFormatter(str_replace('-', '_', $locale), IntlDateFormatter::LONG, IntlDateFormatter::NONE, $this->site->defaultTimezone)->format($this->clock->now()),
            'links' => $this->links($locale),
            'analytics' => $this->modules->has('analytics'),
            'text_messages' => $this->modules->has('sms'),
        ]);
        $blocks = [['type' => 'rich_text', 'version' => 1, 'data' => ['html' => $html]]];
        if ($slug === 'contact') {
            $blocks[] = ['type' => 'contact_form', 'version' => 1, 'data' => [
                'recipient' => is_string($stored['contact'] ?? null) ? $stored['contact'] : '',
                'subject' => mb_substr($this->translations->translate(new Message('Message from the website of {name}', ['name' => $name]), [], $locale), 0, 100),
            ]];
        }

        return new PageContent(
            mb_substr($this->translations->translate($title, [], $locale), 0, PageContent::TITLE_MAX_LENGTH),
            $this->address($slug, $locale),
            null,
            mb_substr($this->translations->translate($description, [], $locale), 0, PageContent::DESCRIPTION_MAX_LENGTH),
            $blocks,
        );
    }

    private function address(string $slug, string $locale): string
    {
        return self::ADDRESSES[$this->templates($locale)][$slug] ?? $slug;
    }

    /**
     * @return array<string, string> slug => the page's address in the language
     */
    private function links(string $locale): array
    {
        $prefix = $this->addresses->prefixOf($locale);
        $links = [];
        foreach (self::SLUGS as $slug) {
            $links[$slug] = $slug === 'home' ? $this->addresses->homeUrl($locale) : $prefix . '/' . $this->address($slug, $locale);
        }

        return $links;
    }

    /**
     * The templates a language uses: its own, the closest language's, or British English.
     */
    private function templates(string $locale): string
    {
        return LocaleFallback::chain($locale, self::LOCALES, self::LOCALES[0])[0];
    }

    /**
     * @param array<string, Page> $pages
     */
    private function remember(array $pages): void
    {
        $stored = $this->stored();
        $stored['pages'] = array_map(static fn (Page $page): int => (int) $page->id(), $pages);
        $this->settings->set(self::SETTING, (string) json_encode($stored));
    }

    /**
     * @return array<string, Page> slug => page, for the starter pages that are still there
     */
    private function remembered(): array
    {
        $pages = [];
        $ids = $this->stored()['pages'] ?? [];
        foreach (is_array($ids) ? $ids : [] as $slug => $id) {
            $page = is_string($slug) && is_int($id) ? $this->pages->find($id) : null;
            if ($page !== null && in_array($slug, self::SLUGS, true)) {
                $pages[$slug] = $page;
            }
        }

        return $pages;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $stored = json_decode((string) $this->settings->get(self::SETTING), true);
        $values = [];
        foreach (is_array($stored) ? $stored : [] as $key => $value) {
            if (is_string($key)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
