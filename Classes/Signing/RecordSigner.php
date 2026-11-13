<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Signing;

use CmsProvenance\ProvenanceSigning\Canonicalization\CanonicalText;
use CmsProvenance\ProvenanceSigning\Canonicalization\JsonCanonicalizer;

/**
 * Ed25519 signing and verification of provenance records (Annex A.5).
 */
final readonly class RecordSigner
{
    public const ALGORITHM = 'Ed25519';

    public function __construct(
        private CanonicalText $canonicalText,
        private JsonCanonicalizer $jsonCanonicalizer,
    ) {}

    /**
     * A.5.1: returns a copy of the record with the signature object added.
     * The record must already carry its contentHash.
     */
    public function sign(\stdClass $record, string $keyId, string $secretKey): \stdClass
    {
        $signature = sodium_crypto_sign_detached($this->jsonCanonicalizer->canonicalizeRecord($record), $secretKey);

        $signed = clone $record;
        $signed->signature = (object)[
            'algorithm' => self::ALGORITHM,
            'keyId' => $keyId,
            'signatureValue' => sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
        ];

        return $signed;
    }

    /** A.5.2 steps 2 and 4, for a known public key */
    public function verify(string $html, \stdClass $record, string $publicKey): bool
    {
        $signature = $record->signature ?? null;
        if (!$signature instanceof \stdClass || ($signature->algorithm ?? null) !== self::ALGORITHM) {
            return false;
        }
        if (($record->contentHash ?? null) !== $this->canonicalText->contentHash($html)) {
            return false;
        }

        try {
            $signatureBytes = sodium_base642bin((string)($signature->signatureValue ?? ''), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
            return sodium_crypto_sign_verify_detached(
                $signatureBytes,
                $this->jsonCanonicalizer->canonicalizeRecord($record),
                $publicKey
            );
        } catch (\SodiumException | \InvalidArgumentException) {
            return false;
        }
    }
}
