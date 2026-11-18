<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use ResourceBundle;
use SightPress\Core\Tenant\LocaleCode;
use Stringable;

/**
 * A language's name as a value in a {@see Message} or a template: written in the language the text is shown in
 * ("European Portuguese" in English, "portugués de Europa" in Spanish). Cast to a string, it is the en-GB name.
 */
final readonly class LanguageName implements Stringable
{
    public function __construct(public string $locale)
    {
    }

    /**
     * The name in another language, as ICU writes it, with a capital first letter.
     */
    public function in(string $language): string
    {
        $icu = str_replace('-', '_', $this->locale);
        $in = str_replace('-', '_', $language);
        $name = null;
        foreach (array_unique([$in, (string) \Locale::getPrimaryLanguage($in)]) as $bundle) {
            $languages = ResourceBundle::create($bundle, 'ICUDATA-lang')?->get('Languages');
            $found = $languages instanceof ResourceBundle ? $languages->get($icu) : null;
            $name ??= is_string($found) && $found !== '' ? $found : null;
        }
        $name ??= (string) \Locale::getDisplayName($icu, $in);

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    public function __toString(): string
    {
        return LocaleCode::describe($this->locale);
    }
}
