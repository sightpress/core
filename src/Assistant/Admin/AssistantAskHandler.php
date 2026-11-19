<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Assistant\Assistant;
use SightPress\Core\Assistant\AssistantError;
use SightPress\Core\Assistant\AssistantResult;
use SightPress\Core\Assistant\SiteAssistantContext;
use SightPress\Core\Assistant\Task\AltTextTask;
use SightPress\Core\Assistant\Task\AskTask;
use SightPress\Core\Assistant\Task\DraftPageTask;
use SightPress\Core\Assistant\Task\ExplainCheckTask;
use SightPress\Core\Assistant\Task\PageInput;
use SightPress\Core\Assistant\Task\RewriteTask;
use SightPress\Core\Infrastructure\Assistant\AssistantAccess;
use SightPress\Core\Page\Contract\PageText;
use SightPress\Core\Page\Contract\PageTexts;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;

/**
 * `POST /admin/assistant/ask` (A-3): a question for the assistant ("Ask the assistant", optionally sharing the saved
 * page) or a suggestion for a field (the Suggest buttons: `field` names it, and the task reads the saved page). The
 * answer is only a suggestion: nothing is changed. With `js/assistant.js` it is JSON for the panel; without, a page
 * with the suggestion and Insert, Try again and Discard (or what went wrong).
 */
final readonly class AssistantAskHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/assistant/ask';

    /** The screens the panel is on, which the assistant is told about. */
    public const array SCREENS = ['dashboard', 'page_editor', 'media_item'];

    /** What each field a Suggest button fills is called, as on the page editor. */
    public const array FIELD_LABELS = [
        'summary' => 'Summary',
        'seo_title' => 'Title in search results',
        'description' => 'Description for search engines',
        'alt' => 'Alt text',
    ];

    public function __construct(
        private AdminScreens $screens,
        private Assistant $assistant,
        private PageTexts $pages,
        private AssistantReplies $replies,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        $body = $request->getParsedBody();
        $ask = AssistantAsk::fromBody(is_array($body) ? $body : []);
        $page = $ask->pageId === null ? null : $this->pages->forEditing($actor, $ask->pageId, $ask->locale ?? $this->site->defaultLocale);
        $heading = $ask->field !== null
            ? new Message('Suggestion for “{field}”', ['field' => new Message(self::FIELD_LABELS[$ask->field])])
            : match ($ask->task) {
                RewriteTask::ID => new Message('Rewritten text'),
                DraftPageTask::ID => new Message('Draft of the page'),
                ExplainCheckTask::ID => new Message('About this check'),
                default => new Message('The assistant’s answer'),
            };
        try {
            [$task, $input, $locale] = $this->request($ask, $page, $actor, $request);
            $result = $this->assistant->run($task, $input, new SiteAssistantContext($locale, $actor, $ask->screen), $actor);
        } catch (AssistantError $error) {
            return $this->failed($request, $ask, $heading, $error);
        }

        return $this->answered($request, $ask, $heading, $result, $locale);
    }

    /**
     * The task, its input and the language of the answer.
     *
     * @return array{non-empty-string, array<string, mixed>, non-empty-string}
     *
     * @throws AssistantError
     */
    private function request(AssistantAsk $ask, ?PageText $page, Actor $actor, ServerRequestInterface $request): array
    {
        $admin = self::locale($this->replies->locale($request));
        if ($ask->field === 'alt') {
            $locale = $this->site->locales[$ask->altIndex ?? -1] ?? null;
            if ($ask->mediaId === null || $locale === null) {
                throw new AssistantError(AssistantError::INVALID_INPUT, new Message('The assistant couldn’t use what was sent.'));
            }

            return [AltTextTask::ID, ['field' => 'alt', 'media' => $ask->mediaId], self::locale($locale)];
        }
        if ($ask->field !== null) {
            if ($page === null) {
                throw new AssistantError(AssistantError::INVALID_INPUT, new Message('Save the page first: the assistant reads the saved page.'));
            }

            return [AssistantAccess::FIELD_TASKS[$ask->field], ['field' => $ask->field, 'title' => $page->title, 'summary' => $page->summary, 'text' => mb_substr($page->text, 0, PageInput::MAX_TEXT)], self::locale($page->locale)];
        }
        $pageLocale = self::locale($page->locale ?? $admin);

        return match ($ask->task) {
            RewriteTask::ID => $ask->text === ''
                ? throw new AssistantError(AssistantError::INVALID_INPUT, new Message('Paste the text the assistant should rewrite.'))
                : [RewriteTask::ID, ['text' => $ask->text, 'style' => isset(RewriteTask::STYLES[$ask->style]) ? $ask->style : 'simpler'], $pageLocale],
            DraftPageTask::ID => $ask->question === ''
                ? throw new AssistantError(AssistantError::INVALID_INPUT, new Message('Say what the page should be about.'))
                : [DraftPageTask::ID, ['brief' => mb_substr($ask->question, 0, DraftPageTask::MAX_BRIEF)] + ($page === null ? [] : ['title' => $page->title]), $pageLocale],
            ExplainCheckTask::ID => $ask->text === ''
                ? throw new AssistantError(AssistantError::INVALID_INPUT, new Message('The assistant couldn’t use what was sent.'))
                : [ExplainCheckTask::ID, ['check' => mb_substr($ask->text, 0, ExplainCheckTask::MAX_CHECK)] + ($page === null ? [] : ['title' => $page->title]), $admin],
            default => $this->question($ask, $page, $admin),
        };
    }

    /**
     * @return array{non-empty-string, array<string, mixed>, non-empty-string}
     *
     * @throws AssistantError
     */
    private function question(AssistantAsk $ask, ?PageText $page, string $locale): array
    {
        if ($ask->question === '') {
            throw new AssistantError(AssistantError::INVALID_INPUT, new Message('Type your question for the assistant.'));
        }
        $input = ['question' => $ask->question];
        if ($ask->share && $page !== null) {
            $input['context'] = mb_substr("# {$page->title}\n\n" . ($page->summary === '' ? '' : "> {$page->summary}\n\n") . $page->text, 0, AskTask::MAX_CONTEXT);
        }

        return [AskTask::ID, $input, self::locale($locale)];
    }

    private function answered(ServerRequestInterface $request, AssistantAsk $ask, Message $heading, AssistantResult $result, string $locale): ResponseInterface
    {
        $notes = [];
        if ($result->truncated) {
            $notes[] = new Message('The answer was cut short because it was too long. Check its end.');
        }
        if ($result->budget->isNearlyUsedUp()) {
            $notes[] = new Message('This site has used {percent}% of this month’s AI assistant budget.', ['percent' => $result->budget->percent()]);
        }
        if (AssistantReplies::wantsJson($request)) {
            return $this->replies->json($request, [
                'ok' => true,
                'heading' => $this->replies->text($request, $heading),
                'text' => $result->suggestion->text,
                'lang' => $locale,
                'notes' => array_map(fn (Message $note): string => $this->replies->text($request, $note), $notes),
                'status' => $this->replies->text($request, new Message('Suggestion ready')),
                'insert' => self::insert($ask, $result),
            ]);
        }

        return $this->screens->render($request, '@assistant/result.html.twig', [
            'heading' => $heading,
            'ask' => $ask,
            'text' => $result->suggestion->text,
            'lang' => $locale,
            'notes' => $notes,
            'insert' => self::insert($ask, $result),
            'error' => null,
            'action' => self::PATH,
            'insert_action' => AssistantInsertHandler::PATH,
        ]);
    }

    private function failed(ServerRequestInterface $request, AssistantAsk $ask, Message $heading, AssistantError $error): ResponseInterface
    {
        $status = match ($error->outcome) {
            AssistantError::NOT_ALLOWED => 403,
            AssistantError::TOO_MANY, AssistantError::BUDGET => 429,
            AssistantError::INVALID_INPUT, AssistantError::UNKNOWN_TASK, AssistantError::NO_VISION => 422,
            default => 503,
        };
        if (AssistantReplies::wantsJson($request)) {
            return $this->replies->json($request, ['ok' => false, 'error' => $this->replies->text($request, $error->reason)], $status);
        }

        return $this->screens->render($request, '@assistant/result.html.twig', [
            'heading' => $heading,
            'ask' => $ask,
            'text' => null,
            'lang' => null,
            'notes' => [],
            'insert' => null,
            'error' => $error->reason,
            'action' => self::PATH,
            'insert_action' => AssistantInsertHandler::PATH,
        ], $status);
    }

    /**
     * What Insert puts where: a field's suggestion, or a draft's blocks (added to the end of the page); null when the
     * answer only gets copied.
     *
     * @return array{field: string, text: string}|null
     */
    private static function insert(AssistantAsk $ask, AssistantResult $result): ?array
    {
        $field = $ask->fieldName();
        if ($field !== null) {
            return ['field' => $field, 'text' => $result->suggestion->text];
        }
        $blocks = $result->suggestion->fields['blocks'] ?? null;

        return $ask->task === DraftPageTask::ID && is_string($blocks) && $ask->pageId !== null ? ['field' => 'blocks', 'text' => $blocks] : null;
    }

    /**
     * @return non-empty-string
     */
    private static function locale(string $locale): string
    {
        return $locale === '' ? 'en-GB' : $locale;
    }
}
