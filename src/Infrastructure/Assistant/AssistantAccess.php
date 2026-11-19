<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Assistant;

use Psr\Container\ContainerInterface;
use SightPress\Core\Assistant\Assistant;
use SightPress\Core\Assistant\AssistantModule;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Rbac\Contract\Actor;

/**
 * Whether the AI assistant panel and its Suggest buttons are shown to a person (A-3), for screens of other modules
 * (the dashboard, the page editor): only while the assistant module is on for the site, it is set up, and the person
 * may use it. Shared code, so those modules don't depend on the optional assistant module (ADR-0009).
 */
final readonly class AssistantAccess
{
    /** The field a Suggest button fills => the task that suggests it. */
    public const array FIELD_TASKS = [
        'summary' => 'summary',
        'seo_title' => 'seo_meta',
        'description' => 'seo_meta',
        'alt' => 'alt_text',
    ];

    /** The tasks the panel's own forms offer, in their order. */
    public const array PANEL_TASKS = ['ask', 'rewrite', 'draft_page', 'explain_check'];

    /** Panel tasks only offered on a page's screen. */
    public const array PAGE_TASKS = ['draft_page', 'explain_check'];

    public function __construct(
        private ContainerInterface $container,
        private ModuleRegistry $registry,
    ) {
    }

    /**
     * The panel for a screen, or null when there is nothing the person may ask.
     *
     * @param string           $return the screen's path, to come back to
     * @param list<string>     $fields the fields of the screen that have a Suggest button when their task is there
     * @param int|null         $mediaId the file of the media library the screen is about
     *
     * @return array{ask: bool, tasks: list<string>, suggest: array<string, string>, page: int|null, locale: string|null, media: int|null, return: string}|null
     */
    public function panel(Actor $actor, string $return, ?int $pageId = null, ?string $locale = null, array $fields = [], ?int $mediaId = null): ?array
    {
        if (!$this->registry->has(AssistantModule::ID)) {
            return null;
        }
        $tasks = Services::get($this->container, Assistant::class)->tasksFor($actor);
        $suggest = [];
        foreach ($fields as $field) {
            $task = self::FIELD_TASKS[$field] ?? null;
            if ($task !== null && isset($tasks[$task])) {
                $suggest[$field] = $task;
            }
        }
        $offered = array_values(array_filter(self::PANEL_TASKS, static fn (string $task): bool => isset($tasks[$task]) && ($pageId !== null || !in_array($task, self::PAGE_TASKS, true))));
        if ($offered === [] && $suggest === []) {
            return null;
        }

        return ['ask' => in_array('ask', $offered, true), 'tasks' => $offered, 'suggest' => $suggest, 'page' => $pageId, 'locale' => $locale, 'media' => $mediaId, 'return' => $return];
    }
}
