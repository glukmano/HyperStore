# Security & Trust Model

## The honest trust boundary: no process-level sandboxing

Plugins run in the **same PHP process, same memory space, same trust
boundary** as Core and Modules. There is no OS-level container, no separate
PHP worker, no capability-restricted runtime. This is stated here plainly so
it is never overstated elsewhere: the Plugin SDK reduces blast radius through
explicit registration contracts, lifecycle controls, capability declaration
and approval, package integrity/signature verification, and per-plugin error
isolation at boot time — **not** through an isolation guarantee that does not
exist. A malicious plugin, once enabled, can do anything Core code can do.
This is why install and enable are two separate, explicit administrative
actions (see [lifecycle.md](lifecycle.md)), and why signature verification
and capability approval exist as gates before that point.

## ZIP package installation security (ADR-0134)

### Why declared ZIP metadata is never trusted

A ZIP archive's central directory declares each entry's uncompressed size and
CRC — but that metadata is attacker-controlled: nothing requires it to match
what actually decompresses. `ZipArchive::extractTo()` inflates entries fully
in C with no size hook, so by the time PHP regains control from a naive
`extractTo()` call, a compression-bomb entry has already fully decompressed
to disk. `PluginZipInstaller` never calls `extractTo()`.

### Byte-counted streaming extraction

Every file entry is extracted via `ZipArchive::getStream($entryName)`, copied
through a bounded read loop in fixed 8KB chunks
(`PluginZipInstaller::READ_CHUNK_BYTES`). The loop aborts the instant the
**actual cumulative decompressed byte count** — per-entry and archive-total —
exceeds configured ceilings:

| Limit | Config key | Default |
|---|---|---|
| Total uncompressed bytes | `plugins.zip.max_total_uncompressed_bytes` | 50 MB |
| Per-entry uncompressed bytes | `plugins.zip.max_entry_uncompressed_bytes` | 10 MB |
| Entry count | `plugins.zip.max_entry_count` | 5,000 |
| Compression ratio (fast pre-filter only) | `plugins.zip.max_compression_ratio` | 100:1 |
| `plugin.json`/`plugin.sig` byte cap | `plugins.zip.max_manifest_bytes` | 256 KB |

`ZipArchive::statIndex()`'s declared size is used **only** as a fast
pre-filter/early-reject pass before extraction begins — never as the
authoritative enforcement point. `tests/Feature/Plugin/PluginZipSecurityTest.php::test_archive_bomb_is_rejected_by_actual_decompressed_bytes_not_declared_metadata`
proves this specifically with a highly-compressible payload that a
declared-size check alone would not reliably catch.

### Path traversal (Zip Slip)

Every entry name is checked (`assertSafeRelativePath()`) before any bytes are
written: empty names, absolute paths (leading `/`), NUL bytes, and any path
segment equal to `..` are rejected outright. Extraction has exactly one
destination-root parameter — there is no code path by which an entry name can
cause a write outside the staging directory.

### Symlink rejection

ZIP encodes a Unix symlink via the upper 16 bits of the entry's external
attributes. `ZipArchive::statIndex()`'s `external_attr` key was found
empirically unreliable on this PHP build (absent/zero), so symlink detection
uses `ZipArchive::getExternalAttributesIndex($index, $opsys, $attr)` (the
reference-parameter form) and checks `((\$attr >> 16) & 0xF000) === 0xA000`
(`S_IFLNK`). Any entry matching this is rejected before extraction.

### Package integrity and signature verification

See [manifest-reference.md](manifest-reference.md#pluginsig--the-package-signature-contract)
for the full non-circular signature contract, canonical payload definition,
and file-list verification rules (extra/unlisted files, missing files, and
hash mismatches are all hard rejections). Summary of the trust-tier decision
table:

| Condition | Outcome |
|---|---|
| No signature | `unverified`, allowed only if `plugins.allow_unsigned` is true |
| Valid signature from a trusted key | Trust tier from `plugins.publisher_trust_tiers` |
| Invalid signature | Hard rejection, always, regardless of `allow_unsigned` |

### Duplicate/conflicting plugin IDs

`plugins.plugin_id` carries a real, non-nullable PostgreSQL `UNIQUE`
constraint (`uq_plugins_plugin_id`) — proven against a real PostgreSQL
connection in `tests/Feature/Plugin/PostgreSqlPluginIntegrityTest.php`. A
second install attempt against an already-installed `plugin_id` is rejected
at the application layer before it would ever reach the database constraint
(`PluginLifecycleService::install()`), and the constraint itself is the
defense-in-depth backstop.

## Secrets

No `plugin_enablements`-style settings table exists in this phase
(enablement is platform-level only — Owner Delta #1). A plugin that needs to
store its own secrets (e.g. a third-party API key) does so in its own
domain tables using the same encrypted-column pattern already established
elsewhere in this codebase (`Modules\Shipping\Models\CarrierCredential`,
`Modules\Dropshipping\Models\SupplierAccount`): `Crypt::encryptString()` on
write, `$hidden` on the Eloquent model, never included in `manifest_snapshot`,
audit payloads, or Livewire public state. The manifest's `settings_schema`
field exists purely as a documentation/UI hint (`secret: true`) for a
plugin's own settings form — the Plugin SDK does not itself persist or
transport plugin setting values.

## What is explicitly not claimed

- No OS-level sandboxing, container isolation, or restricted PHP runtime.
- No PHP-level dependency isolation between two enabled plugins' bundled
  Composer packages beyond the hard version-collision block at `enable`
  time (see [manifest-reference.md](manifest-reference.md#composer-dependency-loading-owner-delta-3)).
- No certificate authority or marketplace-backed trust chain — signature
  verification is against a flat, admin-managed public-key allowlist.
- No claim that a `failed`/`disabled` plugin's previously-executed code
  effects (e.g. already-dispatched queued jobs) are retroactively undone —
  only that its *registration surface* (registries, routes, scheduled tasks)
  stops contributing on the next boot cycle, and that already-queued jobs are
  caught by `PluginJobMiddleware`'s execution-time enabled check.
