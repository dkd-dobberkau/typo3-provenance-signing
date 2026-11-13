<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Middleware;

use CmsProvenance\ProvenanceSigning\Key\KeyStore;
use CmsProvenance\ProvenanceSigning\Key\SigningKey;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Publishes the site keys as JWK (RFC 8037):
 *   /.well-known/content-provenance/keys          all keys, including retired ones
 *   /.well-known/content-provenance/keys/{keyId}  one key, as referenced by a record's "key"
 */
final readonly class PublicKeyEndpoint implements MiddlewareInterface
{
    public const PATH = '/.well-known/content-provenance/keys';

    public function __construct(
        private KeyStore $keyStore,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        if ($request->getMethod() !== 'GET' || ($path !== self::PATH && !str_starts_with($path, self::PATH . '/'))) {
            return $handler->handle($request);
        }

        if ($path === self::PATH) {
            return $this->respond(['keys' => array_map($this->describe(...), $this->keyStore->publicKeys())]);
        }

        $key = $this->keyStore->find(substr($path, strlen(self::PATH) + 1));
        return $key !== null ? $this->respond($this->describe($key)) : $this->respond(['error' => 'unknown key'], 404);
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

    private function respond(array $data, int $status = 200): ResponseInterface
    {
        return (new JsonResponse($data, $status, [], JSON_UNESCAPED_SLASHES))
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
