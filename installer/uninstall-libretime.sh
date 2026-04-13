#!/usr/bin/env bash
# Remove LibreTime native install (systemd, trees, config). Run as root.
# Also removes Nginx reverse-proxy/Certbot/Icecast TLS artifacts added when
# public_url was https:// during install.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=== Stopping LibreTime services ==="
systemctl stop libretime.target 2>/dev/null || true
for u in libretime-api.socket libretime-api libretime-playout libretime-liquidsoap libretime-analyzer libretime-worker; do
  systemctl stop "$u" 2>/dev/null || true
  systemctl disable "$u" 2>/dev/null || true
done
systemctl disable libretime.target 2>/dev/null || true

# Let's Encrypt hostname (read before removing /etc/libretime)
CERT_NAME=""
if [[ -f /etc/libretime/config.yml ]]; then
  pub_line=$(grep -m1 '^  public_url:' /etc/libretime/config.yml | sed 's/^  public_url:[[:space:]]*//' | tr -d '"' | tr -d "'")
  if [[ "$pub_line" =~ ^https://([^/:?#]+) ]]; then
    CERT_NAME="${BASH_REMATCH[1]}"
    echo "=== Detected HTTPS public_url; Certbot name likely: ${CERT_NAME} ==="
  fi
fi

echo "=== Stopping Icecast (will restore stock config if needed) ==="
systemctl stop icecast2 2>/dev/null || true

echo "=== Removing systemd units ==="
rm -f /usr/lib/systemd/system/libretime-api.service \
      /usr/lib/systemd/system/libretime-api.socket \
      /usr/lib/systemd/system/libretime-playout.service \
      /usr/lib/systemd/system/libretime-liquidsoap.service \
      /usr/lib/systemd/system/libretime-analyzer.service \
      /usr/lib/systemd/system/libretime-worker.service \
      /usr/lib/systemd/system/libretime.target
systemctl daemon-reload

echo "=== Removing CLI symlinks ==="
rm -f /usr/local/bin/libretime-api /usr/local/bin/libretime-liquidsoap \
      /usr/local/bin/libretime-playout /usr/local/bin/libretime-playout-notify \
      /usr/local/bin/libretime-analyzer /usr/local/bin/libretime-worker

echo "=== Removing app trees ==="
rm -rf /opt/libretime
rm -rf /etc/libretime
rm -rf /var/lib/libretime
rm -rf /var/log/libretime
rm -rf /usr/share/libretime
rm -rf /srv/libretime

echo "=== Nginx (LibreTime + HTTPS proxy) / PHP-FPM / logrotate ==="
rm -f /etc/nginx/sites-enabled/libretime.conf
rm -f /etc/nginx/sites-available/libretime.conf
rm -f /etc/nginx/sites-enabled/libretime-https-proxy.conf
rm -f /etc/nginx/sites-available/libretime-https-proxy.conf
rm -f /var/log/nginx/libretime-proxy.access.log /var/log/nginx/libretime-proxy.error.log
rm -f /etc/logrotate.d/libretime-legacy /etc/logrotate.d/libretime-liquidsoap
rm -f /etc/php/*/fpm/pool.d/libretime-legacy.conf
# Certbot may have created a vhost named after the domain (besides libretime-https-proxy.conf).
for d in /etc/nginx/sites-enabled /etc/nginx/sites-available; do
  [[ -d "$d" ]] || continue
  for f in "$d"/*; do
    [[ -e "$f" ]] || continue
    if grep -q 'letsencrypt/live' "$f" 2>/dev/null; then
      rm -f "$f"
    fi
  done
done
# The installer removes default; nginx -t fails if no server block is enabled.
if [[ ! -e /etc/nginx/sites-enabled/default ]] && [[ -f /etc/nginx/sites-available/default ]]; then
  ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
fi

echo "=== Certbot / Let's Encrypt (hooks, helper scripts, optional cert) ==="
# Orphan hooks/symlinks (e.g. manual tests or old names): anything matching
# *libretime* in renewal-hooks. Does not remove git clone/install directories.
shopt -s nullglob
for hookdir in deploy pre post; do
  d="/etc/letsencrypt/renewal-hooks/${hookdir}"
  [[ -d "$d" ]] || continue
  for f in "$d"/*libretime* "$d"/*LibreTime*; do
    [[ -e "$f" || -L "$f" ]] || continue
    echo "Removing Certbot hook: $f"
    rm -f "$f"
  done
done
for s in /usr/local/sbin/*libretime*; do
  [[ -e "$s" || -L "$s" ]] || continue
  echo "Removing helper script: $s"
  rm -f "$s"
done
shopt -u nullglob
if [[ -n "$CERT_NAME" ]] && command -v certbot >/dev/null 2>&1; then
  if certbot certificates 2>/dev/null | grep -q "Certificate Name: ${CERT_NAME}"; then
    echo "Removing Let's Encrypt certificate '${CERT_NAME}' (certbot delete)..."
    certbot delete --cert-name "$CERT_NAME" --non-interactive 2>/dev/null || true
  fi
fi

echo "=== PostgreSQL ==="
if command -v psql >/dev/null && id postgres &>/dev/null; then
  sudo -u postgres psql -tAc "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'libretime' AND pid <> pg_backend_pid();" 2>/dev/null || true
  sudo -u postgres dropdb --if-exists libretime 2>/dev/null || true
  sudo -u postgres psql -c "DROP ROLE IF EXISTS libretime;" 2>/dev/null || true
fi

echo "=== RabbitMQ ==="
if command -v rabbitmqctl >/dev/null 2>&1; then
  rabbitmqctl delete_user libretime 2>/dev/null || true
  rabbitmqctl delete_vhost /libretime 2>/dev/null || true
fi

echo "=== Icecast TLS leftovers (bundle + restore icecast.xml) ==="
rm -f /etc/icecast2/bundle.pem
if [[ -f "${SCRIPT_DIR}/icecast/icecast.xml" ]]; then
  echo "Restoring Icecast config from ${SCRIPT_DIR}/icecast/icecast.xml"
  cp "${SCRIPT_DIR}/icecast/icecast.xml" /etc/icecast2/icecast.xml
elif dpkg -l icecast2 2>/dev/null | grep -q '^ii'; then
  echo "No installer icecast template beside this script; forcing package default icecast.xml"
  rm -f /etc/icecast2/icecast.xml
  DEBIAN_FRONTEND=noninteractive apt-get -qq -y install --reinstall icecast2 || true
else
  echo "Icecast not installed or no template: skip icecast.xml restore."
fi

echo "=== Reload nginx / php-fpm (stop FPM before userdel so no process holds libretime) ==="
nginx -t 2>/dev/null && systemctl reload nginx 2>/dev/null || true
shopt -s nullglob
for svc in /lib/systemd/system/php*-fpm.service; do
  [[ -f "$svc" ]] || continue
  systemctl stop "$(basename "$svc")" 2>/dev/null || true
done
sleep 1
pkill -u libretime 2>/dev/null || true

echo "=== System user ==="
if id libretime &>/dev/null; then
  userdel libretime 2>/dev/null || true
fi

for svc in /lib/systemd/system/php*-fpm.service; do
  [[ -f "$svc" ]] || continue
  systemctl start "$(basename "$svc")" 2>/dev/null || true
done

echo "=== Start Icecast again (if installed) ==="
systemctl start icecast2 2>/dev/null || true

echo "=== Uninstall complete ==="
