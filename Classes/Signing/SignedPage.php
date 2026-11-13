<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Signing;

final readonly class SignedPage
{
    public function __construct(
        public string $html,
        public \stdClass $record,
    ) {}
}
