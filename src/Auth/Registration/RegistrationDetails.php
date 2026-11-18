<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Registration;

/**
 * What someone typed in the sign-up form. Never their role: a `role` or `roles[]` field sent with the form is not
 * read.
 */
final readonly class RegistrationDetails
{
    /**
     * @param string  $timezone IANA time zone
     * @param ?string $locale   one of the site's languages, or null for its main language
     */
    public function __construct(
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $displayName,
        public string $timezone,
        public ?string $locale,
        #[\SensitiveParameter] public string $password,
    ) {
    }
}
