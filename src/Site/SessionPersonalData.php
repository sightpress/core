<?php

declare(strict_types=1);

namespace SightPress\Core\Site;

use SightPress\Core\Http\Session\PersonSessions;
use SightPress\Core\Http\Session\SessionMiddleware;
use SightPress\Core\Http\Session\SessionStore;
use SightPress\Module\Privacy\CookieDeclaration;
use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * The sign-in sessions a site keeps (H-9), for the privacy tools: what they are, *Download your data* (when each of
 * the person's sessions was last used and when it ends, never its id or contents) and *Delete your account* (every
 * session of the person ends, in whichever store `session.store` chose). Sessions of visitors who aren't signed in
 * hold no one's identity and end by themselves.
 */
final readonly class SessionPersonalData implements PersonalDataProvider
{
    public const string KEY = 'site.sessions';

    public function __construct(private SessionStore $store)
    {
    }

    /**
     * The session cookie, under both its names (the `__Host-` one over HTTPS).
     *
     * @return list<CookieDeclaration>
     */
    public static function cookies(): array
    {
        $purpose = new Message('Keeps you signed in and protects the forms you send. It is only set once you sign in or fill in a form, and ends when you close the browser.');

        return [
            new CookieDeclaration(SessionMiddleware::SECURE_COOKIE, $purpose, null, true),
            new CookieDeclaration(SessionMiddleware::COOKIE, $purpose, null, true),
        ];
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Sign-in sessions'),
            new Message('To keep you signed in, to protect the forms you send and to show you messages about what you just did.'),
            [PersonalDataCategory::Technical, PersonalDataCategory::Account],
            LawfulBasis::Contract,
            Retention::days(1),
            notes: new Message('A session ends after a time without using the site (2 hours, unless whoever runs the site changed it) or when you sign out, and is then deleted. Only a scrambled form of its code is kept.'),
        );
    }

    public function export(UserRef $user): iterable
    {
        return $this->store instanceof PersonSessions ? $this->store->forPerson($user->userId) : [];
    }

    public function erase(UserRef $user): ErasureResult
    {
        return $this->store instanceof PersonSessions
            ? new ErasureResult(deleted: $this->store->forgetPerson($user->userId))
            : ErasureResult::nothing();
    }
}
