<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use MessageFormatter;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Translates interface text. The en-GB text is the message id, so a missing translation still reads well; the
 * result is formatted with ICU MessageFormat, so `{count, plural, one {# page} other {# pages}}` and `{name}` work
 * in every language.
 */
final readonly class Translations
{
    public function __construct(private TranslatorBagInterface $catalogues)
    {
    }

    /**
     * Loads every `<domain>.<locale>.php` file (returning an array of id => text) from the given folders.
     *
     * @param list<string> $directories
     */
    public static function fromDirectories(array $directories, string $fallbackLocale = 'en-GB'): self
    {
        $translator = new Translator($fallbackLocale);
        $translator->setFallbackLocales([$fallbackLocale]);
        $translator->addLoader('php', new PhpFileLoader());

        foreach ($directories as $directory) {
            $files = glob(rtrim($directory, '/') . '/*.*.php');
            foreach ($files === false ? [] : $files as $file) {
                [$domain, $locale] = explode('.', basename($file, '.php'), 2);
                $translator->addResource('php', $file, $locale, $domain);
            }
        }

        return new self($translator);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function translate(string $message, array $parameters, string $locale, string $domain = 'messages'): string
    {
        $text = $this->catalogues->getCatalogue($locale)->get($message, $domain);
        if ($parameters === []) {
            return $text;
        }

        $formatted = MessageFormatter::formatMessage(str_replace('-', '_', $locale), $text, $parameters);

        return $formatted === false ? $text : $formatted;
    }
}
