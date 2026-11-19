<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\AssistantError;
use SightPress\Core\Assistant\SiteAssistantContext;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Message;
use SightPress\Module\Assistant\AssistantFailure;

#[CoversClass(AssistantError::class)]
#[CoversClass(SiteAssistantContext::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Rbac\Contract')]
final class AssistantErrorTest extends TestCase
{
    public function testExplainsEveryFailureInPlainWords(): void
    {
        $messages = [];
        foreach (AssistantFailure::cases() as $failure) {
            $error = AssistantError::failed($failure);
            self::assertSame([$failure, $failure->value], [$error->failure, $error->outcome]);
            $messages[] = $error->getMessage();
        }

        self::assertCount(8, array_unique($messages));
        self::assertSame('The AI service took too long to answer. Try again, perhaps with less text.', AssistantError::failed(AssistantFailure::Timeout)->getMessage());
    }

    public function testFillsItsValuesInEnglish(): void
    {
        $error = new AssistantError(AssistantError::TOO_MANY, new Message('Try again in {minutes, plural, one {# minute} other {# minutes}} ({what}).', ['minutes' => 2, 'what' => new Message('x')]), retryAfter: 90);

        self::assertSame(['Try again in 2 minutes ().', 90, null], [$error->getMessage(), $error->retryAfter, $error->failure]);
        self::assertSame('Nothing to fill.', AssistantError::english(new Message('Nothing to fill.')));
    }

    public function testTheContextKnowsTheLanguageWhatThePersonMayDoAndTheScreen(): void
    {
        $console = new SiteAssistantContext('en-GB');
        self::assertSame(['en-GB', true, null], [$console->locale(), $console->can('anything'), $console->screen()]);

        $editor = new SiteAssistantContext('pt-PT', new Actor(3, ['page.edit']), 'page.edit');
        self::assertSame(['pt-PT', true, false, 'page.edit'], [$editor->locale(), $editor->can('page.edit'), $editor->can('features.manage'), $editor->screen()]);
    }
}
