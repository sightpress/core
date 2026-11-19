<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Help;

/**
 * Builds the AI assistant's help index (A-5) from the `docs` repository's Markdown: one chunk per section (the page
 * title, the section's heading, its address on the documentation site and its text, at most {@see MAX_TEXT}
 * characters), and the permissions a person needs for the screens and commands it talks about
 * ({@see SCREENS}; what a page's introduction needs, all of its parts need), so nobody is pointed at a screen they
 * can't open. Pages under `adr/` are left out, like on the
 * site. The same Markdown always gives the same index, byte for byte.
 */
final class HelpIndexBuilder
{
    public const string BASE_URL = 'https://docs.sightpress.org/latest/';

    public const int MAX_TEXT = 1500;

    /** Words or addresses in a chunk => the permission a person needs to use what it describes. */
    public const array SCREENS = [
        '/admin/features' => 'module.manage', '**Features**' => 'module.manage', '*Features*' => 'module.manage',
        '/admin/users' => 'user.manage', '**People**' => 'user.manage',
        '**Invite someone**' => 'user.invite',
        '/admin/menus' => 'menu.manage', '**Menus**' => 'menu.manage',
        '/admin/settings' => 'settings.manage', '**Site settings**' => 'settings.manage', '**Branding**' => 'settings.manage',
        '/admin/audit' => 'audit.view', '**Audit log**' => 'audit.view',
        '/admin/assistant' => 'assistant.manage', '**AI assistant**' => 'assistant.manage',
        '/admin/analytics' => 'analytics.view', '**Analytics**' => 'analytics.view', '*Analytics*' => 'analytics.view',
        '/admin/redirects' => 'page.edit.any', '**Redirects**' => 'page.edit.any',
        '/admin/media' => 'media.upload', '**Media**' => 'media.upload',
        'Features screen' => 'module.manage', 'Analytics screen' => 'analytics.view', 'People screen' => 'user.manage',
        'Menus screen' => 'menu.manage', 'Audit log' => 'audit.view', 'Site settings' => 'settings.manage',
        'AI assistant screen' => 'assistant.manage',
        'bin/sightpress' => 'settings.manage',
    ];

    /**
     * @return list<array{id: string, page: string, heading: string, url: string, text: string, needs: list<string>}>
     */
    public function build(string $docsDirectory): array
    {
        $root = rtrim($docsDirectory, '/');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if (str_ends_with($path, '.md') && !str_starts_with($path, 'adr/')) {
                $files[] = $path;
            }
        }
        sort($files);
        $chunks = [];
        foreach ($files as $path) {
            $chunks = [...$chunks, ...$this->page($path, (string) file_get_contents($root . '/' . $path))];
        }

        return $chunks;
    }

    /**
     * @return list<array{id: string, page: string, heading: string, url: string, text: string, needs: list<string>}>
     */
    public function page(string $path, string $markdown): array
    {
        $address = self::BASE_URL . (string) preg_replace('~(^|/)index\.md$|\.md$~', '$1', $path);
        $address = str_ends_with($address, '/') ? $address : $address . '/';
        $title = '';
        /** @var list<string> $headings */
        $headings = [''];
        /** @var list<list<string>> $bodies */
        $bodies = [[]];
        $fenced = false;
        $lines = preg_split('/\R/', $markdown);
        foreach ($lines === false ? [] : $lines as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $fenced = !$fenced;
            }
            if (!$fenced && preg_match('/^(#{1,3})\s+(.+?)\s*#*\s*$/', $line, $match) === 1) {
                if ($match[1] === '#' && $title === '') {
                    $title = $match[2];
                    continue;
                }
                $headings[] = $match[2];
                $bodies[] = [];
                continue;
            }
            $bodies[count($bodies) - 1][] = $line;
        }
        $chunks = [];
        $page = [];
        foreach ($headings as $index => $heading) {
            $text = trim(implode("\n", $bodies[$index]));
            if ($text === '') {
                continue;
            }
            // What the page's introduction needs, every part of the page needs.
            $needs = $page;
            foreach (self::SCREENS as $words => $permission) {
                if (str_contains($text, $words)) {
                    $needs[$permission] = true;
                }
            }
            if ($heading === '') {
                $page = $needs;
            }
            $needs = array_keys($needs);
            sort($needs);
            $chunks[] = [
                'id' => substr(hash('sha256', $path . '#' . $index), 0, 12),
                'page' => $title === '' ? $path : $title,
                'heading' => $heading,
                'url' => $address . ($heading === '' ? '' : '#' . self::anchor($heading)),
                'text' => mb_substr((string) preg_replace("/\n{3,}/", "\n\n", $text), 0, self::MAX_TEXT),
                'needs' => $needs,
            ];
        }

        return $chunks;
    }

    /**
     * The id the documentation site gives a heading: lower case, words joined by hyphens, punctuation left out.
     */
    public static function anchor(string $heading): string
    {
        $text = mb_strtolower(strip_tags((string) preg_replace('/[`*_]/', '', $heading)));
        $text = (string) preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $text);

        return trim((string) preg_replace('/[\s-]+/u', '-', trim($text)), '-');
    }

    /**
     * @param list<array<string, mixed>> $chunks
     */
    public static function json(array $chunks): string
    {
        return json_encode(['version' => 1, 'chunks' => $chunks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}
