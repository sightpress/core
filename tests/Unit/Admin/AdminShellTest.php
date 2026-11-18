<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Admin\AdminShell;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Tests\Support\AdminFixture;

#[CoversClass(AdminShell::class)]
#[UsesNamespace('SightPress\Core')]
final class AdminShellTest extends TestCase
{
    private AdminFixture $admin;

    protected function setUp(): void
    {
        $this->admin = new AdminFixture();
    }

    public function testRendersTheScreenInTheShellWithTheNavigationThePersonMayUse(): void
    {
        $ada = $this->admin->auth->user();
        $session = new Session();
        Flash::add($session, 'Your changes have been saved.');

        $response = $this->admin->shell()->render($this->admin->request($ada, session: $session), '@admin/dashboard.html.twig', [
            'drafts' => null,
            'reviews' => null,
            'activity' => [],
        ]);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertStringContainsString('<title>Dashboard - Admin - Demo</title>', $html);
        self::assertStringContainsString('<a class="skip-link" href="#content">Skip to main content</a>', $html);
        self::assertStringContainsString('<nav class="admin-nav" aria-label="Admin">', $html);
        self::assertStringContainsString('<a href="/admin" aria-current="page">Dashboard</a>', $html);
        self::assertStringContainsString('Signed in as Ada', $html);
        self::assertStringContainsString('<form action="/logout" method="post">', $html);
        self::assertMatchesRegularExpression('#<div class="messages" role="status">\s*<p>Your changes have been saved.</p>#', $html);
        self::assertSame([], array_map(strval(...), Flash::take($session)));
        self::assertMatchesRegularExpression('#<html lang="en-GB" dir="ltr">#', $html, 'no admin preferences, no classes');
    }

    public function testTheAdminPreferencesBecomeClassesOnTheRootElement(): void
    {
        $ada = $this->admin->auth->user();
        $render = fn (\SightPress\Core\Template\ViewerPreferences $preferences): string => (string) $this->admin->shell()->render(
            $this->admin->request($ada)->withAttribute(\SightPress\Core\Template\ViewerPreferences::class, $preferences),
            '@admin/dashboard.html.twig',
            ['drafts' => null, 'reviews' => null, 'activity' => []],
        )->getBody();

        self::assertStringContainsString('<html lang="en-GB" dir="ltr" class="admin-reduced-motion admin-larger-text">', $render(new \SightPress\Core\Template\ViewerPreferences(reducedMotion: true, largerText: true)));
        self::assertStringContainsString('<html lang="en-GB" dir="ltr" class="admin-larger-text">', $render(new \SightPress\Core\Template\ViewerPreferences(largerText: true)));
        self::assertStringContainsString('<html lang="en-GB" dir="ltr">', $render(new \SightPress\Core\Template\ViewerPreferences()));
    }

    public function testPutsTheNumberOfErrorsInTheTitle(): void
    {
        $form = Form::create('demo', new Field('first', 'First'), new Field('second', 'Second'));
        $request = $this->admin->request(null);

        $one = (string) $this->admin->shell()->render($request, '@admin/dashboard.html.twig', [
            'drafts' => null, 'reviews' => null, 'activity' => [], 'form' => $form->withErrors(['first' => 'Enter a first']),
        ])->getBody();
        $two = (string) $this->admin->shell()->render($request, '@admin/dashboard.html.twig', [
            'drafts' => null, 'reviews' => null, 'activity' => [],
            'form' => $form->withErrors(['first' => 'Enter a first', 'second' => 'Enter a second']),
        ], 422)->getBody();

        self::assertStringContainsString('<title>1 error: Dashboard - Admin - Demo</title>', $one);
        self::assertStringContainsString('<title>2 errors: Dashboard - Admin - Demo</title>', $two);
        self::assertStringNotContainsString('Signed in as', $two);
    }

    public function testRedirectsWithAMessageForTheNextScreen(): void
    {
        $session = new Session();
        $request = $this->admin->request(null, session: $session);

        $response = $this->admin->shell()->redirect($request, '/admin/users', 'Ada has been invited.');
        $quiet = $this->admin->shell()->redirect($request, '/admin');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/users', $response->getHeaderLine('Location'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('/admin', $quiet->getHeaderLine('Location'));
        self::assertSame(['Ada has been invited.'], array_map(strval(...), Flash::take($session)));
    }
}
