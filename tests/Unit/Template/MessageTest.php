<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\LanguageName;
use SightPress\Core\Template\Message;

#[CoversClass(Message::class)]
#[CoversClass(LanguageName::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
final class MessageTest extends TestCase
{
    public function testIsTheEnGbSentenceWithItsValues(): void
    {
        $saved = new Message('Saved “{title}” ({count, plural, one {# change} other {# changes}}).', ['title' => 'About', 'count' => 2]);

        self::assertSame('Saved “About” (2 changes).', (string) $saved);
        self::assertSame('Enter title', (string) new Message('Enter {field}', ['field' => Message::of('Title')->lowerFirst()]));
        self::assertSame('Added European Portuguese.', (string) new Message('Added {language}.', ['language' => new LanguageName('pt-PT')]));
        self::assertSame($saved, Message::of($saved));
        self::assertSame(' ', Message::of('')->text, 'never an empty text');
    }

    public function testFillsTheValidatorsValuesAndIcuValues(): void
    {
        self::assertSame('Use 12 characters or fewer.', Message::format('Use {{ limit }} characters or fewer.', ['{{ limit }}' => '12'], 'en-GB'));
        self::assertSame('Title must be 200 characters or fewer', Message::format('{field} must be {{ limit }} characters or fewer', ['field' => 'Title', '{{ limit }}' => '200'], 'en-GB'));
        self::assertSame('“{x}” is not a link', Message::format('“{{ line }}” is not a link', ['{{ line }}' => '{x}', 'unused' => 1], 'en-GB'), 'braces in a value stay as they are');
        self::assertSame('Broken {', Message::format('Broken {', ['x' => 1], 'en-GB'), 'an invalid pattern is shown as it is');
    }

    public function testListsThingsTheWayTheLanguageDoes(): void
    {
        self::assertSame('a', (string) Message::list(['a']));
        self::assertSame('a and b', (string) Message::list(['a', new Message('b')]));
        self::assertSame('a, b and c', (string) Message::list(['a', 'b', 'c']));
        self::assertSame('{first}, {rest}', Message::list(['a', 'b', 'c'])->text);
    }

    public function testGoesIntoASessionAndComesBack(): void
    {
        $message = new Message('{who} removed {language} from {site}.', ['who' => new Message('Someone')->lowerFirst(), 'language' => new LanguageName('es'), 'site' => 'Demo', 'count' => 3, 'none' => null]);

        $back = Message::import($message->export());

        self::assertEquals($message, $back);
        self::assertSame('someone removed Spanish from Demo.', (string) $back);
        self::assertEquals(new Message('Plain'), Message::import('Plain'));
        foreach ([null, '', 3, [], ['text' => ''], ['text' => 7]] as $nothing) {
            self::assertNull(Message::import($nothing));
        }
        self::assertEquals(new Message('{a}', ['a' => null]), Message::import(['text' => '{a}', 'parameters' => ['a' => true, 0 => 'skipped']]));
    }

    public function testNamesALanguageInAnotherLanguage(): void
    {
        $portuguese = new LanguageName('pt-PT');

        self::assertSame('European Portuguese', (string) $portuguese);
        self::assertSame('Portugués de Portugal', $portuguese->in('es'));
        self::assertSame('Português europeu', $portuguese->in('pt-PT'));
        self::assertSame('Português (Portugal)', $portuguese->in('pt-BR'), 'as that region names it');
        self::assertSame('Qaa', new LanguageName('qaa')->in('en-GB'), 'a code ICU doesn’t know stays a code');
    }
}
