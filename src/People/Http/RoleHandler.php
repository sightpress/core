<?php

declare(strict_types=1);

namespace SightPress\Core\People\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Rbac\Contract\PermissionAreas;
use SightPress\Core\Rbac\Contract\PermissionSummary;
use SightPress\Core\Rbac\Contract\RoleRefused;
use SightPress\Core\Rbac\Contract\Roles;
use SightPress\Core\Rbac\Contract\RoleSummary;
use SightPress\Core\Template\Message;
use Slim\Exception\HttpNotFoundException;

/**
 * `/admin/roles/new` and `/admin/roles/{name}`: a role's name and its permissions, one group of checkboxes (a
 * `<fieldset>` with a `<legend>`) per area, each permission with what it allows in plain words. Permissions of
 * features that are off aren't shown, and the role keeps them. Admin and Member can't be changed, so their page only
 * says what they can do. Every refusal of {@see Roles} is shown on its field, with the boxes still as ticked. Needs
 * `role.manage` (X-4).
 */
final readonly class RoleHandler implements RequestHandlerInterface
{
    public const string NEW_PATH = RolesHandler::PATH . '/new';

    public const string NAME_ATTRIBUTE = 'name';

    public function __construct(
        private AdminScreens $screens,
        private FormValidator $validator,
        private Roles $roles,
    ) {
    }

    public static function path(string $name): string
    {
        return RolesHandler::PATH . '/' . $name;
    }

    /**
     * The role's form: its label, then a group of checkboxes for each area.
     *
     * @param list<PermissionSummary> $permissions those that can be given
     */
    public static function form(array $permissions): Form
    {
        $areas = [];
        foreach ($permissions as $permission) {
            $areas[PermissionAreas::of($permission->name)][] = $permission;
        }
        $fields = [new Field('label', 'Name of the role', hint: 'For example “Shop staff”. It is shown next to each person who has it.', autocomplete: 'off')];
        foreach (PermissionAreas::sort(array_keys($areas)) as $area) {
            $choices = [];
            $hints = [];
            foreach ($areas[$area] as $permission) {
                $choices[$permission->name] = Message::of($permission->label);
                $hints[$permission->name] = Message::of($permission->description);
            }
            $fields[] = new Field(self::field($area), PermissionAreas::label($area), FieldType::Checkboxes, required: false, choices: $choices, choiceHints: $hints);
        }

        return Form::create('role', ...$fields);
    }

    /**
     * The name of the form field that holds an area's permissions.
     */
    public static function field(string $area): string
    {
        return 'area_' . $area;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RolesHandler::actor($request);
        $role = null;
        $name = $request->getAttribute(self::NAME_ATTRIBUTE);
        if (is_string($name)) {
            $role = $this->roles->find($name) ?? throw new HttpNotFoundException($request);
        }
        $permissions = $this->roles->permissions();
        $form = self::form($permissions);
        if ($role !== null) {
            $form = $form->withValues(['label' => $role->label, ...self::ticked($form, $role->permissions)]);
        }
        $post = $request->getMethod() === 'POST';
        if ($role !== null && $role->fixed) {
            return $this->show($request, $role, $form, $permissions, $post ? $this->roles->problems($actor, $role->name, $role->name, $role->label, [])['role'] ?? null : null);
        }
        if (!$post) {
            return $this->show($request, $role, $form, $permissions);
        }

        $body = $request->getParsedBody();
        $form = $this->validator->submit(self::form($permissions), is_array($body) ? $body : []);
        if (!$form->isValid()) {
            return $this->show($request, $role, $form, $permissions);
        }
        $label = $form->text('label');
        $wanted = self::wanted($form);
        try {
            if ($role === null) {
                $created = $this->roles->create($actor, $this->roles->nameFor($label), $label, $wanted);

                return $this->screens->redirect($request, self::path($created->name), new Message('Created the role “{role}”. Give it to people on their page in People.', ['role' => Message::of($created->label)]));
            }
            $changed = $this->roles->update($actor, $role->name, $label, $wanted);
        } catch (RoleRefused $refused) {
            return $this->show($request, $role, $form->withErrors(self::errors($form, $refused)), $permissions);
        }

        return $this->screens->redirect($request, self::path($role->name), $changed
            ? new Message('Saved the role “{role}”.', ['role' => Message::of($label)])
            : new Message('Nothing was changed.'));
    }

    /**
     * @param list<PermissionSummary> $permissions
     */
    private function show(ServerRequestInterface $request, ?RoleSummary $role, Form $form, array $permissions, ?Message $error = null): ResponseInterface
    {
        $labels = [];
        foreach ($permissions as $permission) {
            if (in_array($permission->name, $role->permissions ?? [], true)) {
                $labels[] = Message::of($permission->label);
            }
        }

        return $this->screens->render($request, '@people/role.html.twig', [
            'role' => $role,
            'form' => $form,
            'areas' => array_values(array_filter(array_map(static fn (Field $field): string => $field->name, $form->fields()), static fn (string $name): bool => $name !== 'label')),
            'permissions' => $labels,
            'error' => $error,
            'error_count' => $error === null ? count($form->errors()) : 1,
            'action' => $role === null ? self::NEW_PATH : self::path($role->name),
            'delete' => $role === null ? null : DeleteRoleHandler::path($role->name),
        ], $error !== null || ($form->submitted && !$form->isValid()) ? 422 : 200);
    }

    /**
     * Form values that tick these permissions.
     *
     * @param list<string> $names
     *
     * @return array<string, list<string>>
     */
    private static function ticked(Form $form, array $names): array
    {
        $values = [];
        foreach ($form->fields() as $field) {
            if ($field->type === FieldType::Checkboxes) {
                $values[$field->name] = array_values(array_intersect($field->choiceValues(), $names));
            }
        }

        return $values;
    }

    /**
     * Every ticked permission.
     *
     * @return list<string>
     */
    private static function wanted(Form $form): array
    {
        $wanted = [];
        foreach ($form->values() as $value) {
            if (is_array($value)) {
                $wanted = [...$wanted, ...$value];
            }
        }

        return $wanted;
    }

    /**
     * The refusal's problems on the fields they are about: a permission on its area, the rest on the name.
     *
     * @return array<string, Message>
     */
    private static function errors(Form $form, RoleRefused $refused): array
    {
        $errors = [];
        foreach ($refused->problems as $key => $problem) {
            $field = self::field(PermissionAreas::of($key));
            $errors[in_array($key, ['label', 'name', 'role'], true) || !$form->has($field) ? 'label' : $field] ??= $problem;
        }

        return $errors;
    }
}
