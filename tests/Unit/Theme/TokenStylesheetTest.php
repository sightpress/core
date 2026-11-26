<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Theme\Color;
use SightPress\Core\Theme\ThemeTokens;
use SightPress\Core\Theme\TokenStylesheet;

#[CoversClass(TokenStylesheet::class)]
#[UsesClass(Color::class)]
#[UsesClass(ThemeTokens::class)]
final class TokenStylesheetTest extends TestCase
{
    public function testTokensBecomeCustomPropertiesForEveryMode(): void
    {
        $css = new TokenStylesheet()->compile(new ThemeTokens(
            ['text' => Color::fromHex('#222'), 'background' => Color::fromHex('#fff')],
            ['text' => Color::fromHex('#eee'), 'background' => Color::fromHex('#111')],
            ['text' => Color::fromHex('#000')],
            ['background' => Color::fromHex('#000')],
            ['typography' => ['size-base' => '1.125rem'], 'spacing' => ['1' => '0.25rem'], 'radius' => [], 'focus' => ['width' => '3px']],
        ));

        self::assertSame(<<<'CSS'
            /* Generated from theme.json. */
            :root {
              color-scheme: light dark;
              --color-text: #222222;
              --color-background: #ffffff;
              --font-size-base: 1.125rem;
              --space-1: 0.25rem;
              --focus-width: 3px;
            }
            @media (prefers-color-scheme: dark) {
              :root {
                --color-text: #eeeeee;
                --color-background: #111111;
              }
            }
            @media (prefers-contrast: more) {
              :root {
                --color-text: #000000;
              }
            }
            @media (prefers-contrast: more) and (prefers-color-scheme: dark) {
              :root {
                --color-text: #eeeeee;
                --color-background: #000000;
              }
            }

            CSS . "\n" . file_get_contents(TokenStylesheet::BLOCK_STYLES), $css);
    }

    public function testWithoutMoreContrastColoursThoseBlocksAreLeftOut(): void
    {
        $css = new TokenStylesheet()->compile(new ThemeTokens(['text' => Color::fromHex('#222')], ['text' => Color::fromHex('#eee')]));

        self::assertStringNotContainsString('prefers-contrast', $css);
        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
    }
}
