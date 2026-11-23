<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Command;

use Doctrine\ORM\EntityManagerInterface;
use FilesystemIterator;
use League\Flysystem\FilesystemException;
use Psr\Container\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SightPress\Core\Cli\ConsoleCommandInput;
use SightPress\Core\Cli\TakesPaths;
use SightPress\Core\Http\Upload\IniSize;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Storage\StorageException;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\ImageProcessor;
use SightPress\Core\Media\MediaEditor;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;
use SplFileInfo;

/**
 * `site:media:import <site> <path>… [--recursive] [--publish] [--alt=] [--locale=] [--memory-limit=]`: adds files from
 * the server's disk to a site's media library. No size limit applies (D40). The type allow-list, the safety checks and
 * dedupe do. It keeps going after a refusal, lists every result, and exits with an error if anything was refused.
 * Hidden files and symbolic links to folders are skipped when walking a folder.
 */
final readonly class SiteMediaImportCommand implements TakesPaths
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    public function name(): string
    {
        return 'site:media:import';
    }

    public function description(): string
    {
        return 'Adds files of any size from this server to a site\'s media library.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('site', 'Slug of the site:', required: true),
            new CommandParameter('recursive', 'Also import the files inside folders.', CommandParameterType::Flag),
            new CommandParameter('publish', 'Publish the new files straight away.', CommandParameterType::Flag),
            new CommandParameter('alt', 'Alt text for the new pictures.', CommandParameterType::Option, ask: false),
            new CommandParameter('locale', 'Language of the alt text. The default is the site\'s main language.', CommandParameterType::Option, ask: false),
            new CommandParameter('memory-limit', 'Memory for this run, for very large pictures, for example 2G.', CommandParameterType::Option, ask: false),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = trim((string) $input->argument('site'));
        $site = $this->sites->find($slug);
        if ($site === null || $site->status === SiteStatus::Deleted) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }
        $locale = $input->option('locale') ?? $site->defaultLocale;
        if (!in_array($locale, $site->locales, true)) {
            $output->error(sprintf('The site "%s" isn\'t written in "%s".', $slug, $locale));

            return self::FAILURE;
        }
        $memory = $input->option('memory-limit');
        $memory = $memory === null ? null : trim($memory);
        if ($memory !== null && (preg_match('/^(?:-1|[1-9]\d*[KMG]?)$/i', $memory) !== 1 || ini_set('memory_limit', $memory) === false)) {
            $output->error('--memory-limit must be a size such as 512M or 2G, or -1 for no limit, and at least what this run already uses.');

            return self::FAILURE;
        }
        $bytes = $memory === '-1' ? -1 : ($memory === null ? null : IniSize::bytes($memory));

        $container = $this->containers->forSite($site);
        $maxPixels = self::maxPixels($bytes, Services::get($container, MediaLimits::class));
        $counts = ['imported' => 0, 'present' => 0, 'refused' => 0];
        $paths = $input instanceof ConsoleCommandInput ? $input->paths() : [];
        foreach ($paths as $path) {
            foreach (self::files($path, $input->flag('recursive')) as [$file, $problem]) {
                if ($problem !== null) {
                    ++$counts['refused'];
                    $output->error(sprintf('Refused: %s: %s', $file, $problem));
                    continue;
                }
                $this->importOne($file, $site, $container, $input, $locale, $maxPixels, $counts, $output);
            }
        }
        $output->line(sprintf('Imported: %d. Already present: %d. Refused: %d.', $counts['imported'], $counts['present'], $counts['refused']));

        return $counts['refused'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array{imported: int, present: int, refused: int} $counts
     */
    private function importOne(string $file, Site $site, ContainerInterface $container, CommandInput $input, string $locale, int $maxPixels, array &$counts, CommandOutput $output): void
    {
        try {
            $result = Services::get($container, MediaUploader::class)->import($file, basename($file), $maxPixels);
            if ($result->duplicate) {
                ++$counts['present'];
                $output->line('Already present: ' . $file);
            } else {
                $this->describe($result->media, $container, $input, $locale);
                ++$counts['imported'];
                $output->line('Imported: ' . $file);
            }
        } catch (MediaException $exception) {
            ++$counts['refused'];
            $output->error(sprintf('Refused: %s: %s', $file, $exception->getMessage()));
        } catch (FilesystemException | StorageException) {
            ++$counts['refused'];
            $output->error(sprintf('Refused: %s: The site\'s storage couldn\'t keep this file. Check it with site:storage:s3 %s.', $file, $site->slug));
        } finally {
            // A long import must not keep every file's row in memory.
            Services::get($container, EntityManagerInterface::class)->clear();
        }
    }

    private function describe(Media $media, ContainerInterface $container, CommandInput $input, string $locale): void
    {
        $alt = $input->option('alt');
        $publish = $input->flag('publish');
        if ($alt === null && !$publish) {
            return;
        }
        Services::get($container, MediaEditor::class)->update(
            $media,
            $alt === null ? [] : [$locale => ['alt' => $alt, 'caption' => null]],
            null,
            $publish ? true : null,
            null,
        );
    }

    /**
     * The largest picture this run can decode: the site's limit, or what `--memory-limit` makes room for.
     */
    private static function maxPixels(?int $memory, MediaLimits $limits): int
    {
        return match (true) {
            $memory === null => $limits->imagePixels,
            $memory < 0 => PHP_INT_MAX,
            default => max($limits->imagePixels, (int) floor($memory / ImageProcessor::BYTES_PER_PIXEL)),
        };
    }

    /**
     * Every file under a path, in a stable order, each with what is wrong with it.
     *
     * @return iterable<array{string, ?string}>
     */
    private static function files(string $path, bool $recursive): iterable
    {
        if (is_file($path)) {
            yield [$path, null];

            return;
        }
        if (!is_dir($path)) {
            yield [$path, 'There is no such file or folder.'];

            return;
        }
        if (!$recursive) {
            yield [$path, 'This is a folder. Add --recursive to import what is inside it.'];

            return;
        }
        $found = [];
        $walker = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($walker as $item) {
            /** @var SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen(rtrim($path, '/')) + 1);
            if ($item->isFile() && preg_match('#(^|/)\.#', $relative) !== 1) {
                $found[] = $item->getPathname();
            }
        }
        sort($found);
        foreach ($found as $file) {
            yield [$file, null];
        }
    }
}
