<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Contract;

use InvalidArgumentException;

/**
 * Who the first admin of a new site is.
 */
final readonly class FirstAdmin
{
    public string $email;

    public string $firstName;

    public string $lastName;

    public function __construct(string $email, string $firstName, string $lastName)
    {
        $this->email = mb_strtolower(trim($email));
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not an email address.', trim($email)));
        }
        $this->firstName = trim($firstName);
        $this->lastName = trim($lastName);
        if ($this->firstName === '' || $this->lastName === '') {
            throw new InvalidArgumentException('The first admin needs a first name and a last name.');
        }
    }
}
