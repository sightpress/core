<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

/**
 * The outcome of checking a code. Pages show one message for every outcome but `Valid` and `Limited`, so nobody can
 * learn from the answer whether an address has an account.
 */
enum CodeStatus
{
    /** The code was right; it is used up now. */
    case Valid;

    /** The code was wrong; it still works until it runs out of tries. */
    case Wrong;

    /** There is no code for this purpose and address, or it was already used. */
    case Missing;

    /** The code ran out of time. */
    case Expired;

    /** Too many wrong tries: the code is dead and a new one is needed. */
    case Locked;

    /** This client checked too many codes; it must wait before it tries again. */
    case Limited;
}
