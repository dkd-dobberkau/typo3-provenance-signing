<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Tests\Unit\Key;

use CmsProvenance\ProvenanceSigning\Key\KeyStore;
use CmsProvenance\ProvenanceSigning\Key\KeyStoreException;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class KeyStoreTest extends UnitTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/provenance-keys-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*') ?: []);
        @rmdir($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function generateCreatesAnActiveKeyReadableOnlyByTheOwner(): void
    {
        $key = (new KeyStore($this->directory))->generate();

        self::assertMatchesRegularExpression('/^key-\d{4}-\d{2}-\d{2}-[0-9a-f]{6}$/', $key->keyId);
        self::assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($key->publicKey));
        self::assertSame(0600, fileperms($this->directory . '/' . $key->keyId . '.key') & 0777);
        self::assertSame(0700, fileperms($this->directory) & 0777);
        self::assertSame($key->keyId, (new KeyStore($this->directory))->activeKey()?->keyId);
    }

    #[Test]
    public function generateRefusesWhenAnActiveKeyExists(): void
    {
        $store = new KeyStore($this->directory);
        $store->generate();

        $this->expectException(KeyStoreException::class);
        $store->generate();
    }

    #[Test]
    public function rotateRetiresThePreviousKeyAndKeepsItPublished(): void
    {
        $store = new KeyStore($this->directory);
        $old = $store->generate();
        $new = $store->rotate();

        $keys = $store->publicKeys();
        self::assertSame($new->keyId, $store->activeKey()?->keyId);
        self::assertCount(2, $keys);
        self::assertNotNull($store->find($old->keyId)?->retiredAt);
        self::assertNull($store->find($new->keyId)?->retiredAt);
    }

    #[Test]
    public function rotateRequiresAnActiveKey(): void
    {
        $this->expectException(KeyStoreException::class);
        (new KeyStore($this->directory))->rotate();
    }

    #[Test]
    public function secretKeySignsForThePublishedPublicKey(): void
    {
        $store = new KeyStore($this->directory);
        $key = $store->generate();

        $signature = sodium_crypto_sign_detached('message', $store->secretKey($key->keyId));

        self::assertTrue(sodium_crypto_sign_verify_detached($signature, 'message', $key->publicKey));
    }

    #[Test]
    public function secretKeyIsRefusedWhenOthersCanReadIt(): void
    {
        $store = new KeyStore($this->directory);
        $key = $store->generate();
        chmod($this->directory . '/' . $key->keyId . '.key', 0644);

        $this->expectException(KeyStoreException::class);
        $store->secretKey($key->keyId);
    }
}
