# Changelog

Notable **libretime-trixie** releases. Lineage: [LibreTime](https://github.com/libretime/libretime) (AGPL-3.0).  
Detailed engineering notes: [docs/development-log.md](docs/development-log.md).

## [0.1.8-trixie] — 2026-04-22

### Versioning (this release)

- Single distribution identity: **Git tag `v0.1.8-trixie`**, root **`VERSION`** `0.1.8 trixie`, all Python **`setup.py`** packages set to **`0.1.8`** with project URL [stefanolanci/libretime-trixie](https://github.com/stefanolanci/libretime-trixie).
- Legacy UI “what’s new” / update feed URLs point at **this fork’s** GitHub Releases so they match the installed tree.

### Installer and uninstaller (since prior tag)

- Uninstaller **`--purge-packages`**: removed invalid `libretime*` apt pattern; optional **Certbot** purge in a separate step; deterministic package purge on Debian Trixie.
- Broader installer hardening (wizard, upgrades, PostgreSQL/RabbitMQ password sync, `pipefail`, `tools/packages.py` ordering, fail2ban opt-in suite, Liquidsoap/Icecast fixes) — see [docs/development-log.md](docs/development-log.md) for the full chronological list.
