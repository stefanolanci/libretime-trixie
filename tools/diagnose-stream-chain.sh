#!/usr/bin/env bash
# Diagnose LibreTime streaming chain before trusting PCM level probes.
# Run on the station host (as root or a user that can journalctl + curl localhost).
#
# Order of checks (fail-fast reasoning):
#   1) Core units up?
#   2) Icecast: is there a source on the mount? Any ICY/title metadata?
#   3) Playout: schedule push / queue intent in recent logs?
#   4) Liquidsoap: decode / switch / Icecast connect lines?
#   5) PCM sample ONLY if step 2 says a source is connected (otherwise it is noise/silence).
#
# Usage:
#   ./tools/diagnose-stream-chain.sh
#   ICAST_URL=http://127.0.0.1:8000 MOUNT_PATH=/main ./tools/diagnose-stream-chain.sh
#
set -euo pipefail

ICAST_URL="${ICAST_URL:-http://127.0.0.1:8000}"
MOUNT_PATH="${MOUNT_PATH:-/main}"
STREAM_URL="${STREAM_URL:-${ICAST_URL}${MOUNT_PATH}}"
JOURNAL_SINCE="${JOURNAL_SINCE:-8 min ago}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

hr() { printf '\n── %s ──\n' "$1"; }

hr "1) systemd (liquidsoap, playout, icecast)"
for u in libretime-liquidsoap libretime-playout icecast2 icecast; do
  if systemctl cat "$u" &>/dev/null; then
    printf '%s: %s\n' "$u" "$(systemctl is-active "$u" 2>/dev/null || echo missing)"
  fi
done

hr "2) Icecast status-json (sources + metadata)"
export ICAST_URL MOUNT_PATH
python3 <<'PY' || true
import json
import os
import sys
import urllib.request

base = os.environ.get("ICAST_URL", "http://127.0.0.1:8000")
mount_path = os.environ.get("MOUNT_PATH", "/main")
try:
    raw = urllib.request.urlopen(base + "/status-json.xsl", timeout=12).read().decode()
except Exception as e:
    print("FAIL: cannot fetch status-json:", e, file=sys.stderr)
    sys.exit(0)

try:
    d = json.loads(raw)
except json.JSONDecodeError as e:
    print("FAIL: bad JSON from status-json:", e, file=sys.stderr)
    sys.exit(0)
ic = d.get("icestats") or {}
src = ic.get("source")
if src is None:
    rows = []
elif isinstance(src, list):
    rows = src
elif isinstance(src, dict):
    rows = [src]
else:
    rows = []

want = mount_path
found = [x for x in rows if isinstance(x, dict) and x.get("mount") == want]
print("json_bytes", len(raw), "mounts_listed", len(rows))
if not rows:
    print("NO_SOURCES: nothing connected to Icecast (UI 'Currently playing' will be empty).")
for x in rows:
    if not isinstance(x, dict) or "mount" not in x:
        continue
    m = x["mount"]
    title = x.get("title")
    artist = x.get("artist")
    song = x.get("song")
    print(
        f"  {m} listeners={x.get('listeners')} "
        f"has_source_block={bool(x.get('source'))} "
        f"title={title!r} artist={artist!r} song={song!r}"
    )

if not found:
    print(f"TARGET_MOUNT {want!r}: not in source list -> do not interpret PCM on stream URL yet.")
elif not (found[0].get("source")):
    print(f"TARGET_MOUNT {want!r}: listed but empty source field -> odd; treat as no encoder.")
else:
    print(f"TARGET_MOUNT {want!r}: source present -> PCM probe is meaningful (if audio path not silent).")
PY

hr "3) HTTP mount (quick, does /main respond?)"
# stderr (e.g. timeout) must not mix with -w or the code line breaks for humans/scripts
code="$(curl -sS -o /dev/null -w "%{http_code}" --max-time 6 "$STREAM_URL" 2>/dev/null || true)"
[[ -z "$code" ]] && code="000"
echo "GET $STREAM_URL -> HTTP $code"
if [[ "$code" != "200" ]]; then
  echo "Skip PCM: mount not HTTP 200 (no point running volumedetect for minutes)."
  SKIP_PCM=1
else
  SKIP_PCM=0
fi

hr "4) libretime-playout journal (schedule / push / probe)"
if systemctl cat libretime-playout &>/dev/null; then
  journalctl -u libretime-playout --no-pager -n 80 --since "$JOURNAL_SINCE" 2>/dev/null \
    | grep -E 'Bootstrap schedule|Need to add items|queue\.|push |stream level probe|apply_liquidsoap_stream_switches|ERROR|WARNING' \
    | tail -n 35 || true
else
  echo "unit libretime-playout not found"
fi

hr "5) libretime-liquidsoap journal (decode / Icecast)"
if systemctl cat libretime-liquidsoap &>/dev/null; then
  journalctl -u libretime-liquidsoap --no-pager -n 80 --since "$JOURNAL_SINCE" 2>/dev/null \
    | grep -E 'Prepared |Connecting mount|Closing connection|icecast:|switch:|decoder\.ffmpeg|ERROR|WARNING' \
    | tail -n 35 || true
else
  echo "unit libretime-liquidsoap not found"
fi

hr "6) PCM sample (only if mount returned HTTP 200)"
if [[ "${SKIP_PCM:-1}" -eq 0 ]]; then
  echo "Running short volumedetect via $ROOT/tools/stream-level-once.sh"
  bash "$ROOT/tools/stream-level-once.sh" "$STREAM_URL" || true
else
  echo "Skipped (see section 2–3). Fix schedule/Liquidsoap/Icecast source first."
fi

hr "Done"
echo "Tip: if playout logs show 'stream level probe: very low' but Icecast has no source,"
echo "     the probe is measuring silence/404 retries — check sections 2–5 first."
