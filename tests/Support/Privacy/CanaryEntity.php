<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Privacy;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SightPress\Module\Privacy\PersonalData;

/**
 * An entity with personal data the inventory doesn't cover, for the inventory check's tests.
 */
#[ORM\Entity]
final class CanaryEntity
{
    #[ORM\Column(type: Types::STRING)]
    #[PersonalData('canary.missing')]
    private string $nickname = '';

    #[ORM\Column(type: Types::INTEGER)]
    private int $ownerUserId = 0;

    #[ORM\Column(name: 'contact_email', type: Types::STRING)]
    private string $notMarked = '';

    #[ORM\Column(type: Types::INTEGER)]
    private int $visits = 0;

    private string $notMapped = '';

    public function __construct(
        #[ORM\Column(name: 'invited_by', type: Types::INTEGER)]
        #[PersonalData('acme_events.bookings')]
        private int $invitedBy = 0,
        #[PersonalData('canary.promoted')]
        private string $promoted = '',
    ) {
    }

    /**
     * @return list<mixed>
     */
    public function values(): array
    {
        return [$this->nickname, $this->ownerUserId, $this->notMarked, $this->visits, $this->notMapped, $this->invitedBy, $this->promoted];
    }
}
