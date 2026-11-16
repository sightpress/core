<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Workflow;

use DateTimeImmutable;
use LogicException;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Rbac\Contract\PageState;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

/**
 * Lets the state machine read and change a page's state; the time of the change comes in the context (`now`).
 */
final class PageMarkingStore implements MarkingStoreInterface
{
    public function getMarking(object $subject): Marking
    {
        return new Marking([self::page($subject)->state()->value => 1]);
    }

    /**
     * @param array<mixed> $context
     */
    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $places = array_keys($marking->getPlaces());
        $now = $context['now'] ?? null;
        if (count($places) !== 1 || !$now instanceof DateTimeImmutable) {
            throw new LogicException('A page is in exactly one state, changed at a given time.');
        }
        self::page($subject)->changeState(PageState::from((string) $places[0]), $now);
    }

    private static function page(object $subject): Page
    {
        return $subject instanceof Page ? $subject : throw new LogicException(sprintf('The page workflow only handles pages, not %s.', $subject::class));
    }
}
