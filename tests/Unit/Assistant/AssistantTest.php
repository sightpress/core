<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Assistant;
use SightPress\Core\Assistant\AssistantError;
use SightPress\Core\Assistant\AssistantResult;
use SightPress\Core\Assistant\AssistantUse;
use SightPress\Module\Event\AssistantUsed;
use SightPress\Core\Assistant\SiteAssistantContext;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tests\Support\Assistant\AssistantFixture;
use SightPress\Core\Tests\Support\Assistant\FakeProvider;
use SightPress\Core\Tests\Support\Assistant\FakeTask;
use SightPress\Core\Tests\Support\Assistant\FakeTaskWithVision;
use SightPress\Module\Assistant\AssistantFailure;
use SightPress\Module\Assistant\AssistantRequest;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\TokenUsage;
use SightPress\Module\Exception\AssistantException;
use SightPress\Module\Exception\InvalidValueException;

#[CoversClass(Assistant::class)]
#[CoversClass(AssistantResult::class)]
#[CoversClass(AssistantUse::class)]
#[UsesNamespace('SightPress\Core')]
final class AssistantTest extends TestCase
{
    private const string KEY = 'sk-canary-5f2b9c1d7e4a';

    public function testRunsATaskAndCountsWhatItCost(): void
    {
        $provider = new FakeProvider(static fn (AssistantRequest $request): AssistantResponse => new AssistantResponse('A fine summary', 'fake-small-2026', new TokenUsage(100, 20), true));
        $fixture = self::ready($provider);
        $editor = new Actor(3, ['assistant.use', 'page.edit']);

        $result = $fixture->assistant->run('fake_task', ['text' => 'Summarise this'], new SiteAssistantContext('pt-PT', $editor, 'page.edit'), $editor);

        self::assertSame(['A fine summary', ['field' => 'A fine summary'], 120, true], [$result->suggestion->text, $result->suggestion->fields, $result->usage->total(), $result->truncated]);
        self::assertSame([120, 0], [$result->budget->used, $result->budget->limit]);
        self::assertSame('fake-small', $provider->requests[0]->model);
        self::assertSame(Assistant::MAX_OUTPUT_TOKENS, $provider->requests[0]->maxOutputTokens);
        self::assertSame('Be fake in pt-PT.', $provider->requests[0]->instructions());
        self::assertSame(self::KEY, $provider->connections[0]->apiKey);
        self::assertEquals([new AssistantUsed(3, 'fake_task', 'fake', 'fake-small-2026', 100, 20, 'ok')], $fixture->events->events);
        self::assertSame([['task' => 'fake_task', 'uses' => 1, 'failed' => 0, 'input' => 100, 'output' => 20]], $fixture->usage->byTask('2026-11'));
        self::assertStringNotContainsString(self::KEY, serialize($fixture->events->events));
        self::assertStringNotContainsString('Summarise this', serialize($fixture->events->events));
    }

    public function testOnlyPeopleWithThePermissionsAndARoleMayUseIt(): void
    {
        $fixture = self::ready(new FakeProvider(), [new FakeTask(permission: 'page.edit')]);
        $context = new SiteAssistantContext('en-GB');

        self::assertTrue($fixture->assistant->mayUse(null));
        self::assertTrue($fixture->assistant->mayUse(new Actor(3, ['assistant.use'])));
        self::assertFalse($fixture->assistant->mayUse(new Actor(3, ['page.edit'])), 'no assistant.use');
        self::assertFalse($fixture->assistant->mayUse(new Actor(5, ['assistant.use'])), 'members are not among the roles');
        self::assertFalse($fixture->assistant->mayUse(new Actor(99, ['assistant.use'])), 'no role at all');
        self::assertFalse($fixture->assistant->mayUse(Actor::anonymous()));
        $fixture->settings->limit(null, [], 1);
        self::assertFalse($fixture->assistant->mayUse(new Actor(3, ['assistant.use'])));
        self::assertTrue($fixture->assistant->mayUse(new Actor(4, ['assistant.use'])), 'admins always may');
        $fixture->settings->limit(null, ['editor'], 1);

        self::assertSame(['fake_task'], array_keys($fixture->assistant->tasksFor(new Actor(3, ['assistant.use', 'page.edit']))));
        self::assertSame([], $fixture->assistant->tasksFor(new Actor(3, ['assistant.use'])), 'the task needs page.edit');
        self::assertSame([], $fixture->assistant->tasksFor(new Actor(5, ['assistant.use', 'page.edit'])));
        self::assertSame(['fake_task'], array_keys($fixture->assistant->tasksFor(null)));

        $error = self::error(static fn () => $fixture->assistant->run('fake_task', ['text' => 'x'], $context, new Actor(3, ['assistant.use'])));
        self::assertSame([AssistantError::NOT_ALLOWED, 'You can’t use the assistant for this. Ask an admin if you need it.'], [$error->outcome, $error->getMessage()]);
        self::assertSame(AssistantError::UNKNOWN_TASK, self::error(static fn () => $fixture->assistant->run('nope', [], $context))->outcome);
        self::assertSame([], array_filter($fixture->events->events, static fn (object $event): bool => $event instanceof AssistantUsed), 'nothing was sent');
    }

    public function testRefusesUntilItIsSetUp(): void
    {
        $fixture = new AssistantFixture([new FakeProvider()], [new FakeTask()]);

        $error = self::error(static fn () => $fixture->assistant->run('fake_task', ['text' => 'x'], new SiteAssistantContext('en-GB')));
        self::assertSame([AssistantError::NOT_CONFIGURED, 'No AI service is chosen yet.'], [$error->outcome, $error->getMessage()]);
        self::assertSame(AssistantError::NOT_CONFIGURED, self::error(static fn () => $fixture->assistant->test())->outcome);
        self::assertSame([], $fixture->assistant->tasksFor(null));
    }

    public function testVisionTasksNeedAModelThatCanSee(): void
    {
        $fixture = self::ready(new FakeProvider(), [new FakeTask(), new FakeTaskWithVision()]);

        self::assertSame(['fake_task'], array_keys($fixture->assistant->tasksFor(null)));
        $error = self::error(static fn () => $fixture->assistant->run('fake_vision_task', ['text' => 'x'], new SiteAssistantContext('en-GB')));
        self::assertSame([AssistantError::NO_VISION, null], [$error->outcome, $error->failure]);

        $fixture->settings->connect('fake', 'fake-vision', null, null, 1);
        self::assertSame(['fake_task', 'fake_vision_task'], array_keys($fixture->assistant->tasksFor(null)));
        self::assertSame('OK', $fixture->assistant->run('fake_vision_task', ['text' => 'x'], new SiteAssistantContext('en-GB'))->suggestion->text);
    }

    public function testChecksTheInputBeforeSendingAnything(): void
    {
        $provider = new FakeProvider();
        $fixture = self::ready($provider);

        $error = self::error(static fn () => $fixture->assistant->run('fake_task', ['text' => str_repeat('a', 51)], new SiteAssistantContext('en-GB')));
        self::assertSame([AssistantError::INVALID_INPUT, 'The assistant couldn’t use what was sent.', 'input.text must be 50 characters or fewer.'], [$error->outcome, $error->getMessage(), $error->detail]);
        self::assertSame([], $provider->requests);
    }

    public function testLimitsHowOftenAPersonAsks(): void
    {
        $fixture = self::ready(new FakeProvider(), rateLimit: 2);
        $editor = new Actor(3, ['assistant.use']);
        $context = new SiteAssistantContext('en-GB', $editor);

        $fixture->assistant->run('fake_task', ['text' => 'a'], $context, $editor);
        $fixture->assistant->run('fake_task', ['text' => 'b'], $context, $editor);
        $error = self::error(static fn () => $fixture->assistant->run('fake_task', ['text' => 'c'], $context, $editor));

        self::assertSame(AssistantError::TOO_MANY, $error->outcome);
        self::assertMatchesRegularExpression('/^You’ve asked the assistant a lot in a short time\. Try again in \d+ minutes?\.$/', $error->getMessage());
        self::assertGreaterThan(0, $error->retryAfter);
        self::assertSame('OK', $fixture->assistant->run('fake_task', ['text' => 'c'], $context)->suggestion->text, 'the console has no rate limit');
    }

    public function testRefusesOnceTheMonthlyBudgetIsUsedUp(): void
    {
        $fixture = self::ready(new FakeProvider(static fn (AssistantRequest $request): AssistantResponse => new AssistantResponse('OK', 'fake-small', new TokenUsage(70, 10))));
        $fixture->settings->limit(100, ['admin', 'editor'], 1);
        $context = new SiteAssistantContext('en-GB');

        $result = $fixture->assistant->run('fake_task', ['text' => 'a'], $context);
        self::assertSame([80, true, false], [$result->budget->percent(), $result->budget->isNearlyUsedUp(), $result->budget->isUsedUp()]);
        $result = $fixture->assistant->run('fake_task', ['text' => 'b'], $context);
        self::assertTrue($result->budget->isUsedUp());

        $error = self::error(static fn () => $fixture->assistant->run('fake_task', ['text' => 'c'], $context));
        self::assertSame(AssistantError::BUDGET, $error->outcome);
        self::assertStringStartsWith('This site has used all of its AI assistant budget for this month.', $error->getMessage());
        self::assertSame(160, $fixture->assistant->budget()->used);
        self::assertSame('OK', $fixture->assistant->test()->text, 'a connection test is still allowed');
    }

    public function testProviderFailuresAreCountedAndExplained(): void
    {
        $fixture = self::ready(new FakeProvider(static fn (AssistantRequest $request): AssistantResponse => throw new AssistantException(AssistantFailure::InvalidKey, 'Fake AI answered HTTP 401.')));
        $editor = new Actor(3, ['assistant.use']);

        $error = self::error(static fn () => $fixture->assistant->run('fake_task', ['text' => 'a'], new SiteAssistantContext('en-GB'), $editor));

        self::assertSame([AssistantFailure::InvalidKey, 'invalid_key'], [$error->failure, $error->outcome]);
        self::assertSame('The AI service didn’t accept the API key. An admin can replace it on the AI assistant screen.', $error->getMessage());
        self::assertEquals([new AssistantUsed(3, 'fake_task', 'fake', 'fake-small', 0, 0, 'invalid_key')], $fixture->events->events);
        self::assertSame([['task' => 'fake_task', 'uses' => 1, 'failed' => 1, 'input' => 0, 'output' => 0]], $fixture->usage->byTask('2026-11'));
    }

    public function testUnusableAnswersAreCountedButNotSuggested(): void
    {
        $fixture = self::ready(new FakeProvider(static fn (AssistantRequest $request): AssistantResponse => new AssistantResponse('a bad answer', 'fake-small', new TokenUsage(5, 5))));

        $error = self::error(static fn () => $fixture->assistant->run('fake_task', ['text' => 'a'], new SiteAssistantContext('en-GB')));

        self::assertSame(AssistantFailure::InvalidOutput, $error->failure);
        self::assertEquals([new AssistantUsed(null, 'fake_task', 'fake', 'fake-small', 5, 5, 'invalid_output')], $fixture->events->events);
    }

    public function testARequestTheContractRefusesIsInvalidInput(): void
    {
        $fixture = self::ready(new FakeProvider(static fn (AssistantRequest $request): AssistantResponse => throw new InvalidValueException('bad')));

        self::assertSame(AssistantError::INVALID_INPUT, self::error(static fn () => $fixture->assistant->run('fake_task', ['text' => 'a'], new SiteAssistantContext('en-GB')))->outcome);
        self::assertSame(1, $fixture->usage->byTask('2026-11')[0]['failed']);
    }

    public function testTriesTheConnection(): void
    {
        $provider = new FakeProvider();
        $fixture = self::ready($provider);

        $response = $fixture->assistant->test(4);

        self::assertSame('OK', $response->text);
        self::assertSame([16, 'This is a connection test. Reply with the single word OK.'], [$provider->requests[0]->maxOutputTokens, $provider->requests[0]->instructions()]);
        self::assertEquals([new AssistantUsed(4, 'connection_test', 'fake', 'fake-small', 10, 2, 'ok')], $fixture->events->events);
    }

    /**
     * @param list<\SightPress\Module\Assistant\AssistantTask>|null $tasks
     */
    private static function ready(FakeProvider $provider, ?array $tasks = null, int $rateLimit = 20): AssistantFixture
    {
        $fixture = new AssistantFixture([$provider], $tasks ?? [new FakeTask()], rateLimit: $rateLimit);
        $fixture->settings->connect('fake', 'fake-small', null, self::KEY, 1);
        $fixture->events->events = [];

        return $fixture;
    }

    /**
     * @param callable(): mixed $call
     */
    private static function error(callable $call): AssistantError
    {
        try {
            $call();
        } catch (AssistantError $error) {
            return $error;
        }
        self::fail('Expected an AssistantError.');
    }
}
