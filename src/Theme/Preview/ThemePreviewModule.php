<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Preview;

use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;

/**
 * Loaded only by `theme:preview`, on its throwaway site: puts the preview page's Download button in the hero
 * ({@see PreviewHero}). Never installed on a real tenant.
 */
final readonly class ThemePreviewModule implements ModuleInterface
{
    public const string ID = 'theme_preview';

    /**
     * @param array{label: string, accessible_name: string, url: string} $button
     */
    public function __construct(private array $button)
    {
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::ID, '0.1.0', required: true);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $button = $this->button;
        $registrar->services()->factory(PreviewHero::class, static fn (): PreviewHero => new PreviewHero($button));
        $registrar->subscribers()->listen(PublicPageRendering::class, PreviewHero::class);
    }
}
