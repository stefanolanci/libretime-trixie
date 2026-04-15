# Registro interventi di sviluppo (LibreTime Trixie)

Cronologia ad alto livello delle modifiche funzionali rispetto al line principale.  
Repository: `https://github.com/stefanolanci/libretime-trixie` — target installazione **Debian 13 (Trixie)**.

---

## 2026-04-16 — Pagina radio pubblica (responsive + player + schedule widget)

- **Tag rollback:** `rollback/pre-radio-ux-2026-04-16` (annotated) sul commit precedente alle modifiche UI embed.
- **`premium_player.css`:** barra in flex; area “now playing” con `min-width:0`, testo a capo (niente `white-space:nowrap` che tagliava titolo/autore); rimosse media query che stringevano `.on_air` al 30–50%; mobile: fascia fissa sopra la barra con testo leggibile.
- **Correzione post-deploy:** `flex_spacer` non deve avere `flex-grow` (lasciava metà barra bianca); `margin-left:auto` su `.schedule_btn`; tab schedule attivo sul **giorno corrente** stazione (`currentDayOfMonth`), non sempre il primo.
- **`radio-page.css`:** `.wrapper` anti-overflow orizzontale; tab/iframe centrati con `min()` + `translateX` invece del margine negativo fisso.
- **`weekly-program.phtml` + `EmbedController`:** viewport embed; fuso stazione per `toLocaleTimeString`; chiave giorno show da **UTC** allineata a `weekDays` PHP.
- **`weekly-schedule-widget.css` / `station-podcast.css`:** tab flex fluidi; blocco jPlayer podcast `max-width:100%`.

---

## 2026-04 — Dashboard PLC e telemetria playout

- **Pannello PLC nell’header** (`legacy/…/header.phtml`, `styles.css`, `dashboard.js`): sinottico a sei bit con etichette **PLC REAL** / **PLC LOGIC**; lampade **LNK, FLW, AUD, ICE** (catena reale) e **FET, PLAY** (logica) con griglia allineata; testi di stato **State / Detail** e riga anomalie.
- **Severità colore** sulla riga stato (e anomalie quando presenti): verde = nominale (`111111`), giallo = anomalia non bloccante, rosso = condizione critica o dati stale; spaziatura tra matrice lampade e prima riga di testo.
- **Backend playout**: `PipelineMonitor` in `playout/…/pipeline.py` (thread) aggrega segnali (probe livello/link/flow, Icecast JSON, schedule, `.now_playing_sid`) e invia JSON a **`/api/playout-state`** per l’UI; `stream_level_probe.py`, `main.py`, adeguamenti `liquidsoap.py` / `_client.py` per coerenza con la catena audio.
- **Asset**: `legacy/application/assets.json` aggiornato dopo modifiche a CSS/JS (checksum cache browser).

---

## 2026-04 — Harbor live / Master & Show (contesto fork)

- Commutazioni **main** (porta tipica **8001**, mount `/main`) e **show** (**8002**, `/show`) verificate su VPS di test: log `harbor:input_main` / `harbor:input_show`, `switch_source` master_dj / live_dj, transizioni Liquidsoap e ritorno a scaletta senza errori di servizio rilevanti nei journal.

---

## Note operative

- **Deploy di test** (working tree → VPS senza GitHub): script locale `tools/deploy-test-vps.ps1` (non incluso nel clone pubblico minimo; vedi `.gitignore`).
- **Allineamento Git sul server** dopo `git push`: nella directory clone sul VPS (`/root/libretime-trixie`), `git fetch` + `git reset --hard origin/main` (o `git pull --ff-only`) per specchiare il commit pubblicato.

---

*Ultimo aggiornamento registro: 2026-04-16.*
