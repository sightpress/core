<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use SightPress\Module\Access\Permission;

/**
 * Core's permission catalogue, registered by the RBAC module (the user module registers `user.invite` and
 * `user.manage`). Only `admin.access`, `page.create`, `page.edit.own`, `page.submit` and `media.upload` are allowed
 * on sign-up (ADR-0014).
 */
final class CorePermissions
{
    public const string ADMIN_ACCESS = 'admin.access';

    /**
     * @return list<Permission>
     */
    public static function all(): array
    {
        $staff = [RoleName::ADMIN, RoleName::EDITOR, RoleName::CONTRIBUTOR];
        $editors = [RoleName::ADMIN, RoleName::EDITOR];
        $admins = [RoleName::ADMIN];

        return [
            new Permission(self::ADMIN_ACCESS, 'Use the admin panel', 'Sign in to the admin panel.', $staff, true),
            new Permission('page.create', 'Create pages', 'Start new pages.', $staff, true),
            new Permission('page.edit.own', 'Edit own pages', 'Change pages they wrote.', $staff, true),
            new Permission('page.edit.any', 'Edit any page', 'Change pages anyone wrote.', $editors),
            new Permission('page.submit', 'Submit pages for review', 'Send a page to the editors.', $staff, true),
            new Permission('page.publish', 'Publish pages', 'Approve, publish and unpublish pages.', $editors),
            new Permission('page.archive', 'Archive pages', 'Take pages off the site and keep them.', $editors),
            new Permission('page.delete', 'Delete pages', 'Delete pages for good.', $editors),
            new Permission('media.upload', 'Upload media', 'Add pictures, video, audio and files.', $staff, true),
            new Permission('media.publish', 'Publish media', 'Make uploaded files public.', $editors),
            new Permission('menu.manage', 'Manage menus', 'Change the main and footer menus.', $editors),
            new Permission('role.manage', 'Manage roles', 'Create roles and change what they allow.', $admins),
            new Permission('settings.manage', 'Manage site settings', 'Change the site\'s name, profile, branding and other settings.', $admins),
            new Permission('theme.apply', 'Apply themes', 'Choose the site\'s theme.', $admins),
            new Permission('audit.view', 'View the audit log', 'See who changed what, and when.', $admins),
        ];
    }
}
