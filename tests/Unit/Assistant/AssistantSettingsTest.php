<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\AssistantSettings;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\Tests\Support\SiteFixture;

#[CoversClass(AssistantSettings::class)]
#[UsesNamespace('SightPress\Core')]
final class AssistantSettingsTest extends TestCase
{
    private const string KEY = 'sk-canary-5f2b9c1d7e4a';

    public function testStartsEmptyWithTheDefaultRoles(): void
    {
        $settings = new AssistantSettings(new ArraySiteSettings(), SiteFixture::secretBox(), new RecordingEvents());

        self::assertSame([null, null, null, false, null, null, null], [$settings->provider(), $settings->model(), $settings->baseUrl(), $settings->hasKey(), $settings->key(), $settings->maskedKey(), $settings->budget()]);
        self::assertSame(['admin', 'editor', 'contributor'], $settings->roles());
    }

    public function testKeepsTheKeyEncryptedAndShowsOnlyItsEnd(): void
    {
        $stored = new ArraySiteSettings();
        $events = new RecordingEvents();
        $settings = new AssistantSettings($stored, SiteFixture::secretBox(), $events);

        self::assertSame(['assistant.provider', 'assistant.model', 'assistant.key'], $settings->connect('anthropic', 'claude-haiku-5-5', null, self::KEY, 4));
        self::assertTrue(SecretBox::looksEncrypted((string) $stored->get('assistant.key')));
        self::assertStringNotContainsString(self::KEY, (string) $stored->get('assistant.key'));
        self::assertSame([self::KEY, '••••7e4a', 'anthropic', 'claude-haiku-5-5'], [$settings->key(), $settings->maskedKey(), $settings->provider(), $settings->model()]);
        self::assertEquals([new SettingsChanged(['assistant.provider', 'assistant.model', 'assistant.key'], 4)], $events->events);
        self::assertStringNotContainsString(self::KEY, serialize($events->events));

        self::assertSame([], $settings->connect('anthropic', 'claude-haiku-5-5', null, self::KEY, 4), 'the same key is no change');
        self::assertSame([], $settings->connect('anthropic', 'claude-haiku-5-5', null, null, 4), 'no key keeps the current one');
        self::assertSame(['assistant.provider', 'assistant.model', 'assistant.base_url'], $settings->connect('openai_compatible', 'llama', 'https://ai.example.org/v1', null, 4));
        self::assertSame('https://ai.example.org/v1', $settings->baseUrl());
        self::assertSame(self::KEY, $settings->key());

        $settings->connect('openai_compatible', 'llama', 'https://ai.example.org/v1', 'short', 4);
        self::assertSame('••••', $settings->maskedKey(), 'short keys show nothing');

        self::assertTrue($settings->removeKey(4));
        self::assertFalse($settings->removeKey(4));
        self::assertSame([false, null, null], self::keyState($settings));
        self::assertEquals(new SettingsChanged(['assistant.key'], 4), array_last($events->events));
    }

    public function testAKeyItCannotDecryptIsNoKey(): void
    {
        $other = new SecretBox(str_repeat('o', 40));
        $settings = new AssistantSettings(new ArraySiteSettings(['assistant.key' => $other->encrypt(self::KEY)]), SiteFixture::secretBox(), new RecordingEvents());

        self::assertSame([true, null, '••••'], [$settings->hasKey(), $settings->key(), $settings->maskedKey()]);
        self::assertNull(new AssistantSettings(new ArraySiteSettings(['assistant.key' => SiteFixture::secretBox()->encrypt('')]), SiteFixture::secretBox(), new RecordingEvents())->key());
    }

    public function testKeepsTheBudgetAndRoles(): void
    {
        $events = new RecordingEvents();
        $settings = new AssistantSettings(new ArraySiteSettings(['assistant.monthly_budget' => 'lots']), SiteFixture::secretBox(), $events);
        self::assertNull(self::limits($settings)[0], 'nonsense is the tenant default');

        self::assertSame(['assistant.monthly_budget', 'assistant.roles'], $settings->limit(100000, ['admin', 'editor', 'editor'], 1));
        self::assertSame([100000, ['admin', 'editor']], self::limits($settings));
        self::assertSame([], $settings->limit(100000, ['admin', 'editor'], 1));
        self::assertSame(['assistant.monthly_budget', 'assistant.roles'], $settings->limit(null, [], 1));
        self::assertSame([null, []], self::limits($settings));
        self::assertSame(['assistant.monthly_budget'], $settings->limit(0, [], 1));
        self::assertSame(0, $settings->budget());
        self::assertCount(3, $events->events);
    }

    /**
     * @return array{bool, ?string, ?string}
     */
    private static function keyState(AssistantSettings $settings): array
    {
        return [$settings->hasKey(), $settings->key(), $settings->maskedKey()];
    }

    /**
     * @return array{?int, list<string>}
     */
    private static function limits(AssistantSettings $settings): array
    {
        return [$settings->budget(), $settings->roles()];
    }
}
