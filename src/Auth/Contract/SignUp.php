<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

/**
 * Where people make their own account (self-registration, U-1; the pages belong to the people module): the site
 * setting that opens it (off by default), the sign-up form and the page where the emailed code is typed. The sign-in
 * page links to the form while it is open, and sends someone who signed up but hasn't typed their code yet to the
 * code page.
 */
final class SignUp
{
    /** `1` when anyone may make an account; anything else (or nothing) when only invited people can. */
    public const string SETTING = 'people.registration';

    public const string PATH = '/register';

    public const string CODE_PATH = '/register/code';

    /**
     * Whether the stored value of {@see SETTING} opens sign-up.
     */
    public static function isOpen(?string $stored): bool
    {
        return $stored === '1';
    }
}
