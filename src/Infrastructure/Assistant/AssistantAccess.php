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
     *
     * @return array{ask: bool, suggest: array<string, string>, page: int|null, locale: string|null, return: string}|null
     */
    public function panel(Actor $actor, string $return, ?int $pageId = null, ?string $locale = null, array $fields = []): ?array
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
        $ask = isset($tasks['ask']);
        if (!$ask && $suggest === []) {
            return null;
        }

        return ['ask' => $ask, 'suggest' => $suggest, 'page' => $pageId, 'locale' => $locale, 'return' => $return];
    }
}
