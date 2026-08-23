#!/usr/bin/env bash
# XaNMEA installer (Debian/Ubuntu/Raspberry Pi OS)
# Usage: sudo ./install.sh
set -euo pipefail

PREFIX=/usr/local
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==> XaNMEA install from $SRC_DIR"

[ "$(id -u)" -eq 0 ] || { echo "ERROR: run as root (sudo ./install.sh)"; exit 1; }

# --- prerequisites
need_php_ext() {
  php -m | grep -qi "^$1$" || { echo "ERROR: PHP extension '$1' missing"; exit 1; }
}
command -v php >/dev/null || { echo "ERROR: PHP CLI not installed (apt install php-cli)"; exit 1; }
need_php_ext sockets
need_php_ext pcntl

# Serial config relies on stty; uutils coreutils (Ubuntu 25.10) ships an
# stty that cannot set baud rates. The daemon falls back to busybox stty,
# so warn early if neither a capable stty nor busybox is present.
if stty --version 2>/dev/null | grep -qi uutils && ! command -v busybox >/dev/null; then
  echo "==> WARNING: uutils stty detected and no busybox found."
  echo "    Serial inputs need: apt install busybox-static"
fi

# --- user/group
if ! id xanmea >/dev/null 2>&1; then
  useradd --system --no-create-home --shell /usr/sbin/nologin xanmea
  echo "==> created user 'xanmea'"
fi
usermod -aG dialout xanmea

# --- daemon
mkdir -p "$PREFIX/lib/xanmea"
rm -rf "$PREFIX/lib/xanmea/src"
cp -r "$SRC_DIR/src" "$PREFIX/lib/xanmea/src"
install -m 0755 "$SRC_DIR/bin/xanmead" "$PREFIX/bin/xanmead"

# --- config + state dirs
mkdir -p /etc/xanmea /var/lib/xanmea /run/xanmea
if [ ! -f /etc/xanmea/config.json ]; then
  cp "$SRC_DIR/config/config.example.json" /etc/xanmea/config.json
  echo "==> installed example config to /etc/xanmea/config.json"
else
  echo "==> keeping existing /etc/xanmea/config.json"
fi
chown -R xanmea:xanmea /etc/xanmea /var/lib/xanmea /run/xanmea
chmod 2775 /etc/xanmea   # group-write + setgid: UI (www-data) saves must keep group xanmea so the daemon can read config.json
chmod 0660 /etc/xanmea/config.json

# --- web UI
UI_DIR=/var/www/xanmea
mkdir -p "$UI_DIR"
cp -r "$SRC_DIR/ui/"* "$UI_DIR/"
# www-data must reach the control socket: put it in the xanmea group
if id www-data >/dev/null 2>&1; then
  usermod -aG xanmea www-data
else
  echo "==> NOTE: no www-data user found. If you install Apache/nginx+PHP later, run:"
  echo "      usermod -aG xanmea www-data && systemctl restart apache2   (or php*-fpm)"
fi
echo "==> UI installed to $UI_DIR (point your web server docroot there, or: php -S 0.0.0.0:8080 -t $UI_DIR $UI_DIR/router.php)"

# Ubuntu's hardened apache2 unit mounts /etc read-only inside Apache's
# namespace (ProtectSystem=full). Without a carve-out every UI config
# save fails with "Read-only file system" even though the filesystem
# permissions are correct. Debian's unit is unhardened -> no-op there.
if systemctl cat apache2.service 2>/dev/null | grep -q '^ProtectSystem='; then
  mkdir -p /etc/systemd/system/apache2.service.d
  printf '[Service]\nReadWritePaths=/etc/xanmea\n' > /etc/systemd/system/apache2.service.d/xanmea.conf
  echo "==> apache2 has ProtectSystem hardening: added drop-in ReadWritePaths=/etc/xanmea"
  echo "    (takes effect after: systemctl daemon-reload && systemctl restart apache2)"
fi

# --- systemd
cp "$SRC_DIR/systemd/xanmead.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable xanmead
# On reinstall/upgrade the running daemon still has the old code: bounce it.
if systemctl is-active --quiet xanmead; then
  systemctl restart xanmead
  echo "==> restarted xanmead with the new code"
fi
echo ""
echo "Done. Next steps:"
echo "  1. systemctl start xanmead   (already running if this was an upgrade)"
echo "  2. Browse the UI (create the admin password on first visit)"
echo "  3. Test rig: php $SRC_DIR/tools/simulator.php 4010 &"
