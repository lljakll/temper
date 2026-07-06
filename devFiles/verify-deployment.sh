#!/bin/bash
set -euo pipefail

APP="/var/www/temper/app"
STORAGE="/var/www/temper/storage"

echo "=== Path checks ==="
[ -d "$APP" ] && echo "OK  app dir exists: $APP" || echo "FAIL app dir missing"
[ -d "$STORAGE/backups" ] && echo "OK  storage/backups exists" || echo "FAIL storage/backups missing"
[ -d "$APP/.git" ] || [ -d "/var/www/temper/.git" ] && echo "OK  git repo present" || echo "WARN .git not at expected location"

echo ""
echo "=== Apache ==="
apache2ctl -S 2>&1 | grep -i temper || true
curl -s -o /dev/null -w "login.php HTTP %{http_code}\n" http://127.0.0.1/login.php
curl -s -o /dev/null -w "index.php  HTTP %{http_code}\n" http://127.0.0.1/index.php

echo ""
echo "=== Storage (via PHP) ==="
php -r "
require '$APP/config.php';
\$d = getStorageDiagnostics();
echo 'active_root: ' . \$d['active_root'] . PHP_EOL;
echo 'is_configured: ' . (\$d['is_configured'] ? 'yes' : 'no') . PHP_EOL;
echo 'backup_dir: ' . \$d['backup_dir'] . PHP_EOL;
"

echo ""
echo "=== Write probe as current user ==="
probe="${STORAGE}/backups/_verify_$(date +%s).txt"
if echo test > "${probe}" 2>/dev/null; then
    echo "OK  write probe succeeded"
    rm -f "${probe}"
else
    echo "WARN write probe failed for $(whoami) (www-data may still work via Apache)"
fi

echo ""
echo "=== Git ==="
git -C /var/www/temper status -sb | head -3