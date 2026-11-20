<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * The tenant's `themes` settings (D26): which of the installed themes the sites may use (`allowed`; null = every
 * installed theme) and the theme new sites start with (`default`, which must be allowed). Whether the themes are
 * installed is checked when they are used: a site whose theme is missing falls back to the default theme.
 */
final readonly class TenantThemes
{
    public const string DEFAULT_THEME = 'default';

    private const string ID = '/^(?=.{1,64}$)[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    /**
     * @param list<non-empty-string>|null $allowed theme ids the sites may use; null = every installed theme
     * @param non-empty-string            $default the theme of new sites
     */
    public function __construct(
        public ?array $allowed = null,
        public string $default = self::DEFAULT_THEME,
    ) {
        if ($allowed !== null && !in_array($default, $allowed, true)) {
            throw new TenantConfigException(sprintf(
                'Tenant setting "themes.default" ("%s") must be one of "themes.allowed" (%s).',
                $default,
                $allowed === [] ? 'which is empty' : '"' . implode('", "', $allowed) . '"',
            ));
        }
    }

    /**
     * @param array<mixed> $section the `themes` section of `config/tenant.php`
     */
    public static function fromArray(array $section): self
    {
        $default = $section['default'] ?? self::DEFAULT_THEME;
        if (!is_string($default) || preg_match(self::ID, $default) !== 1) {
            throw new TenantConfigException('Tenant setting "themes.default" must be a theme id, for example "default".');
        }
        $allowed = $section['allowed'] ?? null;
        if ($allowed !== null) {
            if (!is_array($allowed) || !array_is_list($allowed) || array_any($allowed, static fn (mixed $id): bool => !is_string($id) || preg_match(self::ID, $id) !== 1)) {
                throw new TenantConfigException('Tenant setting "themes.allowed" must be a list of theme ids, for example [\'default\', \'acme-harbour\'], or null for every installed theme.');
            }
            /** @var list<non-empty-string> $allowed */
            $allowed = array_values(array_unique(array_filter($allowed, is_string(...))));
        }

        return new self($allowed, $default);
    }

    /**
     * Whether the sites may use the theme.
     */
    public function allows(string $id): bool
    {
        return $this->allowed === null || in_array($id, $this->allowed, true);
    }
}
