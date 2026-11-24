<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleException;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\Registrar\TranslationRegistrar;

/**
 * Collects a module's translation folder: XLIFF files named `<domain>.<locale>.xlf`, in the module's own domain
 * (`messages` is core's) and with the en-GB source.
 */
final readonly class TranslationCollector implements TranslationRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function add(string $directory): void
    {
        $directory = rtrim($directory, '/');
        if (!is_dir($directory)) {
            throw new ModuleException(sprintf('Module "%s" registers translations in %s, which is not a folder.', $this->module, $directory));
        }
        if (self::files($directory . '/messages.*.xlf') !== []) {
            throw new ModuleException(sprintf(
                'Module "%s" registers translations in the "messages" domain, which is core\'s. Name its files after its own domain, for example "%s.en-GB.xlf".',
                $this->module,
                str_replace('.', '_', $this->module),
            ));
        }
        if (self::files($directory . '/*.en-GB.xlf') === []) {
            throw new ModuleException(sprintf('Module "%s" registers translations in %s without an en-GB file, the source language.', $this->module, $directory));
        }

        $this->registry->addTranslations($this->module, $directory);
    }

    /**
     * @return list<string>
     */
    private static function files(string $pattern): array
    {
        $files = glob($pattern);

        return $files === false ? [] : $files;
    }
}
