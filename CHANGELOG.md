# Changelog

Notable **libretime-trixie** releases. Lineage: [LibreTime](https://github.com/libretime/libretime) (AGPL-3.0).  
**How tag / `VERSION` / Python versions relate:** [docs/development-log.md — Release identity and versioning](docs/development-log.md#release-identity-and-versioning).  
Detailed engineering notes: [docs/development-log.md](docs/development-log.md).

## [0.1.8-trixie] — 2026-04-22

### Versioning (this release)

- **Single triple `0.1.8`:** Git tag and GitHub release ref **`0.1.8-trixie`**, root **`VERSION`** **`0.1.8 trixie`**, all Python **`setup.py`** **`0.1.8`**, project URL [stefanolanci/libretime-trixie](https://github.com/stefanolanci/libretime-trixie). Rules: **[docs/development-log.md](docs/development-log.md#release-identity-and-versioning)**.
- Legacy UI “what’s new” / update feed URLs point at **this fork’s** GitHub Releases so they match the installed tree.

### Installer and uninstaller (since prior tag)

- Uninstaller **`--purge-packages`**: removed invalid `libretime*` apt pattern; optional **Certbot** purge in a separate step; deterministic package purge on Debian Trixie.
- Broader installer hardening (wizard, upgrades, PostgreSQL/RabbitMQ password sync, `pipefail`, `tools/packages.py` ordering, fail2ban opt-in suite, Liquidsoap/Icecast fixes) — see [docs/development-log.md](docs/development-log.md) for the full chronological list.
