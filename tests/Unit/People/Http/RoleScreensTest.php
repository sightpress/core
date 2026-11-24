<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\People\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\People\Http\DeleteRoleHandler;
use SightPress\Core\People\Http\RoleHandler;
use SightPress\Core\People\Http\RolesHandler;
use SightPress\Core\Rbac\Contract\RoleEdited;
use SightPress\Core\Rbac\RoleAdministration;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\Tests\Support\Text;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(RolesHandler::class)]
#[CoversClass(RoleHandler::class)]
#[CoversClass(DeleteRoleHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class RoleScreensTest extends TestCase
{
    private AdminFixture $fixture;

    private RecordingEvents $events;

    private Session $session;

    private User $admin;

    private RoleAdministration $roles;

    protected function setUp(): void
    {
        $this->fixture = new AdminFixture();
        $this->events = new RecordingEvents();
        $this->session = new Session();
        $this->admin = $this->fixture->auth->user('ada@example.org', 'admin');
        $this->roles = $this->fixture->auth->database->roleAdministration($this->events);
    }

    public function testOnlyPeopleWhoManageRolesMayUseTheScreens(): void
    {
        $editor = $this->fixture->auth->user('bea@example.org', 'editor');
        foreach ([[$this->list(), RolesHandler::PATH, []], [$this->role(), RoleHandler::NEW_PATH, []], [$this->delete(), '/admin/roles/editor/delete', ['name' => 'editor']]] as [$handler, $path, $attributes]) {
            try {
                $this->handle($handler, 'GET', $path, user: $editor, attributes: $attributes);
                self::fail('An editor got in.');
            } catch (HttpForbiddenException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testListsEveryRoleWithWhatItHolds(): void
    {
        $this->roles->create(null, 'shop', 'Shop staff', ['media.upload']);
        $response = $this->handle($this->list(), 'GET', RolesHandler::PATH);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>Roles - Admin - Demo</title>', $html);
        self::assertStringContainsString('<a class="button" href="/admin/roles/new">Create a role</a>', $html);
        self::assertStringContainsString('<caption id="roles-caption">Every role on the site</caption>', $html);
        self::assertMatchesRegularExpression('#<a href="/admin/roles/admin">Admin</a></th>\s*<td>All</td>\s*<td>1</td>\s*<td>Built in, can’t be changed</td>#', $html);
        self::assertMatchesRegularExpression('#<a href="/admin/roles/member">Member</a></th>\s*<td>0</td>\s*<td>0</td>\s*<td>Built in, can’t be changed. Role of people who sign up</td>#', $html);
        self::assertMatchesRegularExpression('#<a href="/admin/roles/shop">Shop staff</a></th>\s*<td>2</td>\s*<td>0</td>\s*<td></td>#', $html);
    }

    public function testANewRoleHasAGroupOfCheckboxesForEachArea(): void
    {
        $html = (string) $this->handle($this->role(), 'GET', RoleHandler::NEW_PATH)->getBody();

        self::assertStringContainsString('<h1>Create a role</h1>', $html);
        self::assertStringContainsString('<label for="role-label">Name of the role</label>', $html);
        self::assertStringContainsString('<legend>Pages (optional)</legend>', $html);
        self::assertStringContainsString('<legend>Roles (optional)</legend>', $html);
        self::assertLessThan(strpos($html, '<legend>Pages (optional)</legend>'), strpos($html, '<legend>Admin panel (optional)</legend>'));
        self::assertMatchesRegularExpression('#<input type="checkbox" id="role-area-page-\d+" name="area_page\[\]" value="page.publish" aria-describedby="role-area-page-\d+-hint">\s*<label for="role-area-page-\d+">Publish pages</label>\s*<p class="field-hint field-option__hint" id="role-area-page-\d+-hint">Approve, publish and unpublish pages.</p>#', $html);
        self::assertStringContainsString('<button type="submit">Create role</button>', $html);
        self::assertStringNotContainsString('Delete this role', $html);
    }

    public function testCreatesARoleFromTheTickedBoxes(): void
    {
        $response = $this->handle($this->role(), 'POST', RoleHandler::NEW_PATH, ['label' => 'Shop staff', 'area_media' => ['media.upload', 'media.publish'], 'area_menu' => ['menu.manage']]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/roles/shop_staff', $response->getHeaderLine('Location'));
        self::assertSame(['Created the role “Shop staff”. Give it to people on their page in People.'], $this->flash());
        self::assertSame(['admin.access', 'media.publish', 'media.upload', 'menu.manage'], $this->roles->find('shop_staff')?->permissions);
        self::assertInstanceOf(RoleEdited::class, $this->events->events[0]);

        $html = (string) $this->handle($this->role(), 'GET', '/admin/roles/shop_staff', attributes: ['name' => 'shop_staff'])->getBody();
        self::assertStringContainsString('<h1>Shop staff</h1>', $html);
        self::assertStringContainsString('Nobody has this role. On the command line it is called shop_staff.', $html);
        self::assertStringContainsString('value="Shop staff"', $html);
        self::assertMatchesRegularExpression('#value="menu.manage" checked#', $html);
        self::assertDoesNotMatchRegularExpression('#value="page.publish" checked#', $html);
        self::assertStringContainsString('<a href="/admin/roles/shop_staff/delete">Delete this role</a>', $html);
    }

    public function testSavesChangesOrSaysNothingChanged(): void
    {
        $this->roles->create(null, 'shop', 'Shop staff', ['media.upload']);
        $path = '/admin/roles/shop';

        $response = $this->handle($this->role(), 'POST', $path, ['label' => 'Shop team', 'area_media' => ['media.upload'], 'area_admin' => ['admin.access']], attributes: ['name' => 'shop']);
        self::assertSame($path, $response->getHeaderLine('Location'));
        self::assertSame(['Saved the role “Shop team”.'], $this->flash());

        $this->handle($this->role(), 'POST', $path, ['label' => 'Shop team', 'area_media' => ['media.upload']], attributes: ['name' => 'shop']);
        self::assertSame(['Nothing was changed.'], $this->flash());
    }

    public function testARefusalIsShownOnItsFieldWithTheBoxesStillTicked(): void
    {
        $this->fixture->auth->database->registrationRole()->change('contributor');

        $response = $this->handle($this->role(), 'POST', '/admin/roles/contributor', ['label' => 'Editor', 'area_page' => ['page.create', 'page.publish']], attributes: ['name' => 'contributor']);
        $html = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('People who sign up get this role, so it can only have permissions that are safe for people you don’t know.', $html);
        self::assertStringContainsString('<title>2 errors: Contributor - Admin - Demo</title>', $html);
        self::assertStringContainsString('<a href="#role-label">There is already a role called “Editor”. Choose another name.</a>', $html);
        self::assertStringContainsString('<a href="#role-area-page">“Contributor” is the role of people who sign up, and they can’t get “Publish pages”', $html);
        self::assertMatchesRegularExpression('#value="page.publish" checked#', $html);
        self::assertSame([], $this->events->events);
    }

    public function testAFormThatIsntValidIsShownAgain(): void
    {
        $response = $this->handle($this->role(), 'POST', RoleHandler::NEW_PATH, ['label' => '', 'area_page' => ['acme.forged']]);
        $html = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Enter name of the role', $html);
        self::assertStringContainsString('Select only the options shown for “Pages”', $html);
        self::assertNull($this->roles->find('role'));
    }

    public function testAdminAndMemberOnlySayWhatTheyCanDo(): void
    {
        $html = (string) $this->handle($this->role(), 'GET', '/admin/roles/admin', attributes: ['name' => 'admin'])->getBody();
        self::assertStringContainsString('Admins can do everything, including managing roles.', $html);
        self::assertStringContainsString('1 person has this role.', $html);
        self::assertStringContainsString('<li>Publish pages</li>', $html);
        self::assertStringNotContainsString('<form id="role"', $html);

        $html = (string) $this->handle($this->role(), 'GET', '/admin/roles/member', attributes: ['name' => 'member'])->getBody();
        self::assertStringContainsString('Members can sign in and read members-only pages', $html);
        self::assertStringNotContainsString('<h2>Permissions</h2>', $html);

        $response = $this->handle($this->role(), 'POST', '/admin/roles/member', ['label' => 'Member', 'area_page' => ['page.create']], attributes: ['name' => 'member']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<p>The Member role never has any permission, so it can’t be changed.</p>', (string) $response->getBody());
        self::assertStringContainsString('<title>1 error: Member - Admin - Demo</title>', (string) $response->getBody());
        self::assertSame([], $this->roles->find('member')?->permissions);
    }

    public function testAnUnknownRoleIsNotFound(): void
    {
        $this->expectException(HttpNotFoundException::class);

        $this->handle($this->role(), 'GET', '/admin/roles/ghost', attributes: ['name' => 'ghost']);
    }

    public function testDeletingAsksFirstThenDeletes(): void
    {
        $this->roles->create(null, 'helper', 'Helper', []);
        $path = '/admin/roles/helper/delete';

        $html = (string) $this->handle($this->delete(), 'GET', $path, attributes: ['name' => 'helper'])->getBody();
        self::assertStringContainsString('<h1>Delete the role “Helper”?</h1>', $html);
        self::assertStringContainsString('<button type="submit" class="button button--danger">Delete the role</button>', $html);
        self::assertStringContainsString('<a href="/admin/roles/helper">Keep it</a>', $html);

        $response = $this->handle($this->delete(), 'POST', $path, attributes: ['name' => 'helper']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/roles', $response->getHeaderLine('Location'));
        self::assertSame(['Deleted the role “Helper”.'], $this->flash());
        self::assertNull($this->roles->find('helper'));
    }

    public function testARoleThatCantBeDeletedSaysWhy(): void
    {
        $html = (string) $this->handle($this->delete(), 'GET', '/admin/roles/admin/delete', attributes: ['name' => 'admin'])->getBody();
        self::assertStringContainsString('“Admin” is built in, so it can’t be deleted.', $html);
        self::assertStringNotContainsString('button--danger', $html);

        $response = $this->handle($this->delete(), 'POST', '/admin/roles/admin/delete', attributes: ['name' => 'admin']);
        self::assertSame(422, $response->getStatusCode());
        self::assertNotNull($this->roles->find('admin'));
    }

    public function testDeletingAnUnknownRoleIsNotFound(): void
    {
        $this->expectException(HttpNotFoundException::class);

        $this->handle($this->delete(), 'GET', '/admin/roles/ghost/delete', attributes: ['name' => 'ghost']);
    }

    private function list(): RolesHandler
    {
        return new RolesHandler($this->fixture->shell(), $this->roles);
    }

    private function role(): RoleHandler
    {
        return new RoleHandler($this->fixture->shell(), $this->fixture->auth->validator(), $this->roles);
    }

    private function delete(): DeleteRoleHandler
    {
        return new DeleteRoleHandler($this->fixture->shell(), $this->roles);
    }

    private function flash(): mixed
    {
        return Text::of(array_map(strval(...), Flash::take($this->session)));
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $attributes
     */
    private function handle(RequestHandlerInterface $handler, string $method, string $path, array $body = [], ?User $user = null, array $attributes = []): ResponseInterface
    {
        $request = $this->fixture->request($user ?? $this->admin, $method, $path, $body, $this->session);
        foreach ($attributes as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $handler->handle($request);
    }
}
