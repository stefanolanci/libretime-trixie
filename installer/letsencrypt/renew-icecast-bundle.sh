#!/bin/sh
# Certbot deploy hook: refresh /etc/icecast2/bundle.pem and restart Icecast.
# Certbot sets RENEWED_LINEAGE to the renewed certificate live directory.
set -e
if [ -z "${RENEWED_LINEAGE:-}" ]; then
  exit 0
fi
if [ ! -f "${RENEWED_LINEAGE}/fullchain.pem" ] || [ ! -f "${RENEWED_LINEAGE}/privkey.pem" ]; then
  exit 0
fi
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
cat "${RENEWED_LINEAGE}/fullchain.pem" "${RENEWED_LINEAGE}/privkey.pem" > "$tmp"
install --group=icecast --mode=640 "$tmp" /etc/icecast2/bundle.pem
systemctl try-restart icecast2.service || true
