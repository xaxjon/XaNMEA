#!/usr/bin/env bash
# XaNMEA installer (Debian/Ubuntu/Raspberry Pi OS)
# Usage: sudo ./install.sh
set -euo pipefail

PREFIX=/usr/local
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==> XaNMEA install from $SRC_DIR"

# --- prerequisites
need_php_ext() {
  php -m | grep -qi "^$1$" || { echo "ERROR: PHP extension '$1' missing"; exit 1; }
}
command -v php >/dev/null || { echo "ERROR: PHP CLI not installed (apt install php-cli)"; exit 1; }
need_php_ext sockets
need_php_ext pcntl

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
fi
echo "==> UI installed to $UI_DIR (point your web server docroot there, or: php -S 0.0.0.0:8080 -t $UI_DIR $UI_DIR/router.php)"

# --- systemd
cp "$SRC_DIR/systemd/xanmead.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable xanmead
echo ""
echo "Done. Next steps:"
echo "  1. Edit /etc/xanmea/config.json (or start the daemon and use the web UI)"
echo "  2. systemctl start xanmead"
echo "  3. Browse the UI (create the admin password on first visit)"
echo "  4. Test rig: php $SRC_DIR/tools/simulator.php 4010 &"
