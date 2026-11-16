<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Workflow;

use DomainException;
use SightPress\Core\Page\Contract\PublishReport;

/**
 * The page can't be published yet: the publish checks found errors. The report lists them, with warnings.
 */
final class PublishBlocked extends DomainException
{
    public function __construct(public readonly PublishReport $report)
    {
        $count = count($report->errors());
        parent::__construct(sprintf(
            '%d %s stop%s this page being published. Fix %s and publish again.',
            $count,
            $count === 1 ? 'problem' : 'problems',
            $count === 1 ? 's' : '',
            $count === 1 ? 'it' : 'them',
        ));
    }
}
