#!/usr/bin/env bash
# Show key markers in the pip-installed playout Liquidsoap bundle (not the git clone).
set -euo pipefail
SP=$(ls -d /opt/libretime/lib/python3.*/site-packages/libretime_playout/liquidsoap 2>/dev/null | head -1) || true
if [[ -z "${SP}" ]]; then
  echo "libretime_playout/liquidsoap not found under /opt/libretime" >&2
  exit 1
fi
echo "SITE_PKG=$SP"
grep -n "schedule_streaming = ref" "$SP/ls_script.liq" || true
grep -n "transition_schedule_cut" "$SP/ls_script.liq" "$SP/ls_lib.liq" || true
grep -n "pulse_schedule_routing" /opt/libretime/lib/python3.*/site-packages/libretime_playout/main.py | head -5 || true
