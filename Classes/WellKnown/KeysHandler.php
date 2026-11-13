<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\WellKnown;

use CmsProvenance\Provenance\WellKnown\WellKnownHandlerInterface;
use CmsProvenance\Provenance\WellKnown\WellKnownRouter;
use CmsProvenance\ProvenanceSigning\Key\KeyStore;
use CmsProvenance\ProvenanceSigning\Key\SigningKey;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Publishes the site keys as JWK (RFC 8037):
 *   /.well-known/content-provenance/keys          all keys, including retired ones
 *   /.well-known/content-provenance/keys/{keyId}  one key, as referenced by a record's "key"
 */
final readonly class KeysHandler implements WellKnownHandlerInterface
{
    public const SUB_PATH = 'keys';
    public const PATH = WellKnownRouter::BASE_PATH . '/' . self::SUB_PATH;

    public function __construct(
        private KeyStore $keyStore,
    ) {}

    public function getSubPath(): string
    {
        return self::SUB_PATH;
    }

    public function handle(ServerRequestInterface $request, string $remainder): ResponseInterface
    {
        if ($remainder === '') {
            return $this->respond(['keys' => array_map($this->describe(...), $this->keyStore->publicKeys())]);
        }

        $key = $this->keyStore->find($remainder);
        return $key !== null ? $this->respond($this->describe($key)) : new JsonResponse(['error' => 'unknown key'], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(SigningKey $key): array
    {
        return [
            'keyId' => $key->keyId,
            'algorithm' => 'Ed25519',
            'publicKeyJwk' => [
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'x' => sodium_bin2base64($key->publicKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            ],
            'createdAt' => $key->createdAt->format(\DateTimeInterface::ATOM),
            'retiredAt' => $key->retiredAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function respond(array $data): ResponseInterface
    {
        return (new JsonResponse($data, 200, [], JSON_UNESCAPED_SLASHES))
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
