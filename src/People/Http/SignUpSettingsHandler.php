<?php

declare(strict_types=1);

namespace SightPress\Core\People\Http;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Auth\Contract\SignUp;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\SignUpRoles;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Site\Contract\SiteSettings;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/settings/sign-up` — "Sign-up": whether anyone may create an account on the site (off by default: only
 * invited people join) and the role they get. The role list only has sign-up-safe roles (ADR-0014), and the server
 * refuses any other, whatever is sent. Needs `settings.manage`; changes are announced and audited
 * ({@see SettingsChanged}).
 */
final readonly class SignUpSettingsHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/settings/sign-up';

    public const string PERMISSION = 'settings.manage';

    /** The stored sign-up role (the rbac module's setting). */
    public const string ROLE_SETTING = 'rbac.registration_role';

    public function __construct(
        private AdminScreens $screens,
        private FormValidator $validator,
        private SiteSettings $settings,
        private SignUpRoles $registrationRole,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param array<string, string> $roles sign-up-safe roles, name => label
     */
    public static function form(array $roles): Form
    {
        return Form::create(
            'sign-up',
            new Field('open', 'Let anyone create an account', FieldType::Checkbox, hint: 'When this is off, only people you invite can join. When it is on, the sign-in page has a “Create an account” link, and people confirm their email address with a code before they can sign in.', required: false),
            new Field('role', 'Role of people who sign up', FieldType::Select, hint: 'Only roles that are safe to give to people you don’t know are listed. “Member” can read members-only pages and nothing more.', choices: $roles),
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(self::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $roles = $this->registrationRole->choices();
        $open = SignUp::isOpen($this->settings->get(SignUp::SETTING));
        $current = $this->registrationRole->forNewAccount();
        $form = self::form($roles)->withValues(['open' => $open ? '1' : '', 'role' => $current]);
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            $form = $this->validator->submit(self::form($roles), is_array($body) ? $body : []);
            if ($form->isValid()) {
                try {
                    $changed = $this->save($form, $open);
                } catch (\DomainException $exception) {
                    $changed = null;
                    $form = $form->withErrors(['role' => $exception->getMessage()]);
                }
                if ($changed !== null) {
                    if ($changed !== []) {
                        $this->events->dispatch(new SettingsChanged($changed, $actor->userId));
                    }

                    return $this->screens->redirect($request, self::PATH, $changed === [] ? 'Nothing was changed.' : 'The sign-up settings have been saved.');
                }
            }
        }

        return $this->screens->render($request, '@people/sign-up.html.twig', [
            'form' => $form,
            'action' => self::PATH,
            'open' => $open,
            'role' => $roles[$current] ?? $current,
            'settings_path' => '/admin/settings',
        ], $form->submitted && !$form->isValid() ? 422 : 200);
    }

    /**
     * @return list<string> keys of the settings that changed
     *
     * @throws \DomainException when the role isn't sign-up-safe
     */
    private function save(Form $form, bool $open): array
    {
        $changed = [];
        $role = $form->text('role');
        if ($role !== $this->registrationRole->name()) {
            $this->registrationRole->change($role);
            $changed[] = self::ROLE_SETTING;
        }
        $wanted = $form->value('open') === '1';
        if ($wanted !== $open) {
            $this->settings->set(SignUp::SETTING, $wanted ? '1' : '0');
            $changed[] = SignUp::SETTING;
        }

        return $changed;
    }
}
