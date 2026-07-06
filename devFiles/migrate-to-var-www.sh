#!/bin/bash
set -euo pipefail

SRC="/home/jak/git_repos/temper"
DEST="/var/www/temper"
STORAGE_BACKUP="/tmp/temper-storage-backup-$$"
APACHE_SITE="/etc/apache2/sites-available/treasurer.conf"
OLD_SYMLINK="/var/www/html/temper"

echo "==> Stopping Apache..."
systemctl stop apache2

echo "==> Preserving existing ${DEST}/storage (backups, exports, logs)..."
if [ -d "${DEST}/storage" ]; then
    mv "${DEST}/storage" "${STORAGE_BACKUP}"
fi

echo "==> Moving repository ${SRC} -> ${DEST}..."
if [ ! -d "${SRC}" ]; then
    echo "Source ${SRC} not found. Aborting."
    exit 1
fi

if [ -d "${DEST}" ]; then
    remaining="$(ls -A "${DEST}" 2>/dev/null || true)"
    if [ -n "${remaining}" ]; then
        echo "Destination ${DEST} is not empty after storage backup: ${remaining}"
        echo "Aborting to avoid overwriting unexpected files."
        exit 1
    fi
    rmdir "${DEST}"
fi

mv "${SRC}" "${DEST}"

echo "==> Restoring preserved storage at ${DEST}/storage..."
if [ -d "${STORAGE_BACKUP}" ]; then
    if [ -d "${DEST}/storage" ]; then
        echo "    Merging app/storage into preserved storage..."
        for sub in backups exports logs; do
            mkdir -p "${DEST}/storage/${sub}"
            if [ -d "${DEST}/app/storage/${sub}" ]; then
                cp -an "${DEST}/app/storage/${sub}/." "${DEST}/storage/${sub}/" 2>/dev/null || true
            fi
        done
        rm -rf "${DEST}/app/storage"
    fi
    mv "${STORAGE_BACKUP}" "${DEST}/storage"
fi

echo "==> Setting ownership (www-data for app, jak for .git)..."
chown -R www-data:www-data "${DEST}"
chown -R jak:jak "${DEST}/.git"
chown -R jak:www-data "${DEST}/app"
chmod -R g+w "${DEST}/app"
find "${DEST}/app" -type d -exec chmod g+s {} \;
chown -R www-data:www-data "${DEST}/storage"
chmod -R 775 "${DEST}/storage"

echo "==> Adding jak to www-data group for development..."
usermod -aG www-data jak || true

echo "==> Updating Apache DocumentRoot..."
cat > "${APACHE_SITE}" <<'EOF'
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /var/www/temper/app

    <Directory /var/www/temper/app>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/treasurer_error.log
    CustomLog ${APACHE_LOG_DIR}/treasurer_access.log combined
</VirtualHost>
EOF

echo "==> Removing old symlink ${OLD_SYMLINK}..."
if [ -L "${OLD_SYMLINK}" ] || [ -e "${OLD_SYMLINK}" ]; then
    rm -f "${OLD_SYMLINK}"
fi

# Optional compatibility symlink
ln -sfn /var/www/temper/app "${OLD_SYMLINK}"

echo "==> Validating Apache config..."
apache2ctl configtest

echo "==> Starting Apache..."
systemctl start apache2

echo "==> Migration complete."
echo "    App root:  ${DEST}/app"
echo "    Storage:   ${DEST}/storage"
echo "    Git repo:  ${DEST}"
echo ""
echo "Re-open your IDE workspace at: ${DEST}/app"
echo "Run: newgrp www-data   (or log out/in) so jak picks up the www-data group."