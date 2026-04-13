#!/bin/bash
set -euo pipefail
python3 << 'PY'
from pathlib import Path
p = Path("/etc/nginx/sites-available/libretime.conf")
t = p.read_text()
# Remove any broken or duplicate Authorization lines
lines = [ln for ln in t.splitlines(True) if "fastcgi_param HTTP_AUTHORIZATION" not in ln]
t = "".join(lines)
needle = "    fastcgi_param PATH_INFO $path_info;\n    include fastcgi_params;\n\n    fastcgi_index index.php;"
repl = "    fastcgi_param PATH_INFO $path_info;\n    include fastcgi_params;\n    fastcgi_param HTTP_AUTHORIZATION $http_authorization;\n\n    fastcgi_index index.php;"
if needle not in t:
    raise SystemExit("needle not found — check nginx config")
p.write_text(t.replace(needle, repl, 1))
print("patched")
PY
nginx -t
systemctl reload nginx
echo ok
