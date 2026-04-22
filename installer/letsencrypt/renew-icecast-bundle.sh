#!/bin/sh
# Certbot deploy hook: refresh /etc/icecast2/bundle.pem and restart Icecast.
# Certbot sets RENEWED_LINEAGE to the renewed certificate live directory.
set -e

log() {
  if command -v logger >/dev/null 2>&1; then
    logger -t libretime-icecast-bundle -- "$1"
  fi
}

if [ -z "${RENEWED_LINEAGE:-}" ]; then
  exit 0
fi
if [ ! -f "${RENEWED_LINEAGE}/fullchain.pem" ] || [ ! -f "${RENEWED_LINEAGE}/privkey.pem" ]; then
  log "skipped: missing fullchain.pem or privkey.pem in ${RENEWED_LINEAGE}"
  exit 0
fi

# Skip cleanly if Icecast has been uninstalled but this hook was left behind.
if ! getent group icecast >/dev/null 2>&1; then
  log "skipped: group 'icecast' not found (Icecast uninstalled?); leaving bundle untouched"
  exit 0
fi

tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
cat "${RENEWED_LINEAGE}/fullchain.pem" "${RENEWED_LINEAGE}/privkey.pem" > "$tmp"
install --group=icecast --mode=640 "$tmp" /etc/icecast2/bundle.pem
systemctl try-restart icecast2.service || true
log "refreshed /etc/icecast2/bundle.pem from ${RENEWED_LINEAGE}"
