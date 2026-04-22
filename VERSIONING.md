# libretime-trixie — versioning (read this first)

This repository is the **libretime-trixie** distribution: LibreTime packaged and tested for **Debian 13 (Trixie)**.  
Upstream code lineage: [LibreTime](https://github.com/libretime/libretime) (AGPL-3.0). This fork’s **release identity** is independent of upstream’s 4.x setuptools labels.

## At a glance

| What | Value | Where |
|------|--------|--------|
| **Distribution name** | libretime-trixie | README, Git remote URL |
| **Git release tag** | `v0.1.8-trixie` (leading `v`, codename after patch) | `git tag`, [GitHub Releases](https://github.com/stefanolanci/libretime-trixie/releases) |
| **Installer / OS label** | `0.1.8 trixie` (semver, space, codename) | Root file **`VERSION`**; printed by `./install`; copied into `legacy/` during install |
| **Python packages (setuptools)** | `0.1.8` | `shared/setup.py`, `api/setup.py`, `api-client/setup.py`, `playout/setup.py`, `analyzer/setup.py`, `worker/setup.py` |
| **User-facing changelog** | [CHANGELOG.md](CHANGELOG.md) | Release notes index |
| **Engineering diary** | [docs/development-log.md](docs/development-log.md) | Maintainer-oriented detail |

## Rules

1. **New fork release** → bump root **`VERSION`** (first line), bump all **`setup.py`** `version=`, add a **`CHANGELOG.md`** section, tag **`v<semver>-trixie`**, push tag + `main`.
2. **`tools/version.sh`** does **not** overwrite **`VERSION`** when the first line already matches `^[0-9]+\.[0-9]+\.[0-9]+` (see script comments).
3. **Legacy “What’s new”** uses **`LIBRETIME_WHATS_NEW_URL`** / **`LIBRETIME_UPDATE_FEED`** in `legacy/application/configs/constants.php` — they must point at **this fork’s** Releases, not upstream’s.

## Check out a known release

```bash
git fetch origin tag v0.1.8-trixie
git checkout v0.1.8-trixie   # detached HEAD; fine for installs
# or stay on main after a release merge:
git checkout main && git pull
```
