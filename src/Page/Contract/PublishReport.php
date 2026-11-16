<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Everything the publish checks found in a page, in every language: errors first, then warnings, each in the order
 * of the page.
 */
final readonly class PublishReport
{
    /** @var list<PublishIssue> */
    public array $issues;

    /**
     * @param list<PublishIssue> $issues
     */
    public function __construct(array $issues = [])
    {
        $this->issues = [
            ...array_values(array_filter($issues, static fn (PublishIssue $issue): bool => $issue->isError())),
            ...array_values(array_filter($issues, static fn (PublishIssue $issue): bool => !$issue->isError())),
        ];
    }

    public function canPublish(): bool
    {
        return $this->errors() === [];
    }

    /**
     * @return list<PublishIssue>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (PublishIssue $issue): bool => $issue->isError()));
    }

    /**
     * @return list<PublishIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (PublishIssue $issue): bool => !$issue->isError()));
    }
}
