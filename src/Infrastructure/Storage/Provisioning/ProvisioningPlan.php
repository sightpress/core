<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage\Provisioning;

use SightPress\Core\Template\Message;

/**
 * What provisioning will create and where, in plain language, before anything is changed.
 */
final readonly class ProvisioningPlan
{
    /**
     * @param list<Message> $steps
     */
    public function __construct(
        public string $provider,
        public string $location,
        public array $steps,
        public Message $transfer,
    ) {
    }

    /**
     * The same plan, saying that the chosen location is not the site's default one.
     */
    public function withInternationalTransfer(string $default): self
    {
        return new self($this->provider, $this->location, [
            ...$this->steps,
            new Message('This location is not the default for this site ({default}), so storing files there may be an international transfer.', ['default' => $default]),
        ], $this->transfer);
    }
}
