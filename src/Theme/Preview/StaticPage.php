<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Preview;

/**
 * Turns the rendered preview page into a page that works as a static file, from `file://` or any static host:
 * every file of the site it uses (stylesheets, scripts, pictures, media, icons) gets a relative address in the
 * folder, the navbar's links jump to their sections, links to other pages of the throwaway site go nowhere (`#`),
 * forms can't send, and what changes on every request (CSRF tokens, CSP nonces) is fixed, so two runs give the same
 * bytes.
 */
final readonly class StaticPage
{
    /** What forms on the preview say instead of sending. */
    public const string FORM_NOTE = 'This is a preview: the form doesn\'t send anything.';

    private const string ATTRIBUTES = '/\s(href|src|poster|action|srcset)="([^"]*)"/';

    /**
     * @param string $base the site's own address (scheme and host); addresses that start with it are the site's too
     */
    public function __construct(private string $base = '')
    {
    }

    /**
     * Addresses of the site's own files the page uses, without query or fragment, in the order they appear.
     *
     * @return list<string>
     */
    public function files(string $html): array
    {
        $files = [];
        preg_match_all(self::ATTRIBUTES, $html, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $attribute, $value]) {
            if ($attribute === 'action' || self::isPageLink($html, $value) || $attribute === 'href' && !self::isFileLink($html, $value) && preg_match('#/[^/?\#]+\.[A-Za-z0-9]+(?:[?\#]|$)#', $value) !== 1) {
                continue;
            }
            foreach (self::candidates($attribute, html_entity_decode($value, ENT_QUOTES | ENT_HTML5)) as $url) {
                $path = $this->localPath($url);
                if ($path !== null && $path !== '/') {
                    $files[] = $path;
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Files a stylesheet uses through `url(…)`, resolved against the stylesheet's own address.
     *
     * @return list<string>
     */
    public function cssFiles(string $css, string $stylesheet): array
    {
        $files = [];
        preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $css, $matches);
        foreach ($matches[2] as $url) {
            $path = $this->resolve($url, $stylesheet);
            if ($path !== null) {
                $files[] = $path;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * The folder-relative file name a site address is saved as: `/assets/css/site.css` → `assets/css/site.css`,
     * `/` → `index.html`.
     */
    public static function fileName(string $path): string
    {
        $name = ltrim($path, '/');

        return $name === '' ? 'index.html' : $name;
    }

    /**
     * @param list<string>                             $saved    the files saved in the folder (site addresses)
     * @param list<array{id: string, heading: string}> $sections the page's sections, in order
     *                                                           ({@see PreviewContent::$sections})
     */
    public function rewrite(string $html, array $saved, array $sections): string
    {
        $saved = array_flip($saved);
        $html = (string) preg_replace_callback(
            self::ATTRIBUTES,
            function (array $match) use ($saved, $html): string {
                [, $attribute, $value] = $match;
                if ($attribute === 'action') {
                    return ' action="#"';
                }
                if (self::isPageLink($html, $value)) {
                    return $match[0];
                }
                $rewritten = [];
                foreach (self::candidates($attribute, html_entity_decode($value, ENT_QUOTES | ENT_HTML5)) as $url) {
                    $rewritten[$url] = $this->relative($url, $saved);
                }

                return sprintf(' %s="%s"', $attribute, htmlspecialchars(strtr(html_entity_decode($value, ENT_QUOTES | ENT_HTML5), $rewritten), ENT_QUOTES | ENT_HTML5));
            },
            $html,
        );
        $html = (string) preg_replace('/<link rel="manifest"[^>]*>\n?/', '', $html);
        $html = (string) preg_replace('/(<input type="hidden" name="csrf[^"]*" value=")[^"]*(")/', '$1preview$2', $html);
        $html = (string) preg_replace('/\snonce="[^"]*"/', ' nonce="preview"', $html);
        $html = (string) preg_replace('#(<form\b[^>]*\smethod="post"[^>]*>.*?)(</form>)#is', '$1<p class="form-note">' . htmlspecialchars(self::FORM_NOTE, ENT_QUOTES | ENT_HTML5) . '</p>$2', $html);
        foreach ($sections as $section) {
            $heading = '<h2 class="block-heading">' . htmlspecialchars($section['heading'], ENT_QUOTES | ENT_HTML5) . '</h2>';
            $position = strpos($html, $heading);
            if ($position !== false) {
                $html = substr_replace($html, '<h2 class="block-heading" id="' . htmlspecialchars($section['id'], ENT_QUOTES | ENT_HTML5) . '">', $position, strlen('<h2 class="block-heading">'));
            }
        }

        return $html;
    }

    /**
     * The same files with what the site picks at random replaced by what their contents give: each media folder
     * (`/media/<random key>/`) is named after the checksum of its first file, and the branding version (`?v=…`) is
     * `preview`. Two runs then give the same folder.
     *
     * @param array<string, string> $files contents by site address, in a fixed order
     *
     * @return array<string, string>
     */
    public function stabilise(array $files): array
    {
        $names = [];
        foreach ($files as $path => $contents) {
            if (preg_match('#^/media/([0-9a-f]{32})/#', $path, $key) === 1 && !isset($names[$key[1]])) {
                $names[$key[1]] = substr(hash('sha256', $contents), 0, 32);
            }
        }
        $replace = static fn (string $text): string => (string) preg_replace(
            '/([?&]v=)[0-9a-f]{12}\b/',
            '$1preview',
            strtr($text, $names),
        );
        $stable = [];
        foreach ($files as $path => $contents) {
            $stable[$replace($path)] = preg_match('/\.(?:css|js|svg|vtt|webmanifest|md)$|^\/$/', $path) === 1 ? $replace($contents) : $contents;
        }

        return $stable;
    }

    /**
     * A stylesheet with its `url(…)` addresses made relative to where it is saved.
     */
    public function rewriteCss(string $css, string $stylesheet): string
    {
        return (string) preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            function (array $match) use ($stylesheet): string {
                $path = $this->resolve($match[2], $stylesheet);
                if ($path === null) {
                    return $match[0];
                }
                $depth = substr_count(ltrim($stylesheet, '/'), '/');

                return sprintf('url(%s%s%s%s)', $match[1], str_repeat('../', $depth), ltrim($path, '/'), $match[1]);
            },
            $css,
        );
    }

    /**
     * @return list<string>
     */
    private static function candidates(string $attribute, string $value): array
    {
        if ($attribute !== 'srcset') {
            return [$value];
        }
        $urls = [];
        foreach (explode(',', $value) as $candidate) {
            $url = explode(' ', trim($candidate))[0];
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * A `<link rel="canonical">` or `rel="alternate"` names an address of the page for search engines; browsers don't
     * load it, so it stays as it is.
     */
    private static function isPageLink(string $html, string $value): bool
    {
        return preg_match('/<link\b[^>]*\brel="(?:canonical|alternate)"[^>]*\shref="' . preg_quote($value, '/') . '"/', $html) === 1;
    }

    /**
     * A `<link href>` loads a file (stylesheet, icon, manifest); an `<a href>` goes to a page unless its address
     * ends in a file name with an extension (a download).
     */
    private static function isFileLink(string $html, string $value): bool
    {
        return preg_match('/<link\b[^>]*\shref="' . preg_quote($value, '/') . '"/', $html) === 1;
    }

    /**
     * The path of an address on the site itself, or null for addresses elsewhere, fragments and the like.
     */
    private function localPath(string $url): ?string
    {
        if ($this->base !== '' && str_starts_with($url, $this->base . '/')) {
            $url = substr($url, strlen($this->base));
        }
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }
        $path = substr($url, 0, strcspn($url, '?#'));

        return $path === '' ? null : rawurldecode($path);
    }

    /**
     * @param array<string, int> $saved
     */
    private function relative(string $url, array $saved): string
    {
        $path = $this->localPath($url);
        if ($path === null) {
            return $url;
        }
        $fragment = strpos($url, '#');
        if (isset($saved[$path])) {
            return self::fileName($path);
        }
        if ($path === '/') {
            return $fragment === false ? 'index.html' : substr($url, $fragment);
        }

        return '#';
    }

    private function resolve(string $url, string $stylesheet): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, '#') || preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $url) === 1) {
            return null;
        }
        $url = substr($url, 0, strcspn($url, '?#'));
        if (str_starts_with($url, '/')) {
            return $url;
        }
        $parts = explode('/', dirname($stylesheet) . '/' . $url);
        $path = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($path);
            } elseif ($part !== '' && $part !== '.') {
                $path[] = $part;
            }
        }

        return '/' . implode('/', $path);
    }
}
