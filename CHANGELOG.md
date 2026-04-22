# Changelog

Notable **libretime-trixie** releases. Lineage: [LibreTime](https://github.com/libretime/libretime) (AGPL-3.0).  
**How tag / `VERSION` / Python versions relate:** [docs/development-log.md — Release identity and versioning](docs/development-log.md#release-identity-and-versioning).  
Detailed engineering notes: [docs/development-log.md](docs/development-log.md).

## [0.1.8-trixie] — 2026-04-22

### Versioning (this release)

- Product line: root **`VERSION`** `0.1.8 trixie`, all Python **`setup.py`** packages **`0.1.8`**, project URL [stefanolanci/libretime-trixie](https://github.com/stefanolanci/libretime-trixie). Mapping table: **[docs/development-log.md](docs/development-log.md#release-identity-and-versioning)**.
- **GitHub:** the published release tag is **`trixie-0.1.8`** (release title *libretime-trixie v0.1.8-trixie*) because repository **immutable-release / tag rules** block (re)creating the ref **`v0.1.8-trixie`**. Use `git checkout trixie-0.1.8` to match the GitHub tarball, or stay on **`main`**.
- Legacy UI “what’s new” / update feed URLs point at **this fork’s** GitHub Releases so they match the installed tree.

### Installer and uninstaller (since prior tag)

- Uninstaller **`--purge-packages`**: removed invalid `libretime*` apt pattern; optional **Certbot** purge in a separate step; deterministic package purge on Debian Trixie.
- Broader installer hardening (wizard, upgrades, PostgreSQL/RabbitMQ password sync, `pipefail`, `tools/packages.py` ordering, fail2ban opt-in suite, Liquidsoap/Icecast fixes) — see [docs/development-log.md](docs/development-log.md) for the full chronological list.
