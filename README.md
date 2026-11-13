# Content Provenance: Signing

Ed25519 signatures for published content, with key generation and rotation.

TYPO3 reference implementation of **Layer 2** of the
[Content Provenance Specification](https://github.com/dkd-dobberkau/content-provenance-spec). Conformance level reached: **L3 Verifiable**.

Optional extension. Requires the core extension [`provenance`](https://github.com/dkd-dobberkau/typo3-provenance).

> **Status: experimental, pre-release.** Milestone M4 of the
> [PRD](https://github.com/dkd-dobberkau/cms-provenance.org/blob/main/docs/prd-typo3-extensions.md)
> is in place. A site using it reaches conformance level L3. Nothing here is
> ready for production use.

## How it works

When TYPO3 has rendered a page and before it is cached, the extension

1. computes the canonical text of the page as defined in Annex A of the
   specification and stores its SHA-256 as `contentHash` in the provenance
   record emitted by the core extension,
2. sets `key` to the URL of the public key and raises `cpsConformance` to 3,
3. canonicalises the record with JCS (RFC 8785) and signs it with Ed25519,
4. writes the signed record back into the page.

The cached page carries the signature, so signing costs nothing per request.
Anyone can verify a page by fetching it, recomputing the canonical text and
checking the signature against the published key. The implementation passes
the [conformance vectors](https://github.com/dkd-dobberkau/content-provenance-conformance)
byte for byte (`Tests/Fixtures/vectors`).

Content that is not cacheable (`USER_INT`) is inserted after signing. Keep it
outside the content area or mark it with `data-cps-ignore`, otherwise the
delivered text no longer matches the signed hash.

## Usage

```bash
vendor/bin/typo3 provenance:keys:generate   # first key
vendor/bin/typo3 provenance:keys:list
vendor/bin/typo3 provenance:keys:rotate     # new key; the old one stays published as retired
vendor/bin/typo3 cache:flush                # re-render and sign pages
```

Keys are stored in `var/provenance-keys/` (extension setting `keyDirectory`),
outside the web root. Secret keys are written with mode 0600; the extension
refuses to use a secret key that other users can read. Never commit or back up
this directory unencrypted.

Public keys are published as JWK:

- `/.well-known/content-provenance/keys` — all keys, including retired ones
- `/.well-known/content-provenance/keys/{keyId}` — one key, as linked from a record's `key`

## Requirements

- TYPO3 13.4 LTS or 14
- PHP 8.4 or later (uses the built-in HTML5 parser `Dom\HTMLDocument`)
- PHP extensions `sodium` and `intl`

## Installation

Not yet released. Once a first version is tagged:

```bash
composer require cms-provenance/provenance-signing
```

Extension key: [`provenance_signing`](https://extensions.typo3.org/extension/provenance_signing)

## Links

- Project site: <https://cms-provenance.org>
- Specification: <https://github.com/dkd-dobberkau/content-provenance-spec>
- Product requirements (PRD): <https://github.com/dkd-dobberkau/cms-provenance.org/blob/main/docs/prd-typo3-extensions.md>
- Conformance test suite: <https://github.com/dkd-dobberkau/content-provenance-conformance>

## Documentation

Installation, configuration, the editorial guide and verification are
documented in the [manual of the core extension](https://github.com/dkd-dobberkau/typo3-provenance/blob/main/Documentation/Index.rst).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
