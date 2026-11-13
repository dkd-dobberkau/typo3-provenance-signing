<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Key;

/**
 * File-based store for Ed25519 site keys.
 *
 * Layout of the key directory (mode 0700):
 * - keys.json          registry of all keys with public key, creation and retirement time
 * - <keyId>.key        secret key, base64url, mode 0600
 *
 * Retired keys stay in the registry so that signatures made with them can
 * still be verified (specification Section 3.2).
 */
final class KeyStore
{
    private const REGISTRY = 'keys.json';

    public function __construct(
        private readonly string $directory,
    ) {}

    public function generate(): SigningKey
    {
        if ($this->activeKey() !== null) {
            throw new KeyStoreException('An active key exists already. Use provenance:keys:rotate to replace it.', 1794600101);
        }

        return $this->createKey();
    }

    public function rotate(): SigningKey
    {
        $previous = $this->activeKey();
        if ($previous === null) {
            throw new KeyStoreException('There is no active key to rotate. Use provenance:keys:generate first.', 1794600102);
        }

        $registry = $this->readRegistry();
        $registry[$previous->keyId]['retiredAt'] = gmdate(\DateTimeInterface::ATOM);
        $this->writeRegistry($registry);

        return $this->createKey();
    }

    public function activeKey(): ?SigningKey
    {
        foreach (array_reverse($this->publicKeys()) as $key) {
            if ($key->isActive()) {
                return $key;
            }
        }

        return null;
    }

    public function find(string $keyId): ?SigningKey
    {
        foreach ($this->publicKeys() as $key) {
            if ($key->keyId === $keyId) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return list<SigningKey> oldest first
     */
    public function publicKeys(): array
    {
        $keys = [];
        foreach ($this->readRegistry() as $keyId => $entry) {
            $keys[] = new SigningKey(
                keyId: (string)$keyId,
                publicKey: sodium_base642bin($entry['publicKey'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
                createdAt: new \DateTimeImmutable($entry['createdAt']),
                retiredAt: $entry['retiredAt'] !== null ? new \DateTimeImmutable($entry['retiredAt']) : null,
            );
        }

        return $keys;
    }

    /**
     * Refuses to hand out a key that other users on the system can read.
     */
    public function secretKey(string $keyId): string
    {
        $file = $this->secretKeyFile($keyId);
        if (!is_file($file)) {
            throw new KeyStoreException('No secret key for ' . $keyId, 1794600103);
        }
        clearstatcache(true, $file);
        if ((fileperms($file) & 0077) !== 0) {
            throw new KeyStoreException(sprintf('Secret key file %s is readable by other users; restrict it to mode 0600.', $file), 1794600104);
        }

        return sodium_base642bin(trim((string)file_get_contents($file)), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    private function createKey(): SigningKey
    {
        $this->ensureDirectory();
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $keyId = sprintf('key-%s-%s', gmdate('Y-m-d'), bin2hex(random_bytes(3)));
        $createdAt = gmdate(\DateTimeInterface::ATOM);

        $file = $this->secretKeyFile($keyId);
        $previousUmask = umask(0077);
        try {
            file_put_contents($file, sodium_bin2base64(sodium_crypto_sign_secretkey($keyPair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), LOCK_EX);
        } finally {
            umask($previousUmask);
        }
        chmod($file, 0600);
        sodium_memzero($keyPair);

        $registry = $this->readRegistry();
        $registry[$keyId] = [
            'publicKey' => sodium_bin2base64($publicKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            'createdAt' => $createdAt,
            'retiredAt' => null,
        ];
        $this->writeRegistry($registry);

        return new SigningKey($keyId, $publicKey, new \DateTimeImmutable($createdAt), null);
    }

    /**
     * @return array<string, array{publicKey: string, createdAt: string, retiredAt: ?string}>
     */
    private function readRegistry(): array
    {
        $file = $this->directory . '/' . self::REGISTRY;
        if (!is_file($file)) {
            return [];
        }

        return json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    private function writeRegistry(array $registry): void
    {
        $this->ensureDirectory();
        file_put_contents(
            $this->directory . '/' . self::REGISTRY,
            json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            LOCK_EX
        );
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new KeyStoreException('Cannot create key directory ' . $this->directory, 1794600105);
        }
        chmod($this->directory, 0700);
    }

    private function secretKeyFile(string $keyId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $keyId) !== 1) {
            throw new KeyStoreException('Invalid key id', 1794600106);
        }

        return $this->directory . '/' . $keyId . '.key';
    }
}
