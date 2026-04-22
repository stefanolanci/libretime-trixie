# LibreTime (Debian Trixie)

**Distribution:** [libretime-trixie](https://github.com/stefanolanci/libretime-trixie) **v0.1.10-trixie** (release line). **Git tag** (annotated checkout): **`0.1.10-trixie`** — same **`0.1.10`** triple as **`VERSION`** and Python packages; the tag ref has **no** leading **`v`** (see **[Release identity](docs/development-log.md#release-identity-and-versioning)**).

Native radio automation for **Debian 13 (Trixie)**. Installation uses the root `install` script (systemd units, no containers).

This tree targets **Trixie** specifically (PHP **8.4**, current Python, **Liquidsoap ≥ 2.3** from Debian). It includes **operational fixes** compared to a generic LibreTime line: legacy patches, a Celery worker backed by **Redis**, a **single** Liquidsoap bundle (`ls_script.liq` / `ls_lib.liq` under `playout/`), and Composer from APT.

**Upstream:** derived from [LibreTime](https://github.com/libretime/libretime). Changes in this repository are under **AGPL-3.0**, consistent with upstream licensing.

---

## Contents

- [Versioning (tag, VERSION, Python)](docs/development-log.md#release-identity-and-versioning)
- [Development log](docs/development-log.md)
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
- **Root** access: the **`install` script must run as UID 0** (see the check at the start of `./install`). On Debian this normally means an interactive **root shell** (`su -`, `ssh root@…`, or the root account on the console)—not an Ubuntu-style assumption that every command is prefixed with `sudo`. A minimal netinst often has **no `sudo` package** until you install it; that is fine **for running `./install` itself**, because the installer’s **Prepare** phase runs `apt-get install` and explicitly pulls in **`sudo`** (along with `git`, `make`, `ed`, `curl`, `ca-certificates`) so later steps (e.g. `sudo -iH -u postgres …`) and the post-install hints (`sudo -u libretime …`) work **after** a successful first run. If you already use a non-root account with `sudo`, you can invoke `sudo ./install …` only **after** `sudo` exists on the system.
- Working network so `apt` can reach mirrors.
- Recommended: dedicated VM or machine, **≥ 2 GB RAM**, enough disk for dependencies and media.
- Recommended: **UTF-8 locale** (e.g. generate `en_US.UTF-8` or your locale) to reduce PostgreSQL/Python warnings and ensure translations/localized UI strings load correctly.

**Stack on Trixie:** PHP **8.4** (FPM), Python **≥ 3.11**, PostgreSQL, **Redis** (Celery results), RabbitMQ, Nginx. APT package lists (`*/packages.ini`, `tools/packages.py`) are **only** for Debian 13 (trixie).

Optional right after OS install (**as root**):

```bash
apt update
apt full-upgrade -y
# optional: remote administration
apt install -y openssh-server
```

---

## Get the source

**Option A — Git** (recommended for development or updates):

```bash
apt install -y git
git clone https://github.com/stefanolanci/libretime-trixie.git libretime-trixie
cd libretime-trixie
```

**Option B — Archive** without `.git`: ensure a `VERSION` file exists in the tree root (otherwise `make VERSION` produces a generic placeholder).

Optional **`.env`** in the same directory as `install`: the script sources it so you can persist `LIBRETIME_*` variables; CLI flags override environment values.

### What `./install` does (summary)

1. **Distribution gate** — Aborts unless `/etc/os-release` indicates **Debian** with **Trixie** (`VERSION_ID=13` or `VERSION_CODENAME=trixie`).
2. **Prepare** — Runs as **root** only; may append **`127.0.1.1 hostname`** to `/etc/hosts` if the machine name does not resolve (avoids noisy Debian resolver warnings). Runs `apt-get update`, installs **`sudo`**, `git`, `make`, `ed`, `curl`, `ca-certificates`, then **`make VERSION`** (uses **`tools/version.sh`**).
3. **First install vs upgrade** — If `/etc/libretime/config.yml` **does not** exist: first install (requires **`public_url`** or **`--wizard`**; optional interactive **`--wizard`** must have a TTY and must not be combined with a positional URL). If config **already** exists: **upgrade** path only (wizard forbidden; storage path must match existing `storage.path`).
4. **Configuration draft** — Copies **`installer/config.yml`** to a temporary file under `/etc/libretime/`, then uses **`ed`** to inject `public_url`, API keys, timezone, storage path, and service passwords as applicable.
5. **Stack** — Optionally sets up **PostgreSQL** (creates role/DB via `postgres` user), **RabbitMQ** (user + vhost), **Icecast** (package + **`installer/icecast/icecast.xml`** with generated passwords). Always installs **Python venv** under **`/opt/libretime`**, resolves **`tools/packages.py`** per component, **`pip install`** for **`shared`**, **`api-client`**, **`api[prod]`**, **`playout`**, **`analyzer`**, **`worker`**, deploys **systemd** units and **logrotate** snippets, installs **Redis** for the worker.
6. **Legacy** — Installs PHP/Composer stack from **`legacy/packages.ini`**, runs **`make -C legacy build`**, deploys PHP-FPM pool and legacy tree to **`/usr/share/libretime/legacy`** (or **`--in-place`** keeps the tree inside the clone).
7. **Nginx** — Deploys **`installer/nginx/libretime.conf`** (internal listen **`--listen-port`**, default **8080**), disables the default site on first install.
8. **HTTPS (first install only)** — When `public_url` is **`https://…`** and **`LIBRETIME_HTTPS_AUTO`** is true (default): installs **Certbot** + **python3-certbot-nginx**, deploys **`installer/nginx/libretime-https-proxy.conf`**, runs **certbot --nginx**, optionally builds Icecast **`bundle.pem`**, runs **`installer/icecast/patch_xml_ssl.py`** and **`installer/config/patch_icecast_public_urls.py`**, installs **`installer/letsencrypt/renew-icecast-bundle.sh`** as a deploy hook. On failure, prints a manual **certbot** hint.
9. **UFW** — If **ufw** is active, opens ports for the chosen mode (80/443 for HTTPS automation, listen port for HTTP, Icecast/Harbor ports when Icecast is enabled).
10. **Fail2ban (opt-in)** — When **`LIBRETIME_SETUP_FAIL2BAN=true`** (or **`--setup-fail2ban`**, or **"y"** at the wizard prompt), installs **fail2ban** and deploys three LibreTime jails — **`libretime-harbor`** (reads a dedicated Liquidsoap-written log), **`icecast-auth`** (Icecast `/admin/` HTTP 401), **`nginx-libretime-login`** (LibreTime web `/login` brute-force) — plus a scoped **`libretime-conntrack-flush`** action that closes pre-existing TCP keep-alive sockets of the banned IP on the same ports, and a logrotate entry for `/var/log/libretime/harbor-auth.log`. Policy matches the stock `sshd` jail (`maxretry=5`, `findtime=3600`, `bantime=1800`). Default is **disabled**; upgrades without the flag leave fail2ban untouched.
11. **Finalize** — Moves the temp config to **`/etc/libretime/config.yml`**, enables **nginx**, **`php*-fpm`**, **`libretime.target`**, reloads nginx/php-fpm.

### What the published tree must contain for `./install`

From a **clean clone** of this fork, **`./install`** expects **`installer/`** (including **`config.yml`** template, **`nginx/`**, **`icecast/`**, **`letsencrypt/`**, **`uninstall-libretime.sh`**, **`systemd/libretime.target`**), **`tools/packages.py`**, **`tools/version.sh`**, and the application directories **`shared/`**, **`api-client/`**, **`api/`**, **`playout/`**, **`analyzer/`**, **`worker/`**, **`legacy/`**, plus root **`VERSION`** and **`docs/development-log.md`**. The published repository includes **only** those two paths under **`tools/`**; optional local helpers (deploy scripts, diagnostics under **`scripts/`**, etc.) are **not** shipped on GitHub—add them locally if you use them. They are **not** invoked by `./install`.

---

## Installation

From the repository root, make scripts executable if needed (e.g. archives or copies that dropped the executable bit):

```bash
chmod +x install tools/version.sh installer/uninstall-libretime.sh installer/letsencrypt/renew-icecast-bundle.sh
```

### Basic usage

Pass the **public URL** listeners will use (HTTP or HTTPS). Examples assume a **root shell** on the server (`#` prompt); if you use `sudo` after it is installed, prefix the same commands.

```bash
cd /path/to/libretime
./install http://192.168.1.10:8080
# or
./install https://radio.example.org
```

To keep the app files inside the clone (development):

```bash
./install --in-place https://radio.example.org
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
LIBRETIME_HTTPS_AUTO=false ./install https://radio.example.org
# or
./install --no-https-auto https://radio.example.org
```

Relevant environment variables:

| Variable | Role |
|----------|------|
| `LIBRETIME_CERTBOT_EMAIL` | Let’s Encrypt account email (recommended). If empty, Certbot uses `--register-unsafely-without-email`. |
| `LIBRETIME_ICECAST_HTTPS_PORT` | Icecast TLS listen port in generated URLs (default **8443**). |

### Interactive wizard

For a guided flow (HTTP vs HTTPS, Certbot email, summary):

```bash
./install --wizard
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
| `--setup-fail2ban` / `--no-setup-fail2ban` | Install (or skip) the opt-in fail2ban jails for Harbor, Icecast, and LibreTime web login. Also settable via `LIBRETIME_SETUP_FAIL2BAN=true` or the wizard prompt. Default: **disabled**. |

You can persist settings in a **`.env`** file next to `install`; flags override environment variables.

---

## After install

1. **Review configuration**  
   Edit `/etc/libretime/config.yml` as needed (database and RabbitMQ passwords are already in the file if the installer created them). Check `general.public_url`, `general.timezone`, `storage.path`, and stream settings.

2. **Database migrations**

   After a successful install, the **`sudo`** package is present. Typical invocation:

   ```bash
   sudo -u libretime libretime-api migrate
   ```

   If you prefer **not** to use `sudo` (e.g. minimal habit on Debian), equivalent options include:

   ```bash
   runuser -u libretime -- libretime-api migrate
   # or: su - libretime -s /bin/sh -c "libretime-api migrate"
   ```

3. **Start services**

   ```bash
   systemctl start libretime.target
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
- **Icecast listener startup burst disabled:** `installer/icecast/icecast.xml` now sets `burst-on-connect=0` and `burst-size=0` by default to reduce short reconnect loops and repeated startup slices observed on some client/network paths.
- **Pre-login player UX:** bottom-bar action buttons (schedule/about/podcast) are now initialized when the embed iframe DOM is ready (via `postMessage`) instead of waiting for iframe `load`, removing delayed button appearance on slower media/resource completion.
- **Web stream handoff guard:** `ls_script.liq` keeps the automation queue path selected while web-stream handoff state is still armed/identified (`web_stream_enabled`, `web_stream_armed`, non-`-1` stream id), reducing transient fallback flips during source transitions.
- **Liquidsoap 2.3 deprecation cleanup:** replaced `set("k", v)` with `settings.k := v`, `audio_to_stereo(...)` with `stereo(...)`, `map_metadata(...)` with `metadata.map(...)`, and `json_of(...)` with `json.stringify(...)` across `entrypoint.liq.j2`, `ls_script.liq`, and `ls_lib.liq`. The generated `radio.liq` runs on LS 2.3 with zero deprecation warnings.
- **Idempotent web-stream restart:** the `input.http_restart` telnet command in `ls_lib.liq` tracks the last URL and skips the `http.stop`/`http.start` cycle when playout re-asserts the same URL on a still-streaming HTTP source. This removes the occasional sub-frame track boundary ("Source created multiple tracks in a single frame!") that corrupted PCM on the local Icecast outputs and produced brief "Broken pipe" events on `/main` / `/main-low`, previously disconnecting listener apps during schedule-refresh while a web-stream slot was armed.
- **Idle HTTP input at startup:** the dummy-URL `input.http` bootstrap is now created with `start=false`, so Liquidsoap does not flood the journal with reconnect attempts against the sentinel URL while no web stream is armed; the HTTP source only leaves idle when a real URL is armed through `http.restart`.
- **Schedule queues:** after automation queue changes, playout can **resync** (flush and refill) to avoid stale crossfades / metadata on the wrong track.
- **Current-track removal sync:** when an in-flight scheduled row is edited/removed, playout can trigger a targeted force-cut on the active queue slot, then re-queue immediately so on-air content follows schedule state without waiting for natural EOF.
- **Calendar week view & autoplaylist:** `getShowHasAutoplaylist()` in `legacy/application/models/ShowInstance.php` uses the same **overlap** window as `getContentCount()` / `getIsFull()` (`starts < p_end` and `ends > p_start`) so edge-of-week shows are not shown empty before `cc_schedule` fills. See [LibreTime #3235](https://github.com/libretime/libretime/issues/3235).
- **Autoplaylist fill:** `legacy/application/common/AutoPlaylistManager.php` also considers shows **already started but not finished** when `autoplaylist_built` is still false, and sets `autoplaylist_built` only if **`cc_schedule`** has rows after the attempt. See [LibreTime #3226](https://github.com/libretime/libretime/issues/3226).
- **PHP 8.4 compatibility:** `E_STRICT` removed and `E_DEPRECATED` / `E_USER_DEPRECATED` mapping bug fixed in `AirtimeLog.php` (string keys → integer constants); `_strftime_compat()` polyfill in `preload.php` replaces deprecated `strftime()` in 20 Propel temporal getters; implicit nullable types fixed across 88 generated `om/` files (884 lines, `Type $p = null` → `?Type $p = null`); `php8.4-intl` added to `packages.ini`; Propel patches for `Criteria::getIterator()` / `PropelPDO::query` / `PropelOnDemandCollection` signatures; `utf8_encode()` → `mb_convert_encoding()`.
- **JS modernization:** jQuery `.live()` → `.on()`, dead `console.log` removed, `class='artwork'` attribute fix, `.bind()` event delegation bug fixed.
- **Liquidsoap cleanup:** dead functions (`transition_default`, `to_live`, `cross_http`, `http_fallback`) removed from `ls_lib.liq`; `make_ouput_` typo corrected to `make_output_` in both `ls_lib.liq` and the Jinja output template.
- **Python modernization:** `datetime.utcnow()` replaced with `datetime.now(timezone.utc)` across the playout package; `UnboundLocalError` risk fixed in analyzer `message_listener.py`.
- **Install robustness:** `--wizard` validates TTY, blocks upgrade usage, and rejects combined positional URL; flags requiring arguments now fail with a clear message instead of a cryptic `shift` error; first install without a URL or `--wizard` is now blocked.
- **Installer robustness (upgrades, retries, helpers):** upgrades rehydrate `public_url` when omitted, **fail fast** on `public_url` or `--user` mismatches, print a concise pre-upgrade summary (HTTPS vs proxy-only detection via Let’s Encrypt paths), restart active LibreTime services after upgrade, and on first-install retries **resync PostgreSQL/RabbitMQ passwords** with `config.yml`. The script uses **`set -o pipefail`**, a stable **`cd` to its directory**, and installs **`python3`** before **`tools/packages.py`**. **`tools/packages.py`** emits a deterministic package order; **`installer/icecast/patch_xml_ssl.py`** is idempotent when TLS is already configured; **`installer/letsencrypt/renew-icecast-bundle.sh`** no-ops cleanly if Icecast was removed and logs renewals to syslog for operations.
- **Opt-in fail2ban suite:** wizard, CLI (`--setup-fail2ban` / `--no-setup-fail2ban`), and **`LIBRETIME_SETUP_FAIL2BAN`** deploy three jails—Harbor Liquidsoap auth (dedicated log), Icecast **`/admin`** failures, Nginx LibreTime **`/login`**—with timing aligned to common **`sshd`** defaults. Harbor events are written from `ls_script.liq` to a stable log path; Nginx jail ports match HTTP vs HTTPS proxy installs; optional **`libretime-conntrack-flush`** closes keep-alive sockets on ban for web/Icecast jails. Harbor logrotate (weekly, compressed). Default: **disabled**.
- **Version label:** root `VERSION` file (e.g. `0.1.10 trixie`); `tools/version.sh` does **not** overwrite it when it already contains a semver. **Git tag (this repo):** **`M.m.p-trixie`** without a leading **`v`** (e.g. **`0.1.10-trixie`**; see **[docs/development-log.md — Release identity](docs/development-log.md#release-identity-and-versioning)**).

A **development log** (English), including release versioning rules, is in [`docs/development-log.md`](docs/development-log.md).

After updating the installed tree from version control, redeploy changed application paths (legacy PHP, playout, Liquidsoap) and restart services as needed.

---

## Uninstall

Run as **root** from a checkout that still contains **`installer/icecast/icecast.xml`** (so Icecast can be reset from the template).
You must now choose an explicit uninstall level:

```bash
bash installer/uninstall-libretime.sh --keep-data
# or
bash installer/uninstall-libretime.sh --remove-data
# or
bash installer/uninstall-libretime.sh --purge-packages
```

- `--keep-data`: remove app/services/config while preserving media (`/srv/libretime`) and DB/broker data.
- `--remove-data`: also remove media plus PostgreSQL and RabbitMQ objects for the **database name, role, broker user, and vhost** read from `/etc/libretime/config.yml` before the file is removed (defaults match `./install`: database `libretime`, user `libretime`, RabbitMQ vhost `/libretime`).
- `--purge-packages`: also attempt `apt purge` of common stack packages (dangerous on shared hosts), including **`fail2ban`** if you asked the installer to deploy the opt-in LibreTime jails (and in any case when purging the whole stack).

The script stops and disables LibreTime units, removes app trees and integrations (`/opt/libretime`, `/etc/libretime`, `/var/lib/libretime`, `/var/log/libretime`, `/usr/share/libretime`, systemd units, Nginx vhosts and both HTTP- and proxy-mode Nginx log files, Certbot deploy hooks, `/usr/local/sbin/*libretime*` helpers), **removes the LibreTime fail2ban drop-ins** the installer may have created (`filter.d`/`action.d`/`jail.d` and `logrotate.d/libretime-harbor-auth`) and **reloads fail2ban** if it is active, deletes the **Let's Encrypt** certificate for the hostname derived from `public_url` when applicable, removes the **Icecast TLS** bundle, restores **`icecast.xml`**, re-enables the Nginx default site if needed, and removes the **application system user** (reads `User=` from `libretime-api.service` so a non-default `--user` from `./install` is handled; default is `libretime`). **UFW** rules that `./install` may have added (`ufw allow …`) are **not** reverted; remove them manually with `ufw status numbered` if the host is no longer a LibreTime box. The installer may also have appended a **`127.0.1.1` line to `/etc/hosts`**; that line is not stripped. Data and package removal depend on the selected mode. It does **not** remove your **git clone directory** (e.g. `/root/libretime`) unless you do it yourself.

---

## Logs and troubleshooting

```bash
journalctl -u libretime-api -u libretime-playout -u libretime-liquidsoap \
  -u libretime-analyzer -u libretime-worker -u redis-server -n 150 --no-pager
```

Text logs: `/var/log/libretime/` (`legacy.log`, `playout.log`, `analyzer.log`, …).

For stream or codec issues, combine the commands above with Icecast **`status-json`**, Liquidsoap telnet/API checks, and playout logs under **`/var/log/libretime/`** as appropriate for your setup.

---

## License

[GNU Affero General Public License v3.0](https://www.gnu.org/licenses/agpl-3.0.html). Full text in the `LICENSE` file. Use and distribution imply acceptance of AGPL-3.0; upstream LibreTime copyright notices in individual files still apply.
