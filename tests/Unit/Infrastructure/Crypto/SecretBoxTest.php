<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Crypto;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Infrastructure\Crypto\SecretBoxException;

#[CoversClass(SecretBox::class)]
final class SecretBoxTest extends TestCase
{
    private const string SECRET = 'a-secret-that-is-long-enough-0123456789';

    public function testEncryptsAndDecrypts(): void
    {
        $box = new SecretBox(self::SECRET);

        $encrypted = $box->encrypt('mysql://app:p4ss@db.internal/site_demo');

        self::assertStringStartsWith(SecretBox::PREFIX, $encrypted);
        self::assertStringNotContainsString('p4ss', $encrypted);
        self::assertTrue(SecretBox::looksEncrypted($encrypted));
        self::assertSame('mysql://app:p4ss@db.internal/site_demo', $box->decrypt($encrypted));
        self::assertTrue($box->isConfigured());
    }

    public function testEveryEncryptionUsesAFreshNonce(): void
    {
        $box = new SecretBox(self::SECRET);

        self::assertNotSame($box->encrypt('same'), $box->encrypt('same'));
    }

    public function testAnotherSecretCannotDecrypt(): void
    {
        $encrypted = new SecretBox(self::SECRET)->encrypt('sqlite:///data/demo.sqlite');

        $this->expectException(SecretBoxException::class);
        $this->expectExceptionMessage('encrypted with another APP_SECRET');

        new SecretBox(self::SECRET . '-other')->decrypt($encrypted);
    }

    public function testAChangedValueCannotBeDecrypted(): void
    {
        $box = new SecretBox(self::SECRET);
        $encrypted = $box->encrypt('sqlite:///data/demo.sqlite');
        $changed = substr($encrypted, 0, -2) . (str_ends_with($encrypted, 'AA') ? 'BB' : 'AA');

        $this->expectException(SecretBoxException::class);

        $box->decrypt($changed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notEncrypted(): iterable
    {
        yield 'plain DSN' => ['sqlite:///data/demo.sqlite'];
        yield 'prefix only' => [SecretBox::PREFIX];
        yield 'not base64' => [SecretBox::PREFIX . '!!!'];
        yield 'too short' => [SecretBox::PREFIX . 'AAAA'];
    }

    #[DataProvider('notEncrypted')]
    public function testRecognisesValuesThatAreNotEncrypted(string $value): void
    {
        self::assertFalse(SecretBox::looksEncrypted($value));

        $this->expectException(SecretBoxException::class);
        $this->expectExceptionMessage('not encrypted');

        new SecretBox(self::SECRET)->decrypt($value);
    }

    public function testWithoutASecretNothingIsEncrypted(): void
    {
        $box = new SecretBox(null);

        self::assertFalse($box->isConfigured());
        $this->expectException(SecretBoxException::class);
        $this->expectExceptionMessage('APP_SECRET is not set.');

        $box->encrypt('anything');
    }
}
