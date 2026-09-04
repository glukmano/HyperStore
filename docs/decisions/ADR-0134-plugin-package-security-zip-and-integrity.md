# ADR-0134: Plugin Package Security — ZIP Extraction and Integrity/Signature Contract

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0134                              |
| Status      | Accepted                              |
| Date        | 2026-09-04                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-16                              |

## Context

Master Plan §21 requires planning for secure ZIP installation and integrity/signing. A naive implementation trusting `ZipArchive::statIndex()`'s declared uncompressed size, or calling `ZipArchive::extractTo()` directly, is unsound: the ZIP central directory is attacker-controlled metadata, and `extractTo()` inflates entirely in C with no size-limit hook exposed to userland — by the time PHP regains control, a decompression bomb has already fully expanded to disk. Owner Delta #4 additionally requires a precisely-defined, non-circular signature contract (the initial draft placed integrity fields inside the signed manifest itself, which is circular).

## Decision

**Extraction**: every plugin ZIP is staged in `storage/app/plugin-staging/<uuid>/`, outside the authoritative `plugins/` directory. `plugin.json` is read first, alone, with its own byte cap, before any bulk extraction. Full extraction is **entry-by-entry via `ZipArchive::getStream($entryName)`**, copied through a bounded read loop (8KB chunks) that aborts the instant the *actual* cumulative decompressed byte count (per-entry and archive-total) exceeds configured ceilings (defaults: 50MB total, 10MB/entry, 5,000 entries, 100:1 compression-ratio heuristic) — `statIndex()`'s declared size is used only as a fast pre-filter, never as the authoritative guarantee. Every entry's destination path is canonicalized and rejected if it resolves outside the staging root or contains `..`. Symlink entries are detected via the Unix external-attributes bit-check (`external_attr >> 16 & 0xF000 === 0xA000`, i.e. `S_IFLNK`) and rejected outright.

**Integrity/signature contract ("HyperStore Plugin Package v1")**: a package's `plugin.json` carries no integrity fields (avoiding circularity). A sibling `plugin.sig` file, when present, carries a canonical payload — `{plugin_id, version, manifest_sha256, files: {sorted relative_path: sha256}}`, deterministically serialized (`json_encode` with recursively `ksort()`-ed keys) — plus `signature_algorithm`, `publisher_key_id`, and `signature` (base64 `sodium_crypto_sign_detached()` over the canonical payload bytes; the signature is never part of the signed bytes). Every hash in `files{}` is verified against the *actually extracted* bytes during the same streaming pass, never a ZIP-declared value. Any archive entry absent from `files{}}`, or vice versa, rejects the whole package — an attacker cannot smuggle an unlisted file past verification. Signature verification uses PHP 8.4's built-in libsodium (`sodium_crypto_sign_verify_detached`) against every key in `config('plugins.trusted_publishers')`.

**Trust outcome**: absent `plugin.sig` → `unverified` tier, allowed only per `config('plugins.allow_unsigned')` (default true local/testing, false production — fail-safe). Present-and-valid → tier matches the verifying key's configured tier. **Present-but-invalid → unconditional hard rejection**, regardless of `allow_unsigned` — an invalid signature is a stronger tamper signal than no signature at all and must never be silently downgraded to "unverified and allowed."

## Consequences

- No plugin ZIP can ever write outside `plugins/<sanitized-id>/` — the extraction routine has exactly one destination-root parameter, never derived from ZIP content.
- Archive-bomb defense is correct regardless of what an attacker declares in ZIP metadata, since enforcement happens on actual bytes produced during extraction.
- The signature contract can be verified by any third party independently (deterministic canonical payload), enabling a future Plugin Marketplace's publisher-trust story without redesign — while this phase builds no marketplace/CA itself.
- No process-level sandboxing is claimed anywhere; this ADR governs package integrity only, not runtime code isolation (see ADR-0133 for the stated same-process trust boundary).

## References

- PROJECT_MASTER_PLAN.md §21 (Plugin System)
- `docs/phases/PHASE-16-PLUGIN-SDK-EXTENSIBILITY-PLATFORM.md` §13
