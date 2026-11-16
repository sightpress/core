<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Security\Authenticator;
use SightPress\Core\Http\Security\GrantedPermissions;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\PermissionLookup;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\AccountPreferences;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Core\Template\ViewerPreferences;

/**
 * Finds the signed-in user from the session, or from a "keep me signed in" cookie, and puts the {@see Actor} and
 * their {@see GrantedPermissions} on the request. A session whose account was deactivated, or whose password
 * changed since it signed in, is signed out.
 */
final readonly class SessionAuthenticator implements Authenticator
{
    /** Request attribute with a `Set-Cookie` value to send with the response. */
    public const string COOKIE_ATTRIBUTE = self::class . '.cookie';

    public function __construct(
        private Accounts $accounts,
        private PermissionLookup $permissions,
        private RememberTokens $rememberTokens,
        private SignIn $signIn,
        private AccountPreferences $preferences,
    ) {
    }

    public function authenticate(ServerRequestInterface $request): ServerRequestInterface
    {
        $account = $this->fromSession($request);
        if ($account === null && RememberTokens::hasCookie($request)) {
            [$account, $cookie] = $this->fromCookie($request);
            $request = $request->withAttribute(self::COOKIE_ATTRIBUTE, $cookie);
        }
        if ($account === null) {
            return $request;
        }

        $id = (int) $account->id();
        $permissions = $this->permissions->permissionNamesOf($id);

        $request = $request
            ->withAttribute(Actor::class, new Actor($id, $permissions))
            ->withAttribute(GrantedPermissions::class, new GrantedPermissions($permissions));
        $preferences = $this->preferences->preferencesOf($id);

        return $preferences === null ? $request : $request->withAttribute(ViewerPreferences::class, $preferences);
    }

    public function respond(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cookie = $request->getAttribute(self::COOKIE_ATTRIBUTE);

        return is_string($cookie) ? $response->withAddedHeader('Set-Cookie', $cookie) : $response;
    }

    private function fromSession(ServerRequestInterface $request): ?Account
    {
        $id = $this->signIn->userId($request);
        if ($id === null) {
            return null;
        }

        $account = $this->accounts->findAccount($id);
        if ($account === null || !$account->isActive() || !$this->signIn->isCurrent($request, $account)) {
            $session = Session::fromRequest($request);
            $session->remove(SignIn::SESSION_USER);
            $session->remove(SignIn::SESSION_SITE);
            $session->remove(SignIn::SESSION_STAMP);

            return null;
        }

        return $account;
    }

    /**
     * @return array{?Account, string} the account and the `Set-Cookie` value: a new token, or one that removes a
     *                                 cookie that no longer works
     */
    private function fromCookie(ServerRequestInterface $request): array
    {
        $id = $this->rememberTokens->consume($request);
        $account = $id === null ? null : $this->accounts->findAccount($id);
        if ($account === null || !$account->isActive()) {
            return [null, $this->rememberTokens->forget($request)];
        }

        $this->signIn->start($request, $account);

        return [$account, $this->rememberTokens->issue($id, $request->getUri()->getScheme() === 'https')];
    }
}
