# Platform Compatibility Baseline (for Theme & Plugin Developers)

This document tells Theme and Plugin developers what platform version their extension is being built against. It is a pointer, not a second source of truth — the authoritative, continuously-updated dependency registry is **[docs/DEPENDENCIES.md](decisions/../DEPENDENCIES.md)**.

## Current Baseline

| Component | Version |
|---|---|
| PHP | 8.4+ |
| Laravel Framework | 13.x |
| PostgreSQL | 16+ |
| Livewire | 4.x |
| Tailwind CSS | 4.x |
| daisyUI | 5.x |
| Vite | 6+ |

See `docs/DEPENDENCIES.md` for the complete, versioned registry of every approved dependency.

## What This Means for Extension Authors

- **Themes**: built entirely in Blade against the Tailwind 4 / daisyUI 5 baseline above (see `docs/themes/developer-guide.md`). There is no separate theme-compatibility manifest field enforced today — `theme.json`'s `version` field is informational only.
- **Plugins**: `plugin.json` declares `compatibility.platform` and `compatibility.php` — these ARE checked at install/enable time by the Plugin SDK (see `docs/plugins/manifest-reference.md`). Keep these fields accurate against the baseline above.

## No Licensing/Version-Gating Service

This is a compatibility *reference* only. There is no automated platform-licensing or feature-gating version service in this build — that is explicitly reserved for a future, separately-authorized stage and is not implemented here.
