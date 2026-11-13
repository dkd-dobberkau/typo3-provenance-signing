# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Documentation pointing to the manual of the core extension (milestone M7).
- The latest signed record per page and language is stored in
  `tx_provenancesigning_record` for the audit endpoint.
- Signing of the page provenance record (milestone M4): canonical text and
  content hash per Annex A, JCS record canonicalisation, Ed25519 signature,
  written into the cached page. Passes the conformance vectors byte for byte.
- File-based key store with `provenance:keys:generate`, `:rotate` and `:list`;
  retired keys stay published.
- Public keys as JWK under `/.well-known/content-provenance/keys`.
- Repository skeleton: composer.json, ext_emconf.php, Services.yaml,
  extension icon, contribution guide (milestone M1).

### Fixed

- The `key` URL in signed records was relative when the site base was
  relative, such as `/`, so verifiers could not fetch the key. It is now
  absolute, completed from the request.
