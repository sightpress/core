<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use DateTimeImmutable;
use SightPress\Module\Search\SearchDocument;
use SightPress\Module\Search\SearchDocumentProvider;

/**
 * The exhibitions of {@see ExhibitionsModule}: two in English, one of them for members only.
 */
final class ExhibitionsSearchProvider implements SearchDocumentProvider
{
    public function source(): string
    {
        return ExhibitionsModule::ID;
    }

    public function documents(string $locale): iterable
    {
        foreach (['1', '2'] as $ref) {
            $document = $this->document($ref, $locale);
            if ($document !== null) {
                yield $document;
            }
        }
    }

    public function document(string $ref, string $locale): ?SearchDocument
    {
        if ($locale !== 'en-GB') {
            return null;
        }

        return match ($ref) {
            '1' => new SearchDocument(ExhibitionsModule::ID, '1', 'en-GB', '/exhibitions/maps', 'Old maps of the harbour', new DateTimeImmutable('2026-10-01T09:00:00Z'), 'Charts from 1850 to 1950.'),
            '2' => new SearchDocument(ExhibitionsModule::ID, '2', 'en-GB', '/exhibitions/preview', 'Members preview of the harbour photographs', new DateTimeImmutable('2026-10-01T09:00:00Z'), visibility: \SightPress\Module\Search\SearchVisibility::Members),
            default => null,
        };
    }
}
