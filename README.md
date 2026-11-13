# Content Provenance: Signing

Ed25519 signatures for published content, with key generation and rotation.

TYPO3 reference implementation of **Layer 2** of the
[Content Provenance Specification](https://github.com/dkd-dobberkau/content-provenance-spec). Conformance level reached: **L3 Verifiable**.

Optional extension. Requires the core extension [`provenance`](https://github.com/dkd-dobberkau/typo3-provenance).

> **Status: experimental, pre-release.** This repository currently contains the
> skeleton only (milestone M1 of the [PRD](https://github.com/dkd-dobberkau/cms-provenance.org/blob/main/docs/prd-typo3-extensions.md)). Nothing here is
> ready for production use.

## Scope

- Ed25519 key management, stored outside the database
- Content hashing and signature generation at publish time
- Signature exposure in the JSON-LD block
- CLI: `provenance:keys:generate`, `provenance:keys:rotate`, `provenance:keys:list`

## Requirements

- TYPO3 13.4 LTS or 14
- PHP 8.2 or later

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

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
