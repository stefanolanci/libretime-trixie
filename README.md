# LibreTime (Debian Trixie)

Native radio automation for **Debian 13 (Trixie)**. Installation uses the root `install` script (systemd units, no containers).

This tree targets **Trixie** specifically (PHP **8.4**, current Python, **Liquidsoap ≥ 2.3** from Debian). It includes **operational fixes** compared to a generic LibreTime line: legacy patches, a Celery worker backed by **Redis**, a **single** Liquidsoap bundle (`ls_script.liq` / `ls_lib.liq` under `playout/`), and Composer from APT.

**Upstream:** derived from [LibreTime](https://github.com/libretime/libretime). Changes in this repository are under **AGPL-3.0**, consistent with upstream licensing.

---

## Contents

- [Requirements](#requirements)
- [Get the source](#get-the-source)
- [Installation](#installation)
  - [HTTPS, Certbot, and Icecast TLS](#https-certbot-and-icecast-tls)
  - [Interactive wizard](#interactive-wizard)
  - [Useful options and environment variables](#useful-options-and-environment-variables)
- [After install](#after-install)
- [Firewall](#firewall)
- [Celery worker and Redis](#celery-worker-and-redis)
- [Liquidsoap 2.3+](#liquidsoap-23)
- [Changes in this fork](#changes-in-this-fork)
- [Uninstall](#uninstall)
- [Logs and troubleshooting](#logs-and-troubleshooting)
- [License](#license)

---

## Requirements

- **Debian 13** or **testing/sid** with `VERSION_CODENAME=trixie` in `/etc/os-release` (the installer accepts `VERSION_ID=13` or codename `trixie`).
- **Root** access (run the installer as root, optionally via `sudo`).
- Working network so `apt` can reach mirrors.
- Recommended: dedicated VM or machine, **≥ 2 GB RAM**, enough disk for dependencies and media.
- Recommended: **UTF-8 locale** (e.g. generate `en_US.UTF-8` or your locale) to reduce PostgreSQL/Python warnings.

**Stack on Trixie:** PHP **8.4** (FPM), Python **≥ 3.11**, PostgreSQL, **Redis** (Celery results), RabbitMQ, Nginx. APT package lists (`*/packages.ini`, `tools/packages.py`) are **only** for Debian 13 (trixie).

Optional right after OS install:

```bash
sudo apt update
sudo apt full-upgrade -y
# for remote administration:
sudo apt install -y openssh-server
```

---

## Get the source

**Option A — Git** (recommended for development or updates):

```bash
sudo apt install -y git
git clone https://github.com/stefanolanci/libretime-trixie.git libretime
cd libretime
```

**Option B — Archive** without `.git`: ensure a `VERSION` file exists in the tree root (otherwise `make VERSION` produces a generic placeholder).

### What `./install` uses from the tree

The root **`install`** script consumes **`installer/`** (templates, Nginx, Icecast, `config.yml` example, systemd target), **`tools/packages.py`**, **`tools/version.sh`** (via `make VERSION` when needed), and the application directories **`shared/`**, **`api-client/`**, **`api/`**, **`playout/`**, **`analyzer/`**, **`worker/`**, **`legacy/`**, plus root **`VERSION`**. The published repository includes **only** those two files under **`tools/`**; optional helpers (deploy scripts, SQL checks, stream diagnostics, extra systemd snippets, Windows checksum helper) are **not** shipped—add them locally if you use them. They are **not** run by `./install`.

---

## Installation

From the repository root, make scripts executable if needed (ZIP, copies from Windows, etc.):

```bash
chmod +x install tools/version.sh installer/uninstall-libretime.sh installer/letsencrypt/renew-icecast-bundle.sh
```

### Basic usage

Pass the **public URL** listeners will use (HTTP or HTTPS):

```bash
cd /path/to/libretime
sudo ./install http://192.168.1.10:8080
# or
sudo ./install https://radio.example.org
```

To keep the app files inside the clone (development):

```bash
sudo ./install --in-place https://radio.example.org
```

The script installs APT dependencies (including **Composer** and `php8.4-zip`, **redis-server** for the worker), creates the venv under `/opt/libretime`, configures PostgreSQL / RabbitMQ / Icecast unless disabled, deploys Nginx and PHP-FPM, and enables **nginx**, **php-fpm**, and **redis-server**. The Legacy step runs **`make -C legacy build`** (Composer plus automatic Propel/Zend patches for PHP 8.4).

If the local system hostname is not resolvable, the installer appends a safe mapping to `/etc/hosts` (typically `127.0.1.1 <hostname>`) to avoid Debian host-resolution warnings during setup.

### HTTPS, Certbot, and Icecast TLS

If the **positional `public_url` starts with `https://`**, the **first install** also:

- Installs **Certbot** and the **nginx** plugin.
- Deploys a **public reverse proxy** on ports **80/443** to the internal LibreTime port (default **8080**).
- Obtains a **Let’s Encrypt** certificate (requires DNS pointing at the server and reachable **80/443**).
- If **Icecast** is enabled: builds **`/etc/icecast2/bundle.pem`**, patches **`icecast.xml`** for TLS (default HTTPS port **8443**), sets **`stream.outputs.icecast` `public_url`** in `config.yml`, and installs a **renewal deploy hook** for the bundle.

If **UFW** is active on first install, the installer opens:

- **HTTPS mode:** **80/tcp** and **443/tcp** (web + ACME), plus **8443/tcp** when Icecast TLS is enabled.
- **HTTP mode:** app listen port (default **8080/tcp**).
- **Both modes (if Icecast is set up):** **8000/tcp** (Icecast HTTP) and **8001/tcp + 8002/tcp** (Harbor live inputs).

To use an **HTTPS** URL but **skip** this automation (e.g. you terminate TLS elsewhere), use:

```bash
sudo LIBRETIME_HTTPS_AUTO=false ./install https://radio.example.org
# or
sudo ./install --no-https-auto https://radio.example.org
```

Relevant environment variables:

| Variable | Role |
|----------|------|
| `LIBRETIME_CERTBOT_EMAIL` | Let’s Encrypt account email (recommended). If empty, Certbot uses `--register-unsafely-without-email`. |
| `LIBRETIME_ICECAST_HTTPS_PORT` | Icecast TLS listen port in generated URLs (default **8443**). |

### Interactive wizard

For a guided flow (HTTP vs HTTPS, Certbot email, summary):

```bash
sudo ./install --wizard
```

- **Do not** pass a positional `public_url` together with `--wizard`.
- **`--wizard` is only for the first install** (no existing `/etc/libretime/config.yml`).

### Useful options and environment variables

Run **`./install --help`** for the full list. Common flags:

| Flag | Meaning |
|------|---------|
| `--listen-port PORT` / `-p` | Internal Nginx listen port for the app (default **8080**). |
| `--storage-path PATH` / `-s` | Media storage path (default `/srv/libretime`). |
| `--no-setup-icecast` | Skip Icecast package and config. |
| `--no-setup-postgresql` / `--no-setup-rabbitmq` | Skip those services (you must configure them yourself). |

You can persist settings in a **`.env`** file next to `install`; flags override environment variables.

---

## After install

1. **Review configuration**  
   Edit `/etc/libretime/config.yml` as needed (database and RabbitMQ passwords are already in the file if the installer created them). Check `general.public_url`, `general.timezone`, `storage.path`, and stream settings.

2. **Database migrations**

   ```bash
   sudo -u libretime libretime-api migrate
   ```

3. **Start services**

   ```bash
   sudo systemctl start libretime.target
   ```

4. **Quick check**

   ```bash
   systemctl is-active libretime.target nginx "php8.4-fpm" postgresql rabbitmq-server redis-server libretime-worker
   curl -sf "http://127.0.0.1:8080/api/v2/version" | head
   ```

   Open the **public URL** you passed to `./install` (with HTTPS automation, use **`https://your.host/`** in the browser; the API is still reachable on **127.0.0.1:8080** internally).

   Default login is typically **`admin` / `admin`** — **change the password immediately**.

---

## Firewall

Open whatever clients need:

| Port | Typical use |
|------|-------------|
| **80** / **443** | Public web UI when using HTTPS + Certbot |
| **8080** | Direct UI access (HTTP) if you do not use a public TLS proxy |
| **8000** | Icecast HTTP |
| **8443** | Icecast HTTPS (when TLS is enabled by the installer) |
| **8001** / **8002** | Liquidsoap harbor (live sources), if exposed |

Installer behavior with **UFW active** (first install):

- In HTTPS mode it auto-allows `80/tcp`, `443/tcp`, and Icecast TLS port (`8443/tcp` by default when enabled).
- In HTTP mode it auto-allows the app listen port (default `8080/tcp`).
- If Icecast is installed, it also auto-allows `8000/tcp`, `8001/tcp`, and `8002/tcp`.

---

## Celery worker and Redis

**Celery 5** no longer supports the **`amqp`** result backend. The worker uses **Redis** (default `redis://127.0.0.1:6379/0`); legacy PHP reads the same keys via **Predis** (`celery-task-meta-<id>`).

- If Redis is down, `libretime-worker` may crash-loop: check `systemctl status redis-server`.
- Optional env: **`LIBRETIME_CELERY_RESULT_BACKEND`** (worker); for legacy, **`LIBRETIME_REDIS_*`** if Redis is not local/default.

---

## Liquidsoap 2.3+

**Liquidsoap ≥ 2.3.0** is required (Debian Trixie package). `libretime-liquidsoap` checks the version at startup. Scripts live in a single bundle under `playout/libretime_playout/liquidsoap/` (`ls_script.liq`, `ls_lib.liq`); older 1.4 / 2.0 / 2.1 variants are not maintained here.

**Note:** The Debian **Liquidsoap** package is built without full **harbor TLS** settings; live encoder connections to harbor ports are usually **plain HTTP**. **AAC** encoding via **fdkaac** is typically unavailable; **MP3** (and optionally Ogg/Opus) is the practical choice — see comments in `installer/config.yml`.

---

## Changes in this fork

Targeted fixes for **Debian 13 / Liquidsoap 2.3** and races between UI, API, and playout:

- **PLC dashboard (header):** passive pipeline synoptic with **PLC REAL** / **PLC LOGIC** rows; six lamps (**LNK, FLW, AUD, ICE** for the real chain; **FET, PLAY** for logic) driven by playout telemetry posted to **`/api/playout-state`**; decoded **State / Detail** text from a six-bit code with **green / yellow / red** severity; layout tuned for the master strip height. Implemented in `legacy` (header, CSS, `dashboard.js`) and playout (`player/pipeline.py`, `stream_level_probe.py`, wiring in `main.py` / Liquidsoap client).
- **Station podcast workflow restored and expanded:** re-enabled **Publish** from Tracks context menu, fixed episode publish persistence (`episode_title` / metadata flow), restored **My Podcast** navigation entry, and fixed empty episode table behavior in the management page.
- **My Podcast table UX parity:** added proper server-side DataTables behavior, stable column definitions, working **Columns** visibility/reorder controls, and cleaner labels for narrow ColVis dropdowns.
- **Public radio page background controls:** General Settings now supports upload/remove for a dedicated radio-page background image, persistent `cover` / `contain` fit preference, fallback behavior, and frontend rendering on the public homepage with dark overlay compatibility.
- **General settings visual refinements:** station logo and background image controls/previews are aligned and proportioned consistently, including placeholder behavior when no custom background is set.
- **Localization reliability and translation pass:** login locale selection now persists correctly (cookie + user preference), locale bootstrap handling was hardened for gettext on PHP 8.4, and key UI strings were refined in `en_US`, `en_GB`, `it_IT`, `fr_FR`, `es_ES`, and `pt_BR`.
- **First-track volume fix:** bootstrap sequence reordered — `schedule_streaming` is only activated after `PypoPush` has pushed the initial tracks to Liquidsoap's `request.queue`, preventing `amplify` from missing `libretime_replay_gain` metadata on the first track.
- **Live / harbor:** legacy updates **database connection state before** publishing the RabbitMQ `switch_source` message (`ApiController::updateSourceStatusAction`, `DashboardController::switchSourceAction`) so `GET /api/v2/stream/state` and playout stay consistent.
- **Liquidsoap source selection:** harbor **show** / **main** switches no longer require `source.is_ready(...)` only — on LS 2.3 it can stay false with valid PCM, which left automation on air despite a connected encoder.
- **Schedule queues:** after automation queue changes, playout can **resync** (flush and refill) to avoid stale crossfades / metadata on the wrong track.
- **Calendar week view & autoplaylist:** `getShowHasAutoplaylist()` in `legacy/application/models/ShowInstance.php` uses the same **overlap** window as `getContentCount()` / `getIsFull()` (`starts < p_end` and `ends > p_start`) so edge-of-week shows are not shown empty before `cc_schedule` fills. See [LibreTime #3235](https://github.com/libretime/libretime/issues/3235).
- **Autoplaylist fill:** `legacy/application/common/AutoPlaylistManager.php` also considers shows **already started but not finished** when `autoplaylist_built` is still false, and sets `autoplaylist_built` only if **`cc_schedule`** has rows after the attempt. See [LibreTime #3226](https://github.com/libretime/libretime/issues/3226).
- **PHP 8.4 compatibility:** `E_STRICT` removed and `E_DEPRECATED` / `E_USER_DEPRECATED` mapping bug fixed in `AirtimeLog.php` (string keys → integer constants); `_strftime_compat()` polyfill in `preload.php` replaces deprecated `strftime()` in 20 Propel temporal getters; implicit nullable types fixed across 88 generated `om/` files (884 lines, `Type $p = null` → `?Type $p = null`); `php8.4-intl` added to `packages.ini`; Propel patches for `Criteria::getIterator()` / `PropelPDO::query` / `PropelOnDemandCollection` signatures; `utf8_encode()` → `mb_convert_encoding()`.
- **JS modernization:** jQuery `.live()` → `.on()`, dead `console.log` removed, `class='artwork'` attribute fix, `.bind()` event delegation bug fixed.
- **Liquidsoap cleanup:** dead functions (`transition_default`, `to_live`, `cross_http`, `http_fallback`) removed from `ls_lib.liq`; `make_ouput_` typo corrected to `make_output_` in both `ls_lib.liq` and the Jinja output template.
- **Python modernization:** `datetime.utcnow()` replaced with `datetime.now(timezone.utc)` across the playout package; `UnboundLocalError` risk fixed in analyzer `message_listener.py`.
- **Install robustness:** `--wizard` validates TTY, blocks upgrade usage, and rejects combined positional URL; flags requiring arguments now fail with a clear message instead of a cryptic `shift` error; first install without a URL or `--wizard` is now blocked.
- **Version label:** root `VERSION` file (e.g. `0.0.5 trixie`); `tools/version.sh` does **not** overwrite it when it already contains a semver.

A chronological **development log** (Italian) lives in [`docs/development-log.md`](docs/development-log.md). Optional Cursor rule summary: [`.cursor/rules/development-log.mdc`](.cursor/rules/development-log.mdc).

After `git pull` on an installed host, redeploy changed paths (legacy PHP, playout, Liquidsoap) and restart services as usual.

---

## Uninstall

Run as **root** from a checkout that still contains **`installer/icecast/icecast.xml`** (so Icecast can be reset from the template).
You must now choose an explicit uninstall level:

```bash
sudo bash installer/uninstall-libretime.sh --keep-data
# or
sudo bash installer/uninstall-libretime.sh --remove-data
# or
sudo bash installer/uninstall-libretime.sh --purge-packages
```

- `--keep-data`: remove app/services/config while preserving media (`/srv/libretime`) and DB/broker data.
- `--remove-data`: also remove media plus PostgreSQL/RabbitMQ LibreTime data.
- `--purge-packages`: also attempt `apt purge` of common stack packages (dangerous on shared hosts).

The script stops and disables LibreTime units, removes app trees and integrations (`/opt/libretime`, `/etc/libretime`, `/var/lib/libretime`, `/var/log/libretime`, `/usr/share/libretime`, systemd units, Nginx site files including HTTPS proxy/Let's Encrypt references, Certbot hooks, helper scripts), deletes **`libretime` Lets Encrypt certificate** when applicable, removes Icecast TLS bundle, restores `icecast.xml`, re-enables Nginx default site if needed, and removes the `libretime` system user. Data and package removal depend on the selected mode. It does **not** remove your **git clone directory** (e.g. `/root/libretime`) unless you do it manually.

---

## Logs and troubleshooting

```bash
sudo journalctl -u libretime-api -u libretime-playout -u libretime-liquidsoap \
  -u libretime-analyzer -u libretime-worker -u redis-server -n 150 --no-pager
```

Text logs: `/var/log/libretime/` (`legacy.log`, `playout.log`, `analyzer.log`, …).

If you maintain a local copy of a stream diagnostic script (e.g. one that walks systemd, Icecast `status-json`, and playout/Liquidsoap journals before a short PCM probe), use it alongside the journal commands above.

---

## License

[GNU Affero General Public License v3.0](https://www.gnu.org/licenses/agpl-3.0.html). Full text in the `LICENSE` file. Use and distribution imply acceptance of AGPL-3.0; upstream LibreTime copyright notices in individual files still apply.
