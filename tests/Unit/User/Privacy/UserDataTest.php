<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\User\Privacy;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\Privacy\SiteTables;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Core\User\Avatar\AvatarService;
use SightPress\Core\User\AvatarKind;
use SightPress\Core\User\Privacy\AccountData;
use SightPress\Core\User\Privacy\AvatarData;
use SightPress\Core\User\Privacy\PhoneData;
use SightPress\Module\Privacy\ErasureRule;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Sms\PhoneNumber;

#[CoversClass(AccountData::class)]
#[CoversClass(PhoneData::class)]
#[CoversClass(AvatarData::class)]
#[UsesNamespace('SightPress\Core')]
final class UserDataTest extends TestCase
{
    public function testExportsAndErasesTheAccountAsATombstone(): void
    {
        $connection = SiteTables::connection();
        SiteTables::user($connection, 7, 'ada@example.org', ['display_name' => 'Ada L', 'locale' => 'en-GB']);
        SiteTables::user($connection, 8, 'grace@example.org');
        $data = new AccountData($connection, new FixedClock(SiteTables::now()));
        $ada = new UserRef(7, 'ada@example.org');

        self::assertSame(ErasureRule::Pseudonymise, $data->describe()->erasure);
        self::assertSame([[
            'email' => 'ada@example.org', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'display_name' => 'Ada L',
            'status' => 'active', 'time_zone' => 'Europe/London', 'admin_language' => 'en-GB',
            'admin_reduced_motion' => false, 'admin_larger_text' => false, 'has_password' => true,
            'created_at' => '2026-11-01T10:00:00Z', 'updated_at' => '2026-11-02T10:00:00Z',
        ]], iterator_to_array($data->export($ada), false));
        self::assertSame([], iterator_to_array($data->export(new UserRef(99, 'nobody@example.org')), false));

        self::assertSame(1, $data->erase($ada)->pseudonymised);
        self::assertTrue($data->erase($ada)->isEmpty(), 'erasing again changes nothing');
        $row = $connection->fetchAssociative('SELECT * FROM users WHERE id = 7');
        self::assertSame(AccountData::tombstoneEmail(7), $row['email'] ?? null);
        self::assertTrue(AccountData::isTombstoneEmail(Row::text($row['email'] ?? null) ?? ''));
        self::assertFalse(AccountData::isTombstoneEmail('ada@example.org'));
        self::assertSame(['', '', null, null, 'deactivated', 'UTC', null], [$row['first_name'], $row['last_name'], $row['display_name'], $row['password_hash'], $row['status'], $row['timezone'], $row['locale']]);
        self::assertSame('grace@example.org', $connection->fetchOne('SELECT email FROM users WHERE id = 8'), 'nobody else is touched');
    }

    public function testExportsAndErasesThePhoneNumberAndTextChoices(): void
    {
        $connection = SiteTables::connection();
        SiteTables::user($connection, 7, 'ada@example.org', ['phone_e164' => '+447700900123', 'phone_verified_at' => '2026-11-03 10:00:00', 'phone_lookup' => 'mobile', 'sms_consent_at' => '2026-11-04 10:00:00', 'sms_notifications' => 'page.review,,people.invite']);
        SiteTables::user($connection, 8, 'grace@example.org');
        $data = new PhoneData($connection);
        $ada = new UserRef(7, 'ada@example.org', new PhoneNumber('+447700900123'));

        self::assertSame(LawfulBasis::Consent, $data->describe()->lawfulBasis);
        self::assertSame([[
            'mobile_number' => '+447700900123', 'confirmed_at' => '2026-11-03T10:00:00Z', 'line_type' => 'mobile',
            'agreed_to_texts_at' => '2026-11-04T10:00:00Z', 'stopped_texts_at' => null, 'text_notifications' => ['page.review', 'people.invite'],
        ]], iterator_to_array($data->export($ada), false));
        self::assertSame([], iterator_to_array($data->export(new UserRef(8, 'grace@example.org')), false));
        self::assertSame(1, $data->erase($ada)->deleted);
        self::assertTrue($data->erase($ada)->isEmpty());
        self::assertSame([], iterator_to_array($data->export($ada), false));
        self::assertNull($connection->fetchOne('SELECT phone_e164 FROM users WHERE id = 7'));
    }

    public function testExportsAndDeletesTheProfilePicture(): void
    {
        $connection = SiteTables::connection();
        SiteTables::user($connection, 7, 'ada@example.org', ['avatar_path' => 'avatars/abc.jpg', 'avatar_kind' => AvatarKind::Uploaded->value, 'avatar_color' => '#123456']);
        SiteTables::user($connection, 8, 'grace@example.org', ['avatar_path' => 'avatars/def.svg', 'avatar_kind' => AvatarKind::Generated->value, 'avatar_color' => '#654321']);
        SiteTables::user($connection, 9, 'kay@example.org');
        $storage = new Filesystem(new LocalFilesystemAdapter(($directory = new TemporaryDirectory())->path));
        foreach (AvatarService::SIZES as $size) {
            $storage->write(sprintf('avatars/abc-%d.jpg', $size), 'jpeg');
        }
        $storage->write('avatars/def.svg', '<svg/>');
        $data = new AvatarData($connection, $storage);
        $ada = new UserRef(7, 'ada@example.org');

        self::assertSame('user.avatar', $data->describe()->key);
        self::assertSame([['kind' => 'uploaded', 'colour' => '#123456', 'files' => ['avatars/abc-64.jpg', 'avatars/abc-128.jpg', 'avatars/abc-256.jpg']]], iterator_to_array($data->export($ada), false));
        self::assertSame([], iterator_to_array($data->export(new UserRef(9, 'kay@example.org')), false));
        $erased = $data->erase($ada);
        self::assertSame([1, 3], [$erased->deleted, $erased->files]);
        self::assertFalse($storage->fileExists('avatars/abc-64.jpg'));
        self::assertTrue($storage->fileExists('avatars/def.svg'), 'nobody else\'s picture');
        self::assertTrue($data->erase($ada)->isEmpty());
        self::assertSame(['avatars/x'], AvatarService::filesOf('avatars/x', null));
        $storage->write('avatars/locked.svg', '<svg/>');
        $connection->update('users', ['avatar_path' => 'avatars/locked.svg', 'avatar_kind' => 'generated'], ['id' => 9]);
        chmod($directory->path . '/avatars', 0o555);
        $locked = $data->erase(new UserRef(9, 'kay@example.org'));
        chmod($directory->path . '/avatars', 0o755);
        self::assertSame([1, 0], [$locked->deleted, $locked->files], 'a file that can\'t be deleted doesn\'t stop the erasure');
        self::assertSame([], AvatarService::filesOf(null, AvatarKind::Uploaded));
        $directory->remove();
    }
}
