# Development log (LibreTime Trixie fork)

English summary of **product and installer-visible** changes versus upstream LibreTime—features, fixes, compatibility, UX, and stack behavior relevant to administrators.

Repository: `https://github.com/stefanolanci/libretime-trixie` — target **Debian 13 (Trixie)**.

Update this file when you ship meaningful fork changes so downstream users can follow releases without digging through Git history.

---

## Release identity and versioning

This repository is the **libretime-trixie** distribution: LibreTime packaged and tested for **Debian 13 (Trixie)**. Upstream lineage: [LibreTime](https://github.com/libretime/libretime) (AGPL-3.0). This fork’s **release identity** is independent of upstream’s setuptools labels.

**Semantic version (one triple):** **Major.Minor.Patch** (e.g. `0.1.16`). The same triple appears in **`VERSION`**, **`setup.py`**, and the **Git tag** before `-trixie`.

| Layer | Format | Example |
|--------|--------|---------|
| **Git tag (this repo)** | `Major.Minor.Patch-trixie` (**no** leading `v` on the tag ref) | **`0.1.16-trixie`** |
| **Root `VERSION`** | `Major.Minor.Patch` + space + `trixie` | **`0.1.16 trixie`** |
| **Python `setup.py`** | `version="Major.Minor.Patch"` | **`0.1.16`** |

User-facing history for this fork is maintained **here** (no separate root changelog). [GitHub Releases](https://github.com/stefanolanci/libretime-trixie/releases) may use a readable title such as **v0.1.16-trixie** while the **tag ref** remains **`M.m.p-trixie`**. One triple ⇒ one annotated tag and one release per bump.

### Release checklist

1. **New fork release** → bump **Major**, **Minor**, or **Patch** once; update **`VERSION`**, all **`setup.py`** `version=` entries; append a dated section below; tag **`M.m.p-trixie`** and publish **one** GitHub release for that tag.
2. **`tools/version.sh`** does **not** overwrite **`VERSION`** when the first line already matches `^[0-9]+\.[0-9]+\.[0-9]+` (see script comments).
3. Legacy **What’s new** URLs (**`LIBRETIME_WHATS_NEW_URL`** / **`LIBRETIME_UPDATE_FEED`** in `legacy/application/configs/constants.php`) must point at **this fork’s** Releases, not upstream’s.

### Check out a known release

```bash
git fetch origin tag 0.1.16-trixie
git checkout 0.1.16-trixie   # detached HEAD; fine for installs
# or stay on main after a release merge:
git checkout main && git pull
```

---

## Changelog (newest first)

### 2026-05-01 — **v0.1.16-trixie** (patch)

- Bumped fork semver to **0.1.16** in **`VERSION`** and all component **`setup.py`** files.
- **Apple Podcasts readiness:** station podcast settings now support Apple-focused owner metadata, podcast type, category/subcategory selection, dedicated artwork upload/removal, public artwork delivery, and non-blocking readiness diagnostics.
- **Podcast RSS output:** station feeds emit Apple-compatible namespaces and `itunes:*` metadata, including owner, artwork, show type, explicit flags, nested categories, atom self links, and episode-level Apple defaults.
- **Smart Blocks editor:** adding/removing criteria and modifiers no longer duplicates rows; criteria groups preserve OR/AND semantics, support double-digit group/modifier indices, and reopen in the same logical order in which they were created.
- **Smart Blocks validation:** relative date/range controls, minute limits, track-type modifier rows, and duplicated smart blocks now keep their saved state consistently.
- **Git tag ref:** **`0.1.16-trixie`**. Release titles may use **v0.1.16-trixie**.

---

### 2026-04-28 — **v0.1.15-trixie** (patch)

- Bumped fork semver to **0.1.15** in **`VERSION`** and all component **`setup.py`** files.
- **Station podcast / Apple Podcasts readiness:** the **My Podcast** editor now includes Apple-focused fields for author, owner name/email, podcast type, category/subcategory, explicit metadata, dedicated podcast artwork upload/removal, and a non-blocking readiness panel.
- **Podcast RSS output:** station feeds now emit proper `itunes`, `atom`, and `content` namespaces, `atom:link rel="self"`, normalized Apple explicit values, `itunes:type`, `itunes:owner`, `itunes:image`, nested Apple categories, stable episode GUID/enclosure data, and episode-level Apple defaults.
- **Podcast artwork:** a public artwork endpoint serves dedicated podcast cover art with the correct MIME type and falls back to the station logo when no dedicated image is uploaded.
- **Listener analytics:** listener statistics handling now records and displays stream status more clearly, with UI status rows for enabled/disabled collection states.
- **Git tag ref:** **`0.1.15-trixie`**. Release titles may use **v0.1.15-trixie**.

---

### 2026-04-26 — **v0.1.14-trixie** (patch)

- Bumped fork semver to **0.1.14** in **`VERSION`** and all component **`setup.py`** files.
- **Playout bootstrap reliability:** Liquidsoap Telnet queue pushes now retry cleanly after transient connection failures during service startup, avoiding a silent first scheduled item when Liquidsoap is active but its command socket is still settling.
- **Liquidsoap Telnet client:** shared command connections are serialized with a re-entrant lock, broken sockets close without cascading cleanup errors, and lost push replies are handled without duplicating queue requests.
- **Restart behavior:** repeated `libretime.target` stop/start, restart, and full server reboot checks confirmed schedule activation, Icecast metadata, and audible output recover automatically after startup.
- **Git tag ref:** **`0.1.14-trixie`**. Release titles may use **v0.1.14-trixie**.

---

### 2026-04-26 — **v0.1.13-trixie** (patch)

- Bumped fork semver to **0.1.13** in **`VERSION`** and all component **`setup.py`** files.
- **Legacy asset checksums:** regenerated `legacy/application/assets.json` for `legacy/public/css/styles.css` so cache-busted CSS URLs match the shipped stylesheet.
- **`playout/libretime_playout/liquidsoap/templates/outputs.liq.j2`:** each generated Icecast output now gets a small per-mount Liquidsoap buffer before encoding; the buffer accepts transient fallibility and is followed by `mksafe(...)` so the Icecast output remains stable.
- **`installer/icecast/icecast.xml`:** listener startup burst is re-enabled with a conservative **`burst-size=32768`** default (about 2 seconds at 128 kbps), improving startup without returning to larger 64k reconnect bursts.
- **`installer/config.yml`:** documents `audio.channels` explicitly for stream outputs.
- **Scheduled web streams:** prebuffer events are keyed at the actual prebuffer time, and playout waits briefly for the HTTP source to report `connected` before switching it on air. The Liquidsoap route can let an already armed web stream take over when the aggregate scheduled queue runs dry just before the output-start event, avoiding a brief offline fallback. Removal keeps the stable route-disable/id-reset behavior because forcing `input.http.stop()` is not safe on the tested Liquidsoap 2.3 build.
- **Scheduled item removal:** playout now fast-paths `remove_items` schedule updates so removing the currently playing item can cut to the next valid scheduled item without waiting for the full cache cleanup cycle.
- **Git tag ref:** **`0.1.13-trixie`**. Release titles may use **v0.1.13-trixie**.

---

### 2026-04-24 — **v0.1.11-trixie** (patch)

- Bumped fork semver to **0.1.11** in **`VERSION`** and all component **`setup.py`** files.
- **Premium / public radio embed:** mobile “now playing” styles show artist and track title on separate lines (no single-line truncation); `legacy/public/css/radio-page/premium_player.css` and legacy `assets.json` checksums updated for cache-safe delivery.
- **Git tag ref:** **`0.1.11-trixie`**. Release titles may use **v0.1.11-trixie**.

---

### 2026-04-23 — **v0.1.10-trixie** (version bump)

- Bumped fork semver to **0.1.10** in **`VERSION`** and all component **`setup.py`** files.
- **Git tag ref:** **`0.1.10-trixie`**. Release titles may use **v0.1.10-trixie**.

---

### 2026-04-22 — **v0.1.9-trixie** (version + documentation)

- Bumped fork semver to **0.1.9** in **`VERSION`** and all component **`setup.py`** files.
- Removed root **`CHANGELOG.md`**; release notes live in this file and on GitHub Releases.
- **Git tag ref:** **`0.1.9-trixie`** (repository tag rules disallow a leading `v` on `refs/tags/…-trixie`). Release titles may still use **v0.1.9-trixie** for readability.
- Consolidated versioning documentation: removed redundant **`VERSIONING.md`**; **Release identity and versioning** above is the single reference; README links here.

---

### 2026-04-22 — Uninstall script aligned with `./install`

- **fail2ban:** removes the same drop-ins the installer may have added (filters, actions, jails, harbor logrotate) before deleting log trees, then reloads fail2ban when active.
- **Nginx:** removes both HTTP site logs and HTTPS proxy logs for LibreTime vhosts.
- **System user:** derives the application user from **`libretime-api.service`** (supports non-default **`--user`**).
- **PostgreSQL / RabbitMQ:** reads database and broker identity from **`config.yml`** before it is removed for **`--remove-data`** / **`--purge-packages`** (no longer assumes fixed default names only).
- **`--purge-packages`:** may purge **fail2ban** after LibreTime jail files are removed (destructive on shared hosts—documented in script help and README).
- **Unchanged by design:** UFW rules and any **`127.0.1.1`** host line added by the installer are not reverted (documented).
- **Bugfix:** service loop variable clash that could interfere with user detection.

---

### 2026-04-22 — Installer robustness (upgrades, retries, helpers)

Applies to **retries**, **upgrades**, and **partial first-install recovery**; a clean first install on a fresh system follows the same successful path as before.

**Wizard**

- Wizard hints reference **`./install --help`** for full non-interactive flags.
- Certbot email and fail2ban defaults respect **`LIBRETIME_CERTBOT_EMAIL`** and **`LIBRETIME_SETUP_FAIL2BAN`** when set (including via **`.env`**).
- Fail2ban copy and effective **`bantime`** aligned with documented policy (**1800** s).

**Shell behavior**

- **`set -o pipefail`** with **`set -eu`**; stable **`cd` to script directory**; **`python3`** installed before **`tools/packages.py`** runs.

**Upgrade path** (existing **`config.yml`**)

- Rehydrates **`public_url`** from disk when not passed on the CLI.
- **Hard errors** on **`public_url`** or **`--user`** mismatch versus the live config (prevents silent drift).
- Pre-upgrade summary of layout; optional short TTY countdown; HTTPS summary distinguishes proxy-only from Let’s Encrypt cert present.

**Credentials on retry**

- PostgreSQL and RabbitMQ passwords are reset on retry when roles/users already exist so **`config.yml`** stays consistent with the stack.

**Post-upgrade**

- Restarts active LibreTime units so new code is loaded without a manual step.

**Helper scripts**

- **`tools/packages.py`:** deterministic package ordering; tolerant parsing; warns on invalid **`--exclude`** sections.
- **`installer/icecast/patch_xml_ssl.py`:** idempotent Icecast TLS patch when **`bundle.pem`** is already configured.
- **`installer/letsencrypt/renew-icecast-bundle.sh`:** safe no-op when Icecast is gone; syslog trail for renewal hook outcomes.

**Version at this milestone:** **`0.1.8 trixie`** before the **0.1.9** bump above.

---

### 2026-04-22 — Optional fail2ban integration (LibreTime-oriented jails)

- **Opt-in** via **`LIBRETIME_SETUP_FAIL2BAN`**, **`--setup-fail2ban` / `--no-setup-fail2ban`**, or wizard prompt. Default: **disabled**; installs without the flag do not touch fail2ban.
- **Three jails:** Harbor (Liquidsoap auth), Icecast **`/admin`** HTTP 401, Nginx LibreTime **`/login`**. Policy aligned with common **`sshd`** timing (**maxretry**, **findtime**, **bantime**).
- **Harbor:** dedicated log file written from Liquidsoap for reliable pattern matching on Trixie; filter uses file backend.
- **Nginx:** jail port list substituted at install time for HTTP vs HTTPS proxy modes so bans target the real listener ports.
- **Icecast:** filters admin authentication failures from the Icecast access log.
- **Conntrack flush action:** optional companion action closes long-lived TCP sessions on ban for the web and Icecast jails so bans take effect immediately for keep-alive clients; scoped to each jail’s ports.
- **Logrotate** for the Harbor auth log.

---

### 2026-04-21 — Pre-login player: faster bottom-bar controls

- Embed signals the parent when the iframe DOM is ready; bottom actions (schedule / about / podcast) wire up without waiting for full iframe **`load`**, which could be delayed by slow subresources.

**Version:** **`0.1.7 trixie`**.

---

### 2026-04-21 — Icecast: reduce connect burst for unstable clients

- **`installer/icecast/icecast.xml`:** **`burst-on-connect=0`** and **`burst-size=0`** by default to reduce repeated startup audio slices when clients reconnect often (e.g. mobile paths).

**Version:** **`0.1.6 trixie`**.

---

### 2026-04-21 — Liquidsoap 2.3 cleanup and web-stream stability

- **Deprecation-free** LS 2.3 syntax in templates and **`ls_script.liq`** / **`ls_lib.liq`** (**settings** assignments, **`stereo`**, **`metadata.map`**, **`json.stringify`**).
- **Dummy HTTP input** starts idle (**`start=false`**) so the journal is not flooded when no web stream is armed.
- **`input.http_restart`** skips redundant stop/start when the same URL is already streaming—avoids PCM glitches and Icecast disconnects during schedule refreshes.

**Version:** **`0.1.5 trixie`**.

---

### 2026-04-20 — Web-stream handoff and schedule edits

- Automation source selection stays on the queue path while web-stream handoff state is active; clearer state transitions for web stream IDs.
- When the playing schedule row is removed or changed, playout can force-cut and re-sync the queue so on-air audio matches the schedule.

---

### 2026-04-18 — Install wizard: HTTP public URL normalization

- Corrects common **`http:`** typos to valid **`http://`** URLs.
- For plain **`http://`** URLs without an explicit port, appends the app listen port so browser origin behavior matches Nginx.

---

### 2026-04-16 — Public radio page (responsive player and schedule widget)

- Player toolbar and “now playing” layout: wrapping, spacing, mobile strip; schedule button alignment; schedule tabs default to the **current calendar day** in the station timezone.
- **`radio-page.css`:** overflow and centered tab layout; weekly embed uses station timezone helpers.
- Podcast block and weekly widget styles: fluid layout and **`max-width`** constraints.

---

### 2026-04 — PLC dashboard and playout telemetry

- **Header PLC strip:** six-lamp synoptic (**LNK, FLW, AUD, ICE** plus logic indicators) with **State / Detail** text and severity coloring.
- **Backend:** **`PipelineMonitor`** aggregates playout/Icecast/schedule signals and posts JSON to **`/api/playout-state`** for the dashboard; related probe and Liquidsoap client adjustments.
- Assets refreshed for cache-busting after CSS/JS changes.

---

### 2026-04 — Harbor live inputs (master / show)

- Switching between automation and live **Harbor** sources (**main** / **show**) exercised for typical mounts and **`switch_source`** flows; transitions return to automation without playout errors in test scenarios.

---

## Standing fork highlights (see also README “Changes in this fork”)

- **Station podcast:** publish-from-tracks workflow, episode metadata, **My Podcast** navigation and DataTables behavior.
- **Public radio page:** configurable background image and fit in General Settings; homepage rendering with overlay.
- **Localization:** login locale persistence; PHP 8.4 gettext bootstrap; string updates across **`en_US`**, **`en_GB`**, **`it_IT`**, **`fr_FR`**, **`es_ES`**, **`pt_BR`**.
- **First scheduled track:** **`schedule_streaming`** after the queue is seeded so replay-gain metadata exists for the first item.
- **Live vs API:** database source state updated before **`switch_source`** messaging so **`GET /api/v2/stream/state`** matches playout.
- **Liquidsoap 2.3:** harbor inputs not gated solely on **`source.is_ready`** when PCM is valid.
- **Calendar / autoplaylist:** week overlap and fill behavior aligned with upstream discussions [#3235](https://github.com/libretime/libretime/issues/3235), [#3226](https://github.com/libretime/libretime/issues/3226).
- **PHP / Python / JS / installer:** compatibility and cleanup as summarized in the root README.

---

*Last updated: 2026-04-26.*
