<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Tests\Unit\Canonicalization;

use CmsProvenance\ProvenanceSigning\Canonicalization\CanonicalText;
use CmsProvenance\ProvenanceSigning\Canonicalization\JsonCanonicalizer;
use CmsProvenance\ProvenanceSigning\Signing\RecordSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Runs the Annex A vectors of the conformance suite. The PHP implementation
 * must produce byte-identical results to the reference implementation.
 */
final class VectorTest extends UnitTestCase
{
    private const VECTORS = __DIR__ . '/../../Fixtures/vectors';

    public static function vectors(): iterable
    {
        foreach (glob(self::VECTORS . '/canonicalization/*', GLOB_ONLYDIR) as $directory) {
            yield basename($directory) => [$directory];
        }
    }

    #[Test]
    #[DataProvider('vectors')]
    public function canonicalTextMatchesTheHandWrittenExpectation(string $vector): void
    {
        self::assertSame(
            file_get_contents($vector . '/expected-text.txt'),
            (new CanonicalText())->extract(file_get_contents($vector . '/input.html'))
        );
    }

    #[Test]
    #[DataProvider('vectors')]
    public function contentHashMatches(string $vector): void
    {
        self::assertSame(
            $this->expected($vector)->contentHash,
            (new CanonicalText())->contentHash(file_get_contents($vector . '/input.html'))
        );
    }

    #[Test]
    #[DataProvider('vectors')]
    public function canonicalRecordMatches(string $vector): void
    {
        $record = $this->record($vector);
        $record->contentHash = $this->expected($vector)->contentHash;

        $canonical = (new JsonCanonicalizer())->canonicalizeRecord($record);

        self::assertSame($this->expected($vector)->canonicalRecord, $canonical);
        self::assertSame($this->expected($vector)->canonicalRecordSha256, hash('sha256', $canonical));
    }

    #[Test]
    #[DataProvider('vectors')]
    public function signatureMatchesTheReferenceSignature(string $vector): void
    {
        $record = $this->record($vector);
        $record->contentHash = $this->expected($vector)->contentHash;

        $signed = $this->signer()->sign($record, 'cps-test-rfc8032-1', $this->testSecretKey());

        self::assertEquals($this->expected($vector)->signedRecord->signature, $signed->signature);
    }

    #[Test]
    #[DataProvider('vectors')]
    public function referenceSignedRecordVerifiesAgainstThePage(string $vector): void
    {
        $verified = $this->signer()->verify(
            file_get_contents($vector . '/input.html'),
            $this->expected($vector)->signedRecord,
            $this->testPublicKey()
        );

        self::assertTrue($verified);
    }

    #[Test]
    #[DataProvider('vectors')]
    public function changedRecordFailsVerification(string $vector): void
    {
        $signed = $this->expected($vector)->signedRecord;
        $signed->cpsConformance = 4;

        self::assertFalse($this->signer()->verify(file_get_contents($vector . '/input.html'), $signed, $this->testPublicKey()));
    }

    #[Test]
    public function invisibleBytesDoNotChangeTheHash(): void
    {
        $canonicalText = new CanonicalText();

        self::assertSame(
            $canonicalText->contentHash('<main><p>Signed text</p></main>'),
            $canonicalText->contentHash("<main><p>Sig\u{200B}ned\u{00AD} te\u{2060}xt\u{FEFF}</p></main>")
        );
    }

    #[Test]
    public function nonIntegerNumbersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new JsonCanonicalizer())->canonicalizeRecord((object)['cpsConformance' => 2.5]);
    }

    private function signer(): RecordSigner
    {
        return new RecordSigner(new CanonicalText(), new JsonCanonicalizer());
    }

    private function record(string $vector): \stdClass
    {
        return json_decode(file_get_contents($vector . '/record.json'), false, 512, JSON_THROW_ON_ERROR);
    }

    private function expected(string $vector): \stdClass
    {
        return json_decode(file_get_contents($vector . '/expected.json'), false, 512, JSON_THROW_ON_ERROR);
    }

    private function testSecretKey(): string
    {
        $key = json_decode(file_get_contents(self::VECTORS . '/keys/test-key.json'), false, 512, JSON_THROW_ON_ERROR);
        return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin($key->privateKeySeedHex)));
    }

    private function testPublicKey(): string
    {
        $key = json_decode(file_get_contents(self::VECTORS . '/keys/test-key.json'), false, 512, JSON_THROW_ON_ERROR);
        return hex2bin($key->publicKeyHex);
    }
}
