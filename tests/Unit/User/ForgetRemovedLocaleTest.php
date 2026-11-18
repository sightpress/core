<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\User;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Site\Contract\LocaleRemoved;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\ForgetRemovedLocale;
use SightPress\Core\User\UserRepository;

#[CoversClass(ForgetRemovedLocale::class)]
#[CoversClass(UserRepository::class)]
#[UsesNamespace('SightPress\Core')]
final class ForgetRemovedLocaleTest extends TestCase
{
    public function testPeopleWhoChoseTheRemovedLanguageGetTheMainOneAgain(): void
    {
        $database = new CoreSiteDatabase();
        $users = new UserRepository($database->entityManager);
        $ana = new User('ana@example.org', 'Ana', 'Silva', 'UTC', new DateTimeImmutable(), locale: 'pt-PT');
        $bo = new User('bo@example.org', 'Bo', 'Berg', 'UTC', new DateTimeImmutable(), locale: 'sv-SE');
        $users->save($ana);
        $users->save($bo);

        new ForgetRemovedLocale($users)(new LocaleRemoved('pt-PT', 1));

        $database->entityManager->clear();
        self::assertNull($users->find((int) $ana->id())?->locale());
        self::assertSame('sv-SE', $users->find((int) $bo->id())?->locale());
        self::assertSame(0, $users->forgetLocale('pt-PT'));
    }
}
