#!/bin/bash
set -euo pipefail
APIK=$(grep '^  api_key:' /etc/libretime/config.yml | head -1 | sed 's/.*:[[:space:]]*//')
code=$(curl -sS -o /tmp/lt-version.json -w "%{http_code}" -H "Authorization: Api-Key ${APIK}" "http://127.0.0.1:8080/api/version?format=json" || true)
echo "http_code=${code}"
head -c 300 /tmp/lt-version.json 2>/dev/null || true
echo
