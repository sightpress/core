<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Search\SearchDocument;
use SightPress\Module\Search\SearchDocumentProvider;

final class ExampleSearchProvider implements SearchDocumentProvider
{
    public function source(): string
    {
        return 'acme.events';
    }

    public function documents(string $locale): iterable
    {
        return [];
    }

    public function document(string $ref, string $locale): ?SearchDocument
    {
        return null;
    }
}
