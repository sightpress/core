<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Template;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * Interface translations through the whole kernel (I-3): an admin switches the admin panel to Spanish with the switcher
 * in its header; the screens, the messages and the error pages are then in Spanish, each language in the switcher is
 * named in its own words with its `lang`, and a visitor's 404 on a Portuguese address is in Portuguese.
 */
#[CoversNothing]
final class AdminInSpanishTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('spanish', locales: ['en-GB', 'pt-PT']);
        $container = $this->site->container();
        $now = new DateTimeImmutable();
        $user = new User('ada@example.org', 'Ada', 'Lovelace', 'Europe/London', $now);
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), $now);
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole('admin') ?? throw new \LogicException('admin'));
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testTheAdminPanelErrorsAndMessagesFollowTheChosenLanguage(): void
    {
        $ada = new Browser($this->site->kernel, 'https://example.org');
        $ada->submit('/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD]);
        $english = (string) $ada->request('GET', '/admin')->getBody();
        self::assertStringContainsString('<html lang="en-GB" dir="ltr">', $english);
        self::assertStringContainsString('<option value="es" lang="es">Español</option>', $english);
        self::assertStringContainsString('<option value="pt-PT" lang="pt-PT">Português europeu</option>', $english);

        $switched = $ada->submit('/admin', ['locale' => 'es', 'return' => '/admin/pages'], '/account/language');
        self::assertSame([303, '/admin/pages'], [$switched->getStatusCode(), $switched->getHeaderLine('Location')]);
        $pages = (string) $ada->request('GET', '/admin/pages')->getBody();
        self::assertStringContainsString('<html lang="es" dir="ltr">', $pages);
        self::assertStringContainsString('<title>Páginas - Administración - ', $pages);
        self::assertStringContainsString('La administración está ahora en Español.', $pages);
        self::assertStringContainsString('Saltar al contenido principal', $pages);

        $invalid = (string) $ada->submit('/admin/settings', ['name' => str_repeat('x', 121), 'timezone' => 'Europe/London'])->getBody();
        self::assertStringContainsString('Hay un problema', $invalid);
        self::assertStringContainsString('El nombre del sitio puede tener como máximo 120 caracteres', $invalid);

        $missing = $ada->request('GET', '/admin/pages/99999');
        self::assertSame(404, $missing->getStatusCode());
        self::assertStringContainsString('<html lang="es" dir="ltr">', (string) $missing->getBody());

        $visitor = new Browser($this->site->kernel, 'https://example.org');
        $portuguese = (string) $visitor->request('GET', '/pt-pt/nada')->getBody();
        self::assertStringContainsString('<html lang="pt-PT" dir="ltr">', $portuguese);
        self::assertStringContainsString('Página não encontrada', $portuguese);
    }
}
