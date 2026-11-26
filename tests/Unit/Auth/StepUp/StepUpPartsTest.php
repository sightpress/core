<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\StepUp;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\StepUp\CriticalActions;
use SightPress\Core\Auth\StepUp\StepUpSession;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Tenant\TenantConfigException;
use SightPress\Core\Tenant\TwoStepPolicy;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\User\Contract\AccountPhone;
use SightPress\Core\User\Contract\AccountPhones;

#[CoversClass(StepUpSession::class)]
#[CoversClass(CriticalActions::class)]
#[CoversClass(TwoStepPolicy::class)]
#[UsesClass(Session::class)]
#[UsesClass(Actor::class)]
#[UsesClass(SiteVisibility::class)]
#[UsesClass(AccountPhone::class)]
#[UsesClass(TenantConfigException::class)]
final class StepUpPartsTest extends TestCase
{
    public function testAConfirmationIsBoundToTheSessionAndLastsTenMinutes(): void
    {
        $session = new Session('session-one');
        self::assertFalse(StepUpSession::isConfirmed($session, 1000));
        StepUpSession::confirm($session, 1000);
        self::assertTrue(StepUpSession::isConfirmed($session, 1000 + StepUpSession::WINDOW));
        self::assertFalse(StepUpSession::isConfirmed($session, 1001 + StepUpSession::WINDOW));
        self::assertFalse(StepUpSession::isConfirmed(new Session('session-two', $session->all()), 1000), 'copied to another session');
        $session->regenerate();
        self::assertFalse(StepUpSession::isConfirmed($session, 1000), 'a new sign-in');
    }

    public function testKeepsTheFormWithoutSecretsOrFiles(): void
    {
        $session = new Session('s');
        self::assertFalse(StepUpSession::hold($session, '/admin/x', '/admin/x', ['csrf_name' => 'n', 'csrf_value' => 'v', 'title' => 'Hello', 'roles' => ['editor', 3, 'admin'], 0 => 'odd', 'count' => 3], false));
        self::assertSame(['action' => '/admin/x', 'back' => '/admin/x', 'fields' => ['title' => 'Hello', 'roles' => ['editor', 'admin']], 'left_out' => false], StepUpSession::held($session));
        self::assertTrue(StepUpSession::hold($session, '/account/email', '/account', ['new_email' => 'a@b.example', 'current_password' => 'secret'], false));
        self::assertTrue(StepUpSession::hold($session, '/media', '/media', ['title' => 'x'], true), 'a file');
        StepUpSession::release($session);
        self::assertNull(StepUpSession::held($session));
        $session->set('auth.step_up.held', ['action' => 3]);
        self::assertNull(StepUpSession::held($session));
    }

    public function testCoresCriticalActions(): void
    {
        $settings = new ArraySiteSettings();
        $phones = self::createStub(AccountPhones::class);
        $phones->method('phoneOf')->willReturn(new AccountPhone('+447400123456', null, false, [], null));
        $actions = new CriticalActions($settings, $phones);
        $post = static fn (array $body, ?int $user = 3): ServerRequest => new ServerRequest('POST', '/')->withParsedBody($body)->withAttribute(Actor::class, new Actor($user));

        self::assertTrue($actions->isCritical('acme.anything', true, $post([])));
        self::assertTrue($actions->isCritical('features.list', false, $post([])));
        self::assertFalse($actions->isCritical('page.save', false, $post([])));
        self::assertTrue($actions->isCritical('assistant.settings', false, $post(['op' => 'service'])));
        self::assertFalse($actions->isCritical('assistant.settings', false, $post(['op' => 'limits'])));
        self::assertTrue($actions->isCritical('auth.account.phone', false, $post(['op' => 'remove'])));
        self::assertFalse($actions->isCritical('auth.account.phone', false, $post(['op' => 'remove'], null)), 'nobody signed in');
        self::assertFalse($actions->isCritical('admin.settings', false, $post(['visibility' => 'public'])));
        self::assertTrue($actions->isCritical('admin.settings', false, $post(['visibility' => 'members'])));
        $settings->set(SiteVisibility::SETTING, SiteVisibility::MEMBERS);
        self::assertFalse($actions->isCritical('admin.settings', false, $post(['visibility' => 'members'])));
        self::assertFalse($actions->isCritical('admin.settings', false, $post(['name' => 'Shop'])));
        self::assertFalse($actions->isCritical('people.invite', false, new ServerRequest('POST', '/')));
    }

    public function testTheTenantsPolicy(): void
    {
        self::assertSame([TwoStepPolicy::Optional, TwoStepPolicy::Required, TwoStepPolicy::Off], [TwoStepPolicy::fromValue(null), TwoStepPolicy::fromValue('required'), TwoStepPolicy::fromValue('off')]);
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage('Tenant setting "two_step" must be');
        TwoStepPolicy::fromValue(true);
    }
}
