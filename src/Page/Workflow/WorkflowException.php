<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Workflow;

use DomainException;

/**
 * A workflow step that can't be taken: the page isn't in the state the step starts from, or the person may not take
 * it ({@see $forbidden}, answered with 403).
 */
final class WorkflowException extends DomainException
{
    public function __construct(string $message, public readonly bool $forbidden = false)
    {
        parent::__construct($message);
    }
}
