<?php

declare(strict_types=1);

namespace SightPress\Core\Search\Analysis;

/**
 * One word of a text: as it was written, after normalisation (case folded, accents removed) and the term that is
 * indexed and searched for (the normalised word, stemmed where the language has a stemmer).
 */
final readonly class Token
{
    /**
     * @param non-empty-string $word
     * @param non-empty-string $normalised
     * @param non-empty-string $term
     */
    public function __construct(
        public string $word,
        public string $normalised,
        public string $term,
    ) {
    }
}
