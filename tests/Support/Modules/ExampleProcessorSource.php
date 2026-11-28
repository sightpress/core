<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Privacy\ProcessorDeclaration;
use SightPress\Module\Privacy\ProcessorSource;
use SightPress\Module\Text\Message;

/**
 * A module's third party, for registry tests.
 */
final class ExampleProcessorSource implements ProcessorSource
{
    public function processors(): iterable
    {
        yield new ProcessorDeclaration('acme_events.tickets', 'Acme Tickets Ltd', new Message('Names and email addresses.'), ['GB'], ['api.acme-tickets.example']);
    }
}
