<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use SightPress\Core\Tenant\LocaleFallback;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Translates interface text from XLIFF catalogues (`<domain>.<locale>.xlf`). The en-GB text is the message id, so a
 * missing translation still reads well. A locale without a catalogue of its own uses the closest one
 * ({@see LocaleFallback}: `pt-BR` uses `pt-PT`, `en-US` uses `en-GB`), then en-GB (ADR-0007). The result is
 * formatted with ICU MessageFormat ({@see Message::format()}).
 */
final readonly class Translations
{
    public const string SOURCE_LOCALE = 'en-GB';

    /**
     * @param list<string> $locales the locales that have a catalogue
     */
    public function __construct(
        private TranslatorBagInterface $catalogues,
        private array $locales = [self::SOURCE_LOCALE],
        private string $fallbackLocale = self::SOURCE_LOCALE,
    ) {
    }

    /**
     * Loads every `<domain>.<locale>.xlf` file from the given folders.
     *
     * @param list<string> $directories
     */
    public static function fromDirectories(array $directories, string $fallbackLocale = self::SOURCE_LOCALE): self
    {
        $translator = new Translator($fallbackLocale);
        $translator->setFallbackLocales([$fallbackLocale]);
        $translator->addLoader('xlf', new XliffLoader());
        $locales = [$fallbackLocale];
        foreach ($directories as $directory) {
            $files = glob(rtrim($directory, '/') . '/*.*.xlf');
            foreach ($files === false ? [] : $files as $file) {
                [$domain, $locale] = explode('.', basename($file, '.xlf'), 2);
                $translator->addResource('xlf', $file, $locale, $domain);
                $locales[] = $locale;
            }
        }

        return new self($translator, array_values(array_unique($locales)), $fallbackLocale);
    }

    /**
     * The tenant's admin language, used when nothing else is chosen (en-GB unless the tenant says otherwise).
     */
    public function defaultLocale(): string
    {
        return $this->fallbackLocale;
    }

    /**
     * The locales interface text is translated into, the source first.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->locales;
    }

    /**
     * The locale whose catalogue is used for a viewer's locale.
     */
    public function catalogueLocale(string $locale): string
    {
        return LocaleFallback::chain($locale, $this->locales, $this->fallbackLocale)[0];
    }

    /**
     * @param array<string, mixed> $parameters added to the message's own; messages among them are translated too
     */
    public function translate(Message|LanguageName|string $message, array $parameters, string $locale, string $domain = 'messages'): string
    {
        if ($message instanceof LanguageName) {
            return $message->in($this->catalogueLocale($locale));
        }
        $message = Message::of($message);
        $values = [];
        foreach ([...$message->parameters, ...$parameters] as $name => $value) {
            $values[(string) $name] = match (true) {
                $value instanceof Message => $this->translate($value, [], $locale, $domain),
                $value instanceof LanguageName => $value->in($this->catalogueLocale($locale)),
                is_string($value), is_int($value), is_float($value), $value === null => $value,
                $value instanceof \Stringable => (string) $value,
                is_bool($value) => $value ? 1 : 0,
                default => '',
            };
        }
        $catalogue = $this->catalogueLocale($locale);
        $text = $this->catalogues->getCatalogue($catalogue)->get($message->text, $domain);
        $text = $values === [] ? $text : Message::format($text, $values, $catalogue);

        return $message->lowerFirst ? mb_lcfirst($text) : $text;
    }
}
