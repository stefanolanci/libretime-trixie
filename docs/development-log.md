# Development log (LibreTime Trixie fork)

High-level chronological notes for meaningful changes versus upstream LibreTime.  
Repository: `https://github.com/stefanolanci/libretime-trixie` — install target **Debian 13 (Trixie)**.

**Maintainers:** update this file when you merge user-facing, API, playout, or installer-impacting work so it stays a faithful diary of the fork (English only).

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

*Last log update: 2026-04-21 (Icecast burst disabled for listener stability, version 0.1.6).*
