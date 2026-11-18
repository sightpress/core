<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Publish;

use SightPress\Core\Block\Contract\BlockContexts;
use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Block\Contract\BlockValidator;
use SightPress\Core\Block\Contract\InvalidBlocks;
use SightPress\Core\Block\Contract\MediaTexts;
use SightPress\Core\Media\Contract\MediaLibrary;
use SightPress\Core\Page\Contract\PublishIssue;
use SightPress\Core\Page\Contract\PublishReport;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Theme\Contract\PageColours;
use SightPress\Module\Block\A11ySeverity;

/**
 * The checks a page must pass before it is published, in every language it is published in (a translation that is a
 * draft of its own is checked when it is published).
 *
 * Accessibility (errors stop publishing, unless a rule says it is a warning): every block type's own rules (alt
 * text, link text, captions, transcripts …, with the texts a block left empty taken from the media library), blocks that are still valid, files that are published, the language
 * of the page, and what {@see HtmlChecks} finds in each block's HTML — the page title is the only `h1`, heading
 * levels don't skip, every picture has `alt`, language codes are real, and colours chosen in the content have 4.5:1
 * contrast in every colour mode of the theme.
 *
 * Search engines (warnings): a description, not longer than {@see DESCRIPTION_MAX} characters; a summary; a title no
 * other page uses in the same language.
 */
final readonly class PublishChecker
{
    public const int DESCRIPTION_MAX = 160;

    public function __construct(
        private BlockValidator $validator,
        private BlockRenderer $renderer,
        private BlockContexts $contexts,
        private MediaLibrary $media,
        private PageRepository $pages,
        private PageColours $colours,
        private Site $site,
        private MediaTexts $texts,
    ) {
    }

    /**
     * The checks of every language visitors will read when the page is published: translations that are drafts of
     * their own, and those in a language the site no longer has, wait. With `$locale`, only that translation.
     */
    public function check(Page $page, ?string $locale = null): PublishReport
    {
        $issues = [];
        foreach ($page->translations() as $translation) {
            $wanted = $locale === null
                ? !$translation->isDraft() && in_array($translation->locale(), $this->site->locales, true)
                : $translation->locale() === $locale;
            if ($wanted) {
                $issues = [...$issues, ...$this->translation($page, $translation)];
            }
        }

        return new PublishReport($issues);
    }

    /**
     * @return list<PublishIssue>
     */
    private function translation(Page $page, PageTranslation $translation): array
    {
        $locale = $translation->locale();
        if (!in_array($locale, $this->site->locales, true)) {
            return [$this->error($locale, 'page.language', sprintf('The site isn\'t written in "%s" any more. Remove this language from the page, or add it to the site.', $locale), '', ['3.1.1'])];
        }

        return [
            ...$this->blocks($translation),
            ...$this->seo($page, $translation),
        ];
    }

    /**
     * @return list<PublishIssue>
     */
    private function blocks(PageTranslation $translation): array
    {
        $locale = $translation->locale();
        $blocks = $this->texts->fill($translation->blocks(), $locale);
        try {
            $validated = $this->validator->validate($blocks);
        } catch (InvalidBlocks $invalid) {
            return array_map(
                fn ($violation): PublishIssue => $this->error($locale, 'blocks.invalid', $violation->message, '/blocks' . $violation->pointer),
                $invalid->violations,
            );
        }

        $issues = [];
        foreach ($validated->issues as $issue) {
            $issues[] = new PublishIssue($locale, $issue->issue->severity, PublishIssue::ACCESSIBILITY, $issue->issue->rule, $issue->issue->message, '/blocks' . $issue->fieldPointer(), $issue->issue->wcag);
        }
        $files = $this->media->files($validated->mediaIds);
        foreach ($validated->media as $reference) {
            if (($files[$reference['id']] ?? null)?->isPublished() !== true) {
                $issues[] = $this->error($locale, 'media.unpublished', 'This file isn\'t published in the media library, so visitors won\'t see it. Publish it there first.', '/blocks' . $reference['pointer']);
            }
        }

        $context = $this->contexts->create($locale, 'https://' . ($this->site->domains[0] ?? 'localhost'));
        $modes = $this->colours->pageColours();
        $heading = 1;
        foreach ($validated->blocks as $index => $block) {
            foreach (HtmlChecks::check($this->renderer->html([$block], $context), $heading, $modes) as $found) {
                $issues[] = new PublishIssue($locale, $found['severity'], PublishIssue::ACCESSIBILITY, $found['rule'], $found['message'], '/blocks/' . $index, $found['wcag']);
            }
        }

        return $issues;
    }

    /**
     * @return list<PublishIssue>
     */
    private function seo(Page $page, PageTranslation $translation): array
    {
        $locale = $translation->locale();
        $issues = [];
        $description = $translation->description();
        if ($description === null || trim($description) === '') {
            $issues[] = $this->warning($locale, 'seo.description_missing', 'Add a description for search engines: one or two sentences about the page.', '/description');
        } elseif (mb_strlen($description) > self::DESCRIPTION_MAX) {
            $issues[] = $this->warning($locale, 'seo.description_long', sprintf('Search engines show about %d characters of the description; this one has %d. Shorten it.', self::DESCRIPTION_MAX, mb_strlen($description)), '/description');
        }
        $summary = $translation->summary();
        if ($summary === null || trim($summary) === '') {
            $issues[] = $this->warning($locale, 'seo.summary_missing', 'Add a summary: it is shown in lists of pages and read by AI search engines.', '/summary');
        }
        if ($this->pages->titleTaken($locale, $translation->title(), (int) $page->id())) {
            $issues[] = $this->warning($locale, 'seo.title_duplicate', sprintf('Another page is also called "%s". Give this page a title of its own, so people can tell them apart in search results.', $translation->title()), '/title');
        }

        return $issues;
    }

    /**
     * @param list<string> $wcag
     */
    private function error(string $locale, string $rule, string $message, string $pointer, array $wcag = []): PublishIssue
    {
        return new PublishIssue($locale, A11ySeverity::Error, PublishIssue::ACCESSIBILITY, $rule, $message, $pointer, $wcag);
    }

    private function warning(string $locale, string $rule, string $message, string $pointer): PublishIssue
    {
        return new PublishIssue($locale, A11ySeverity::Warning, PublishIssue::SEO, $rule, $message, $pointer);
    }
}
