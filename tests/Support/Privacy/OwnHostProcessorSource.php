<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Privacy;

use SightPress\Core\Infrastructure\Http\OutboundHostSource;
use SightPress\Module\Privacy\ProcessorDeclaration;
use SightPress\Module\Privacy\ProcessorSource;
use SightPress\Module\Text\Message;

/**
 * A core-style processor source that also allows a host of the operator's own, for tests.
 */
final class OwnHostProcessorSource implements ProcessorSource, OutboundHostSource
{
    public function processors(): iterable
    {
        yield new ProcessorDeclaration('acme_events.tickets', 'A second declaration with the same id', new Message('Nothing.'), ['GB'], ['second.example']);
        yield new ProcessorDeclaration('acme_events.maps', 'Acme Maps', new Message('Addresses.'), ['IE'], ['*.maps.example']);
    }

    public function outboundHosts(): iterable
    {
        yield 'minio';
    }
}
