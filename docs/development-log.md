# Development log (LibreTime Trixie fork)

High-level chronological notes for meaningful changes versus upstream LibreTime.  
Repository: `https://github.com/stefanolanci/libretime-trixie` — install target **Debian 13 (Trixie)**.

**Maintainers:** update this file when you merge user-facing, API, playout, or installer-impacting work so it stays a faithful diary of the fork (English only).

---

## Release identity and versioning

This repository is the **libretime-trixie** distribution: LibreTime packaged and tested for **Debian 13 (Trixie)**.  
Upstream code lineage: [LibreTime](https://github.com/libretime/libretime) (AGPL-3.0). This fork’s **release identity** is independent of upstream’s 4.x setuptools labels.

### At a glance

| What | Value | Where |
|------|--------|--------|
| **Distribution name** | libretime-trixie | README, Git remote URL |
| **Git release tag** | `v0.1.8-trixie` (leading `v`, codename after patch) | `git tag`, [GitHub Releases](https://github.com/stefanolanci/libretime-trixie/releases) |
| **Installer / OS label** | `0.1.8 trixie` (semver, space, codename) | Root file **`VERSION`**; printed by `./install`; copied into `legacy/` during install |
| **Python packages (setuptools)** | `0.1.8` | `shared/setup.py`, `api/setup.py`, `api-client/setup.py`, `playout/setup.py`, `analyzer/setup.py`, `worker/setup.py` |
| **User-facing changelog** | [CHANGELOG.md](../CHANGELOG.md) | Release notes index |
| **Engineering diary** | *this file* (sections below) | Maintainer-oriented detail |

### Rules

1. **New fork release** → bump root **`VERSION`** (first line), bump all **`setup.py`** `version=`, add a **`CHANGELOG.md`** section, tag **`v<semver>-trixie`**, push tag + `main`.
2. **`tools/version.sh`** does **not** overwrite **`VERSION`** when the first line already matches `^[0-9]+\.[0-9]+\.[0-9]+` (see script comments).
3. **Legacy “What’s new”** uses **`LIBRETIME_WHATS_NEW_URL`** / **`LIBRETIME_UPDATE_FEED`** in `legacy/application/configs/constants.php` — they must point at **this fork’s** Releases, not upstream’s.

### Check out a known release

```bash
git fetch origin tag v0.1.8-trixie
git checkout v0.1.8-trixie   # detached HEAD; fine for installs
# or stay on main after a release merge:
git checkout main && git pull
```

---

## 2026-04-22 — Docs: versioning merged into this file

- Removed redundant root **`VERSIONING.md`**. The **Release identity and versioning** section above is the only place for the tag / `VERSION` / setuptools table and rules; **README** and **CHANGELOG** now deep-link to it.

---

## 2026-04-22 — Release **v0.1.8-trixie** (canonical versioning)

- **Single reference for tag / `VERSION` / setuptools:** section **Release identity and versioning** at the top of this file (no separate `VERSIONING.md` in repo root).
- **Forge identity:** Git annotated tag **`v0.1.8-trixie`**, root **`VERSION`** remains **`0.1.8 trixie`** (semver + space + codename; `tools/version.sh` unchanged).
- **Python packages:** all component **`setup.py`** files now declare **`0.1.8`** (replacing inherited **`4.5.0`** from upstream metadata) and **`url` / `project_urls`** → `https://github.com/stefanolanci/libretime-trixie` so `pip`/wheel metadata matches this fork.
- **User-facing changelog:** root **`CHANGELOG.md`** indexes **`0.1.8-trixie`**; legacy **`LIBRETIME_WHATS_NEW_URL`** / **`LIBRETIME_UPDATE_FEED`** and **`config-check.php`** release-notes link target **this fork’s** GitHub Releases (not upstream’s).

---

## 2026-04-22 — `uninstall-libretime.sh` aligned with `./install`

- **fail2ban:** removes the same drop-ins `install` deploys when fail2ban is enabled (`/etc/fail2ban/filter.d/{libretime-harbor,icecast-auth,nginx-libretime-login}.conf`, `action.d/libretime-conntrack-flush.conf`, `jail.d/91-libretime.conf`, `logrotate.d/libretime-harbor-auth`), then `fail2ban-client reload` (or `systemctl try-reload-or-restart fail2ban`) if the service is active — *before* `rm -rf /var/log/libretime` so jails are not left pointing at deleted log paths.
- **Nginx HTTP logs:** also deletes `libretime.access.log` / `libretime.error.log` (not only the HTTPS proxy `libretime-proxy.*` pair).
- **System user:** reads `User=` from `libretime-api.service` (default `libretime`) for `pkill` / `userdel`, matching a non-default `--user` on install; guards against an unexpected `User=root`.
- **PostgreSQL / RabbitMQ:** parses `database.user`, `database.name`, `rabbitmq.user`, and `rabbitmq.vhost` from `/etc/libretime/config.yml` *before* removing `/etc/libretime`, and uses them in `dropdb` / `DROP ROLE` / `delete_user` / `delete_vhost` in `--remove-data` and `--purge-packages` (previously the script always assumed role/db/user `libretime` and vhost `/libretime`).
- **`--purge-packages`:** adds **`fail2ban`** to the `apt-get purge` list (after our jail files are already removed) so a dedicated single-purpose radio host can strip the service entirely; on shared systems this remains dangerous, as documented.
- **By design, unchanged:** UFW `allow` rules and any `127.0.1.1` line appended to `/etc/hosts` by the installer are not reverted (documented in script `--help` and README **Uninstall**).
- **Bugfix:** the `for u in ...` service loop clobbered a temporary `u` used for user detection — the loop variable was renamed to `unit`.

---

## 2026-04-22 — Installer hardening: upgrade path, idempotency, and satellite scripts

Multi-area cleanup of the root `install` script and of the Python/Bash helpers it invokes. No behavioral change for a successful first install; every change is scoped to make retries, upgrades, and partial-state recoveries predictable.

### Wizard UX polish (`install`)

- Top-of-wizard hint mentions that **`./install --help`** lists every non-interactive flag, so users who outgrow the guided flow know where to look without re-reading the README.
- **Certbot email** and **fail2ban** prompts are now dynamically pre-filled from `LIBRETIME_CERTBOT_EMAIL` and `LIBRETIME_SETUP_FAIL2BAN` when those variables are set in the environment or `.env`, matching the behavior of the other wizard prompts.
- The fail2ban prompt line for the web login was reworded to **"LibreTime web application login"** (previously implied a specific reverse-proxy scenario that did not apply to HTTP mode).
- `bantime` shown in the wizard fail2ban summary and in `installer/fail2ban/jail.d/91-libretime.conf` changed from **600 → 1800 s** (30 min), aligning with what is actually effective against brute-force retries without being too punitive for legitimate lockouts.

### Bash hardening (`install`)

- Added `set -o pipefail` on top of the existing `set -eu`, so pipelines fail loudly when an earlier command errors.
- `cd "$SCRIPT_DIR"` at the top of the script: every `"${SCRIPT_DIR}/installer/..."` path and every relative reference now resolves against a known CWD, independent of how the user invoked `./install` (e.g. from `~` with an absolute path).
- `python3` is now installed in the **Prepare** phase, before any helper (`tools/packages.py`) is invoked — previously `python3` was only guaranteed later in the flow.

### Upgrade path (existing `config.yml` detected)

A dedicated upgrade block runs before any destructive action and prints a summary of what is about to happen, then short-circuits on configuration drift:

- **Public URL rehydration.** When `LIBRETIME_PUBLIC_URL` is not passed on the command line during an upgrade, it is read from `/etc/libretime/config.yml` so downstream logic (fail2ban placeholder substitution, HTTPS decision branch, UFW rules) stays aligned with the running system instead of falling back to defaults.
- **`public_url` mismatch ⇒ hard error.** If the CLI-provided URL differs from the one in `config.yml`, the script refuses to continue with a self-documenting message (`"To change the public URL, edit ${CONFIG_FILEPATH} manually; to keep the current one, omit the positional URL"`). Prevents silent DB/config/URL drift on re-runs.
- **`--user` mismatch ⇒ hard error.** If `--user` points at a UID that does not own the existing `config.yml`, the script stops and tells the user exactly how to reconcile (either re-run with the detected user, or perform the user migration manually — `chown`, DB ownership, service restarts). Prevents partially-chowned installs.
- **Pre-upgrade warning summary.** Prints the detected layout (`config_file`, `public_url`, `storage`, `listen_port`, `https_mode`, `setup_icecast`, `setup_fail2ban`) and reminds the operator to keep upgrade flags aligned with the original install; includes a 5-second countdown on interactive TTYs (skipped under CI/pipes so non-interactive upgrades stay non-blocking).
- **Smarter HTTPS detection in the summary.** Distinguishes `"proxy only (no TLS cert)"` from `"yes (TLS cert present)"` by probing `/etc/letsencrypt/live/<host>/fullchain.pem` when the HTTPS proxy vhost exists — the previous summary called them both "yes" even if Certbot had failed.

### Idempotent credential handling

- **PostgreSQL.** On first-install retries (`is_first_install=true` but the role already exists), `install` now unconditionally runs `ALTER ROLE <user> WITH PASSWORD '<generated>'` so the password in the freshly written `config.yml.tmp` always matches what the database expects. Emits a warning so the admin knows why the password was reset.
- **RabbitMQ.** Same treatment: if the broker user already exists, `rabbitmqctl change_password` is executed to keep `config.yml` in sync. Prevents the class of failure where a failed first install left credentials out of sync between `config.yml` and the stack.

### Post-upgrade service restart

- After a detected upgrade, the installer now restarts already-active LibreTime units (`libretime-api`, `libretime-playout`, `libretime-liquidsoap`, `libretime-analyzer`, `libretime-worker`) via `service_restart_if_active`, so the newly deployed Python/legacy code is picked up without requiring a separate manual step.

### Satellite scripts (the previous "quality bottleneck")

- **`tools/packages.py`.** Fixed a real determinism bug: `list_packages` used to return `set(sorted(packages))` — `sorted()` returns a list, re-wrapping it in `set()` destroyed the ordering. `apt-get install` was fed a different permutation on every run; install logs were not diffable. Now returns a `List[str]`, sorted, and the CLI wrapper prints it with `"\n".join` / `" ".join` unchanged. Also: the `",".split()` delimiter is now tolerant of extra whitespace (`[d.strip() for d in distributions.split(",")]`), parentheses were added around the `not development and section == DEVELOPMENT_SECTION` clause to make operator precedence explicit, and an `stderr` warning is emitted for `--exclude` sections that are not present in any of the `packages.ini` files scanned.
- **`installer/icecast/patch_xml_ssl.py`.** Made idempotent. A new `ALREADY_PATCHED_MARKER` (`<ssl-certificate>/etc/icecast2/bundle.pem</ssl-certificate>`) is checked first; if present, the script logs `"already contains bundle.pem ssl-certificate; skipping"` and exits **0** instead of the previous **1** that triggered `warning "could not patch icecast.xml for TLS"` on re-runs. The `<hostname>localhost</hostname>` replacement now degrades gracefully when the hostname has already been customized (informational message, no failure).
- **`installer/letsencrypt/renew-icecast-bundle.sh`.** Hardened the Certbot deploy hook: `getent group icecast >/dev/null 2>&1 || exit 0` early-exits cleanly when Icecast is no longer installed (so a stale hook cannot fail the entire certificate renewal), and a `logger -t libretime-icecast-bundle` call writes a syslog trail on every skip/success so failed renewals are diagnosable from `/var/log/syslog` without having to dig into Certbot's own log.

### `VERSION`

- Bumped to `0.1.8 trixie`.

---

## 2026-04-22 — Fail2ban security suite for LibreTime (opt-in)

- **Opt-in installer step.** New `LIBRETIME_SETUP_FAIL2BAN` variable (default `false`), matching `--setup-fail2ban` / `--no-setup-fail2ban` CLI flags, and a wizard prompt at the end of the guided setup (`"Enable fail2ban for LibreTime? [y/N]"`). Non-wizard installs without the flag or the env variable are unchanged — no new services are touched. Handled in `install`.
- **Three jails, each strictly per-service.** New `installer/fail2ban/jail.d/91-libretime.conf` ships `libretime-harbor`, `icecast-auth`, and `nginx-libretime-login`. Each jail has its own filter, port set, nftables set, and action chain — a ban in one jail never affects sockets of another service. Policy aligned with the stock `sshd` jail (maxretry=5, findtime=3600, bantime=1800).
- **Harbor: file-based jail that sidesteps the Debian 13 systemd backend regression.** Observed behavior with fail2ban 1.1.0 + `python3-systemd` 235 on Trixie: the `systemd` backend silently missed `_SYSTEMD_UNIT=libretime-liquidsoap.service` matches, so the jail never incremented even though `fail2ban-regex` confirmed the pattern. Worked around by writing a dedicated line from `playout/libretime_playout/liquidsoap/ls_script.liq` via `file.write(append=true)` on `/var/log/libretime/harbor-auth.log` (format: `libretime-harbor[<mount>] status=auth_ok|auth_failed ip=<client>`). Filter `installer/fail2ban/filter.d/libretime-harbor.conf` uses `datepattern = {^LN-BEG}`, jail uses `backend=pyinotify`. The write is outside the audio graph (runs in the Harbor handshake thread, not in the DSP thread), `append=true` on a <80 byte line is atomic per `write(2)` below PIPE_BUF, so it has no effect on the audio chain.
- **Nginx: placeholder-driven port alignment between service and jail.** `@LIBRETIME_NGINX_PORTS@` in the jail template is substituted at install time — in HTTPS mode to `80,443`, in HTTP mode to `${LIBRETIME_LISTEN_PORT}` (default `8080`, overridable via `--listen-port` or env). Previous hard-coded `port = http,https` would have produced an `nftables` rule on ports 80/443 while nginx was actually listening on 8080; the ban was correctly emitted but targeted the wrong socket. The log path is similarly switched between `/var/log/nginx/libretime.access.log` (HTTP) and `/var/log/nginx/libretime-proxy.access.log` (HTTPS).
- **Nginx log fd reopen after pre-creation.** The installer now runs `nginx -t && systemctl reload nginx` right after pre-creating the LibreTime access log with `install -m 0640 -o www-data -g adm -D /dev/null ...`. Without the reload, nginx kept writing to the orphan inode of the previous file and the jail saw an empty log.
- **Icecast: reads `/var/log/icecast2/access.log` for HTTP 401 on `/admin/`** via `installer/fail2ban/filter.d/icecast-auth.conf`. Ports `8000,8443`.
- **`libretime-conntrack-flush` action — scoped socket kill for TCP keep-alive.** New `installer/fail2ban/action.d/libretime-conntrack-flush.conf`. Default nftables/iptables banactions only drop packets with `ct state NEW`, so HTTP keep-alive sockets (and, in theory, long-lived Icecast admin sockets) pre-dating the ban keep flowing. The new action closes those sockets using iproute2 `ss -K` (no extra package; requires `CONFIG_INET_DIAG_DESTROY=y`, default on Debian 13). Important: the `ports="..."` parameter is scoped to the jail's own service ports, so a ban in `nginx-libretime-login` never touches Icecast/Harbor/SSH sockets of the same IP. Attached only to the web/Icecast jails; Harbor does not need it because Liquidsoap opens a new TCP for each auth attempt.
- **Logrotate for the Harbor auth log.** New `installer/fail2ban/logrotate/libretime-harbor-auth.conf` (weekly, 12 rotations, `compress` + `delaycompress`, `create 0640 libretime adm`). No `postrotate` hook is needed because Liquidsoap opens the file per write (no persistent fd).
- **Functional ban/unban validation (per-jail).** Five failed `POST /login` → `nginx-libretime-login` increments → `NOTICE Ban`; materialized `nftables` rule is `tcp dport <configured-port> ip saddr @addr-set-nginx-libretime-login reject with icmp port-unreachable`; `ss -K` closes pre-existing keep-alive sockets scoped to the jail port; browser immediately reports "site unreachable" for new connections; `unbanip` restores normal traffic. Harbor jail exercised on both `main` (8001) and `show` (8002) mounts, Icecast jail exercised by repeated 401s on `/admin/`. Port-swap sanity run at 3345 confirmed the `@LIBRETIME_NGINX_PORTS@` substitution tracks the actual listen port.
- **End-to-end wizard install on a clean Debian 13 Trixie snapshot.** `./install --wizard` answered **`y`** at the fail2ban prompt materializes `/etc/fail2ban/filter.d/{libretime-harbor,icecast-auth,nginx-libretime-login}.conf`, `/etc/fail2ban/action.d/libretime-conntrack-flush.conf`, `/etc/fail2ban/jail.d/91-libretime.conf` (placeholders resolved), `/etc/logrotate.d/libretime-harbor-auth`, and pre-creates `/var/log/libretime/harbor-auth.log` (`libretime:adm 0640`) plus the nginx access log with the post-`install` nginx reload. `fail2ban.service` starts clean with all four jails active (`sshd, libretime-harbor, icecast-auth, nginx-libretime-login`). Ban/unban tests re-executed on the freshly wizarded target pass identically to the in-place run.
- **Commit reference:** `feat(installer): finalize fail2ban security suite (end of development)` (code); wizard acceptance run recorded 2026-04-22 on LAN Debian 13 Trixie target.

---

## 2026-04-21 — Pre-login player buttons: early initialization on embed DOM ready

- **Root cause (runtime-verified):** on the public pre-login page, schedule/about/podcast buttons were appended by the parent page only inside the `#player_iframe.load(...)` callback. The iframe `load` event was delayed by late resource/media completion, so the buttons appeared several seconds after the rest of the page.
- **`legacy/application/views/scripts/embed/player.phtml`:** the embed now posts a same-origin message (`libretime:player-embed-dom-ready`) to the parent as soon as the iframe document reaches `$(document).ready(...)`.
- **`legacy/application/views/scripts/index/index.phtml`:** added `initPreloginBottomButtons()` and moved button wiring to an idempotent initializer triggered by the new DOM-ready message, with existing iframe `load` as a safe fallback.
- **Result:** bottom-bar controls become visible much earlier and no longer depend on full iframe load completion.
- **`VERSION`:** bumped to `0.1.7 trixie`.

---

## 2026-04-21 — Icecast listener stability tuning (burst disabled)

- **`installer/icecast/icecast.xml`:** changed Icecast global listener burst behavior to `burst-on-connect=0` and `burst-size=0` to avoid short repeated startup slices on unstable/mobile paths where clients rapidly reconnect and can replay the same initial buffered segment.
- **Runtime validation on Jupiter:** after applying the same configuration in production, listener sessions became materially longer in repeated stop/play/pause stress tests (including mobile app and browser players), while the playout chain stayed healthy (`stream_level_probe` remained active without service restarts in the verified window).
- **`VERSION`:** bumped to `0.1.6 trixie`.

---

## 2026-04-21 — Liquidsoap 2.3 deprecation cleanup and idempotent web-stream restart

- **`playout/libretime_playout/liquidsoap/templates/entrypoint.liq.j2`:** replaced the legacy `set("path.to.key", value)` calls with the Liquidsoap 2.3 assignment syntax `settings.path.to.key := value` across `log.file.path`, `server.telnet` / `server.telnet.bind_addr` / `server.telnet.port`, `harbor.bind_addrs`, and the `harbor.ssl.*` block. The generated `radio.liq` no longer carries LS 2.3 deprecation warnings at startup.
- **`playout/libretime_playout/liquidsoap/ls_script.liq`:** replaced deprecated `audio_to_stereo(...)` with `stereo(...)` on the automation queue source and on the `/show` and `/main` harbor inputs; replaced `map_metadata(...)` with `metadata.map(...)` on the queue-notify, schedule append-title and offline-label chains.
- **`playout/libretime_playout/liquidsoap/ls_lib.liq`:** replaced the last deprecated `json_of(m)` call in `notify_stream` with `json.stringify(m)`. With these three files `liquidsoap --check` on the generated script emits zero deprecation warnings on LS 2.3.
- **`playout/libretime_playout/liquidsoap/ls_lib.liq`:** added `start=false` to the dummy-URL `input.http` bootstrap so the HTTP source stays idle until a real web-stream URL is armed through the `restart` telnet command. This removes the repeated 2-second reconnect loop against the bootstrap sentinel URL that was flooding the Liquidsoap journal while no web stream was active.
- **`playout/libretime_playout/liquidsoap/ls_lib.liq`:** made the `input.http_restart` telnet command **idempotent** — it keeps a `last_url` reference and skips the `http.stop` / `http.start` cycle when the same URL is re-asserted while the HTTP source is already streaming. Playout can re-issue `http.restart <same url>` on schedule-refresh events while a web-stream slot is still armed; the previous non-idempotent behaviour produced a sub-frame track boundary (Liquidsoap "Source created multiple tracks in a single frame!") that corrupted PCM frames sent to the local Icecast outputs, triggering **Broken pipe** on `/main` and `/main-low` and briefly disconnecting listener apps. First activation, real URL changes, and network-recovery restarts keep going through the full stop/start cycle; the idempotent path only short-circuits the redundant same-URL re-assertions and is logged as `idempotent no-op`.
- **`VERSION`:** bumped to `0.1.5 trixie` for Settings → Status and packaging consistency.

---

## 2026-04-20 — Liquidsoap handoff hardening (web stream + live cut behavior)

- **`playout/libretime_playout/liquidsoap/ls_script.liq`:** hardened the automation source-selection guard so the queue branch remains selected while web stream handoff state is still active (`schedule_streaming() or web_stream_enabled() or web_stream_armed() or web_stream_id() != "-1"`). This reduces unintended fallbacks during short handoff windows.
- **`playout/libretime_playout/liquidsoap/ls_script.liq`:** normalized web stream state transitions (`web_stream_id` initialization, trimmed IDs in `web_stream_set_id`, explicit `web_stream_armed` set/clear on start/stop) so control flow is deterministic across transient API updates.
- **`playout/libretime_playout/liquidsoap/client/_client.py` + playout queue sync path:** when the currently playing scheduled row is removed/changed, playout now requests a targeted force-cut on the active queue slot and immediately re-syncs queue content, keeping automation aligned with schedule edits.

---

## 2026-04-19 — README and development log vs installer (Debian conventions)

- **README:** expanded **“What `./install` does”** to match the root `install` script (distribution gate, Prepare and `sudo`/`git`/`make`/`ed` bootstrap, first-install vs upgrade, `installer/` templates, PostgreSQL/RabbitMQ/Icecast, Python venv and `tools/packages.py`, legacy build, Nginx, HTTPS/Certbot/Icecast hooks, UFW, finalize). Clarified **Debian-first** usage: run **`./install` as root** without assuming `sudo` is pre-installed; the installer’s Prepare step installs the **`sudo`** package so documented **`sudo -u libretime`** steps work **after** install, with **`runuser` / `su`** alternatives for migrations.
- **`docs/development-log.md`:** removed per-host operational duplication; **post-install and firewall** remain the single source of truth in the root **README**.

---

## 2026-04-18 — Release v0.1.3-trixie (GitHub) and workflow docs

- **Distribution label:** `VERSION` set to **0.1.3 trixie** for Settings → Status and packaging consistency.
- **GitHub:** release/tag **v0.1.3-trixie** replaces **v0.1.2-trixie** (includes prior `main` fixes such as install wizard HTTP/public URL handling and `development-log` policy).

---

## 2026-04-18 — Install wizard: HTTP URL normalization (typos + implicit listen port)

- **`install` (root script):** `wizard_fix_http_scheme_typos` corrects common mistakes (`http:host`, `http:/host`) to valid `http://…`.
- **`wizard_normalize_http_public_url`:** for plain `http://` URLs without an explicit TCP port, append **`LIBRETIME_LISTEN_PORT`** (same as `--listen-port`) so browser Origin/CORS matches Nginx; IPv6 bracketed hosts and explicit `:port` left unchanged.
- **Wizard copy:** documents that omitting the port in HTTP mode auto-appends the listen port.

---

## 2026-04-16 — Public radio page (responsive player + schedule widget)

- **Rollback tag:** `rollback/pre-radio-ux-2026-04-16` (annotated) on the commit before the embed UI changes.
- **`premium_player.css`:** flex toolbar; “now playing” uses `min-width: 0` and wrapping (removed `white-space: nowrap` that clipped title/artist); dropped media queries that squeezed `.on_air` to 30–50%; mobile: fixed strip above the bar with readable text.
- **Post-deploy fix:** `flex_spacer` must not use `flex-grow` (it left half the bar empty); `margin-left: auto` on `.schedule_btn`; schedule tabs default to the station’s **current calendar day** (`currentDayOfMonth`), not always the first day.
- **`radio-page.css`:** `.wrapper` prevents horizontal overflow; tabs/iframe centered with `min()` + `translateX` instead of a fixed negative margin.
- **`weekly-program.phtml` + `EmbedController`:** embed viewport; station timezone for `toLocaleTimeString`; show day key from **UTC** aligned with PHP `weekDays`.
- **`weekly-schedule-widget.css` / `station-podcast.css`:** fluid flex tabs; jPlayer podcast block `max-width: 100%`.

---

## 2026-04 — PLC dashboard and playout telemetry

- **PLC strip in header** (`legacy/…/header.phtml`, `styles.css`, `dashboard.js`): six-bit synoptic with **PLC REAL** / **PLC LOGIC** labels; **LNK, FLW, AUD, ICE** (real chain) and **FET, PLAY** (logic) in a aligned grid; **State / Detail** copy plus anomaly row.
- **Colour severity** on the status row (and anomalies when present): green = nominal (`111111`), yellow = non-blocking anomaly, red = critical or stale data; spacing between the lamp matrix and the first text row.
- **Playout backend:** `PipelineMonitor` in `playout/…/pipeline.py` (thread) aggregates signals (level/link/flow probe, Icecast JSON, schedule, `.now_playing_sid`) and posts JSON to **`/api/playout-state`** for the UI; `stream_level_probe.py`, `main.py`, and Liquidsoap client tweaks for consistency with the audio chain.
- **Assets:** `legacy/application/assets.json` refreshed after CSS/JS edits (browser cache checksums).

---

## 2026-04 — Harbor live / Master & Show (fork context)

- **Main** (typical port **8001**, mount `/main`) and **show** (**8002**, `/show`) switchovers exercised on a test VPS: `harbor:input_main` / `harbor:input_show` logs, `switch_source` for `master_dj` / `live_dj`, Liquidsoap transitions and return to automation without notable service errors in journals.

---

## Additional fork highlights (see also README “Changes in this fork”)

- **Station podcast:** Publish from Tracks restored; episode metadata persistence; **My Podcast** navigation and DataTables behaviour fixes.
- **Public radio page:** configurable background image/fit in General Settings; homepage rendering with dark overlay.
- **Localization:** login locale persistence (cookie + preference); PHP 8.4 gettext bootstrap; string pass across `en_US`, `en_GB`, `it_IT`, `fr_FR`, `es_ES`, `pt_BR`.
- **First-track level:** `schedule_streaming` enabled only after `PypoPush` seeds Liquidsoap’s `request.queue` so replay-gain metadata exists for `amplify` on the first item.
- **Live / API order:** DB connection state updated **before** RabbitMQ `switch_source` in legacy controllers so `GET /api/v2/stream/state` matches playout.
- **Liquidsoap 2.3:** harbor show/main no longer gated on `source.is_ready(...)` alone when PCM is valid.
- **Schedule / autoplaylist:** week overlap and autoplaylist fill fixes aligned with upstream issues [#3235](https://github.com/libretime/libretime/issues/3235), [#3226](https://github.com/libretime/libretime/issues/3226).
- **PHP 8.4 / Python / JS / Liquidsoap / installer:** compatibility and cleanup as summarized in the root README.

---

*Last log update: 2026-04-22 (uninstall-libretime.sh aligned with install — fail2ban, logs, system user, PG/Rabbit identity from config; plus the installer-hardening and fail2ban work from the same day).*
