<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Assistant\Contract\AssistantUsed;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Infrastructure\Assistant\AssistantExtensions;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\RoleGrants;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Assistant\AssistantContext;
use SightPress\Module\Assistant\AssistantMessage;
use SightPress\Module\Assistant\AssistantRequest;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\AssistantTask;
use SightPress\Module\Assistant\TokenUsage;
use SightPress\Module\Exception\AssistantException;
use SightPress\Module\Exception\InvalidValueException;

/**
 * Runs an assistant task for a person (ADR-0010, ADR-0040): checks they may (`assistant.use`, the task's own
 * permission and one of the site's assistant roles), that the assistant is set up, their rate limit
 * ({@see RATE_LIMIT}) and the site's monthly budget; validates the input, sends the task's prompt to the provider
 * and lets the task check the answer. Every call that reaches the provider is counted in `assistant_usage` and
 * audited ({@see AssistantUsed}), whether it worked or not. The result is only ever a suggestion: nothing here
 * changes the site.
 *
 * Without an actor (the console) the permission, role and rate limit checks don't apply; the budget still does.
 */
final readonly class Assistant
{
    public const string RATE_LIMIT = 'assistant';

    public const string TEST = 'connection_test';

    public const int MAX_OUTPUT_TOKENS = 2000;

    public function __construct(
        private AssistantSetup $setup,
        private AssistantExtensions $extensions,
        private AssistantBudget $budget,
        private AssistantUsageStore $usage,
        private RateLimiters $limiters,
        private RoleGrants $roles,
        private AssistantSettings $settings,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private Site $site,
    ) {
    }

    /**
     * The tasks the person may use with the current model (vision tasks only when it can see pictures).
     *
     * @return array<non-empty-string, AssistantTask>
     */
    public function tasksFor(?Actor $actor): array
    {
        $configuration = $this->setup->configuration();
        if (!$configuration->isReady() || !$this->mayUse($actor)) {
            return [];
        }
        $model = $configuration->model;
        $vision = $model !== null && ($configuration->provider?->capabilities($model)->vision ?? false);

        return array_filter(
            $this->extensions->tasks(),
            static fn (AssistantTask $task): bool => ($actor === null || $actor->has($task->permission())) && (!$task->needsVision() || $vision),
        );
    }

    /**
     * Whether the person may use the assistant at all (permission and role), whatever the task.
     */
    public function mayUse(?Actor $actor): bool
    {
        if ($actor === null) {
            return true;
        }
        if (!$actor->has(AssistantModule::USE) || $actor->userId === null) {
            return false;
        }
        $role = $this->roles->roleOf($actor->userId)?->name;

        return $role === 'admin' || in_array($role, $this->settings->roles(), true);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AssistantError
     */
    public function run(string $taskId, array $input, AssistantContext $context, ?Actor $actor = null): AssistantResult
    {
        $task = $this->extensions->task($taskId) ?? throw new AssistantError(AssistantError::UNKNOWN_TASK, new Message('The assistant can’t do that.'));
        if (!$this->mayUse($actor) || ($actor !== null && !$actor->has($task->permission()))) {
            throw new AssistantError(AssistantError::NOT_ALLOWED, new Message('You can’t use the assistant for this. Ask an admin if you need it.'));
        }
        $configuration = $this->ready();
        $provider = $configuration->provider;
        $model = $configuration->model;
        if ($task->needsVision() && $provider !== null && $model !== null && !$provider->capabilities($model)->vision) {
            throw new AssistantError(AssistantError::NO_VISION, new Message('The chosen model can’t look at pictures. An admin can choose one that can on the AI assistant screen.'));
        }
        $this->limit($actor);
        $budget = $this->withinBudget($configuration);
        $problems = InputSchema::problems($task->inputSchema(), $input);
        if ($problems !== []) {
            throw new AssistantError(AssistantError::INVALID_INPUT, new Message('The assistant couldn’t use what was sent.'), detail: $problems[0]);
        }

        $response = $this->send($task->id(), $configuration, $task->buildPrompt($input, $context), $actor);
        try {
            $suggestion = $task->validateOutput($response, $input, $context);
        } catch (AssistantException $exception) {
            $this->record($actor, $task->id(), (string) $provider?->id(), $response->model, $response->usage, $exception->failure->value);

            throw AssistantError::failed($exception->failure);
        }
        $this->record($actor, $task->id(), (string) $provider?->id(), $response->model, $response->usage, AssistantUse::OK);

        return new AssistantResult($suggestion, $response->usage, $response->truncated, $this->budget->status($budget->limit));
    }

    /**
     * Tries the connection with a tiny request ("reply OK"); counted like any other use.
     *
     * @throws AssistantError
     */
    public function test(?int $actorId = null): AssistantResponse
    {
        $configuration = $this->ready();
        $actor = $actorId === null ? null : new Actor($actorId);
        $response = $this->send(self::TEST, $configuration, [
            AssistantMessage::system('This is a connection test. Reply with the single word OK.'),
            AssistantMessage::user('Test'),
        ], $actor, 16);
        $this->record($actor, self::TEST, (string) $configuration->provider?->id(), $response->model, $response->usage, AssistantUse::OK);

        return $response;
    }

    public function budget(): BudgetStatus
    {
        return $this->budget->status($this->setup->configuration()->budget);
    }

    /**
     * @throws AssistantError
     */
    private function ready(): AssistantConfiguration
    {
        $configuration = $this->setup->configuration();
        if (!$configuration->isReady()) {
            throw new AssistantError(AssistantError::NOT_CONFIGURED, $configuration->problem ?? new Message('No AI service is chosen yet.'));
        }

        return $configuration;
    }

    /**
     * @throws AssistantError
     */
    private function limit(?Actor $actor): void
    {
        if ($actor?->userId === null) {
            return;
        }
        $limit = $this->limiters->limiter(self::RATE_LIMIT, $this->site->slug . ':' . $actor->userId)->consume();
        if (!$limit->isAccepted()) {
            $seconds = RateLimiters::secondsUntil($limit->getRetryAfter());
            throw new AssistantError(AssistantError::TOO_MANY, new Message(
                'You’ve asked the assistant a lot in a short time. Try again in {minutes, plural, one {# minute} other {# minutes}}.',
                ['minutes' => (int) ceil($seconds / 60)],
            ), retryAfter: $seconds);
        }
    }

    /**
     * @throws AssistantError
     */
    private function withinBudget(AssistantConfiguration $configuration): BudgetStatus
    {
        $status = $this->budget->status($configuration->budget);
        if ($status->isUsedUp()) {
            throw new AssistantError(AssistantError::BUDGET, new Message('This site has used all of its AI assistant budget for this month. It starts again on the 1st, or an admin can raise it on the AI assistant screen.'));
        }

        return $status;
    }

    /**
     * Sends the prompt; a failure is counted and audited here, a success by the caller once the answer is checked.
     *
     * @param non-empty-list<AssistantMessage> $messages
     *
     * @throws AssistantError
     */
    private function send(string $task, AssistantConfiguration $configuration, array $messages, ?Actor $actor, int $maxOutputTokens = self::MAX_OUTPUT_TOKENS): AssistantResponse
    {
        $provider = $configuration->provider ?? throw new AssistantError(AssistantError::NOT_CONFIGURED, new Message('No AI service is chosen yet.'));
        $connection = $configuration->connection ?? throw new AssistantError(AssistantError::NOT_CONFIGURED, new Message('No AI service is chosen yet.'));
        $model = $configuration->model ?? throw new AssistantError(AssistantError::NOT_CONFIGURED, new Message('No AI service is chosen yet.'));
        try {
            $response = $provider->complete(new AssistantRequest($model, $messages, $maxOutputTokens), $connection);
        } catch (AssistantException $exception) {
            $this->record($actor, $task, $provider->id(), $model, new TokenUsage(), $exception->failure->value);

            throw AssistantError::failed($exception->failure);
        } catch (InvalidValueException) {
            $this->record($actor, $task, $provider->id(), $model, new TokenUsage(), AssistantError::INVALID_INPUT);

            throw new AssistantError(AssistantError::INVALID_INPUT, new Message('The assistant couldn’t use what was sent.'));
        }

        return $response;
    }

    /**
     * @param non-empty-string $outcome
     */
    private function record(?Actor $actor, string $task, string $provider, string $model, TokenUsage $usage, string $outcome): void
    {
        $this->usage->record(new AssistantUse($this->clock->now(), $actor?->userId, $task, $provider, $model, $usage, $outcome));
        $this->events->dispatch(new AssistantUsed($actor?->userId, $task, $provider, $model, $usage->inputTokens, $usage->outputTokens, $outcome));
    }
}
