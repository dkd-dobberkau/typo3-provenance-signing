<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Signing;

final readonly class SignedPage
{
    public function __construct(
        public string $html,
        public \stdClass $record,
        // The canonical text (Annex A.2) the content hash was computed over.
        public string $canonicalText,
    ) {}
}
