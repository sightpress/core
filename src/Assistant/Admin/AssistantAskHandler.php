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
use SightPress\Core\Assistant\Task\AskTask;
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
    public const array SCREENS = ['dashboard', 'page_editor'];

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
        $heading = $ask->field === null ? new Message('The assistant’s answer') : new Message('Suggestion for “{field}”', ['field' => new Message(self::FIELD_LABELS[$ask->field])]);
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
        if ($ask->field !== null) {
            $task = AssistantAccess::FIELD_TASKS[$ask->field];
            if ($page === null) {
                throw new AssistantError(AssistantError::INVALID_INPUT, new Message('Save the page first: the assistant reads the saved page.'));
            }

            return [$task, ['field' => $ask->field, 'title' => $page->title, 'summary' => $page->summary, 'text' => $page->text], self::locale($page->locale)];
        }
        if ($ask->question === '') {
            throw new AssistantError(AssistantError::INVALID_INPUT, new Message('Type your question for the assistant.'));
        }
        $input = ['question' => $ask->question];
        if ($ask->share && $page !== null) {
            $input['context'] = mb_substr("# {$page->title}\n\n" . ($page->summary === '' ? '' : "> {$page->summary}\n\n") . $page->text, 0, AskTask::MAX_CONTEXT);
        }

        return [AskTask::ID, $input, self::locale($this->replies->locale($request))];
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
            ]);
        }

        return $this->screens->render($request, '@assistant/result.html.twig', [
            'heading' => $heading,
            'ask' => $ask,
            'text' => $result->suggestion->text,
            'lang' => $locale,
            'notes' => $notes,
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
            'error' => $error->reason,
            'action' => self::PATH,
            'insert_action' => AssistantInsertHandler::PATH,
        ], $status);
    }

    /**
     * @return non-empty-string
     */
    private static function locale(string $locale): string
    {
        return $locale === '' ? 'en-GB' : $locale;
    }
}
