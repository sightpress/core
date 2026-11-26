<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\StepUp;

/**
 * Where two-step confirmation happens (N-6).
 */
final class ConfirmPaths
{
    /** *Confirm it's you*: send a code by text, then type it. */
    public const string CONFIRM = '/confirm';

    /** After the code: send the kept form again. */
    public const string CONTINUE = '/confirm/continue';
}
