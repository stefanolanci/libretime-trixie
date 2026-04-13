#!/usr/bin/env bash
# Decode ~6s from an Icecast/HTTP mount and print ffmpeg volumedetect lines.
# Prefer ./tools/diagnose-stream-chain.sh first: it checks schedule/Icecast/logs before PCM.
# Usage: ./tools/stream-level-once.sh [URL]
# Example: ./tools/stream-level-once.sh http://127.0.0.1:8000/main
set -euo pipefail
URL="${1:-http://127.0.0.1:8000/main}"
exec timeout 30 ffmpeg -hide_banner -nostats -t 6 -i "$URL" -af volumedetect -f null - 2>&1 | tail -12
