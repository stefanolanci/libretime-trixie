#!/usr/bin/env bash
# Reinstall Python packages and legacy web tree from this repository into an existing
# LibreTime install (same steps as ./install for those sections). Run as root from
# the repository root after the initial ./install.
#
# Usage: sudo ./tools/align-running-install-from-here.sh
set -euo pipefail

[[ "$(id -u)" -eq 0 ]] || { echo "run as root" >&2; exit 1; }

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VENV_DIR="${VENV_DIR:-/opt/libretime}"
LEGACY_WEB_ROOT="${LEGACY_WEB_ROOT:-/usr/share/libretime/legacy}"
LIBRETIME_USER="${LIBRETIME_USER:-libretime}"
PIP="$VENV_DIR/bin/pip"

cd "$ROOT"
make VERSION
cp VERSION legacy/

"$PIP" install --upgrade "$ROOT/shared"
"$PIP" install --upgrade "$ROOT/api-client"
"$PIP" install --upgrade "$ROOT/api[prod]"
"$PIP" install --upgrade "$ROOT/playout"
"$PIP" install --upgrade "$ROOT/analyzer"
"$PIP" install --upgrade "$ROOT/worker"

make -C legacy build

rm -rf "$LEGACY_WEB_ROOT"
mkdir -p "$LEGACY_WEB_ROOT"
cp -a "$ROOT/legacy/." "$LEGACY_WEB_ROOT/"
chown -R "$LIBRETIME_USER:$LIBRETIME_USER" "$LEGACY_WEB_ROOT"

systemctl daemon-reload
systemctl restart libretime.target
echo "done: Python venv + legacy aligned from $ROOT"
