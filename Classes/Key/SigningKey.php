<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Key;

/**
 * Public part of a site key. The secret part never leaves the KeyStore.
 */
final readonly class SigningKey
{
    public function __construct(
        public string $keyId,
        public string $publicKey,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $retiredAt,
    ) {}

    public function isActive(): bool
    {
        return $this->retiredAt === null;
    }
}
