<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\StepUp;

use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\User\Contract\AccountPhones;

/**
 * Core's critical actions (N-6, plan §1.2 *Two-step confirmation*): changing your own password, email address or
 * mobile number; inviting an admin or editor; changing a role, a person's role or status, or removing their number;
 * who can read the site and who may register; security settings; turning features on or off and deleting their data;
 * API keys (AI assistant, cloud storage); deleting analytics data. A module's route is critical when it says so
 * (`Route::$critical`).
 */
final readonly class CriticalActions
{
    /** Routes whose every change is critical. */
    public const array ALWAYS = [
        'auth.account.password', 'auth.account.email',
        'people.user', 'people.user.status', 'people.user.phone', 'people.sign_up',
        'people.role.new', 'people.role', 'people.role.delete',
        'features.list', 'features.turn_off', 'features.delete_data',
        'admin.storage', 'analytics.delete', 'security.settings',
    ];

    public function __construct(
        private SiteSettings $settings,
        private AccountPhones $phones,
    ) {
    }

    /**
     * @param bool $flagged the module marked the route critical
     */
    public function isCritical(?string $route, bool $flagged, ServerRequestInterface $request): bool
    {
        if ($flagged || in_array($route, self::ALWAYS, true)) {
            return true;
        }
        $body = $request->getParsedBody();
        $field = static fn (string $name): string => is_array($body) && is_string($body[$name] ?? null) ? $body[$name] : '';

        return match ($route) {
            // Adding a first number can't wait for a code to a number; changing or removing one can.
            'auth.account.phone' => in_array($field('op'), ['number', 'remove'], true) && $this->hasNumber($request),
            'people.invite' => in_array($field('role'), ['admin', 'editor'], true),
            'admin.settings' => $field('visibility') !== '' && $field('visibility') !== (SiteVisibility::isMembersOnly($this->settings) ? SiteVisibility::MEMBERS : SiteVisibility::PUBLIC),
            'assistant.settings' => in_array($field('op'), ['service', 'remove_key'], true),
            default => false,
        };
    }

    private function hasNumber(ServerRequestInterface $request): bool
    {
        $id = Actor::fromRequest($request)->userId;

        return $id !== null && ($this->phones->phoneOf($id)?->hasNumber() ?? false);
    }
}
