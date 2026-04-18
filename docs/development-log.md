# Development log (LibreTime Trixie fork)

High-level chronological notes for meaningful changes versus upstream LibreTime.  
Repository: `https://github.com/stefanolanci/libretime-trixie` — install target **Debian 13 (Trixie)**.

**Maintainers:** update this file when you merge user-facing, API, playout, or installer-impacting work so it stays a faithful diary of the fork (English only).

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

## Operational notes

- **Test deploy** (working tree → VPS without GitHub): local script `tools/deploy-test-vps.ps1` (not in the minimal public clone; see `.gitignore`).
- **Git on the server** after `git push`: in the VPS clone (e.g. `/root/libretime-trixie`), `git fetch` + `git reset --hard origin/main` (or `git pull --ff-only`) to match the published commit.

---

*Last log update: 2026-04-18.*
