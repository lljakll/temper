#!/bin/bash
echo "=== Temper Permission Fix Script ==="

CURRENT_USER=$(whoami)
#CURRENT_USER=www-data

echo "Current user detected: $CURRENT_USER"
echo "Setting ownership ($CURRENT_USER:www-data)..."

sudo chown -R $CURRENT_USER:www-data .

echo "Setting standard permissions..."
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;

echo "Securing config.php..."
sudo chmod 640 config.php 2>/dev/null || true

echo "Setting storage permissions..."
sudo chmod -R 775 storage/
sudo chown -R www-data:www-data storage/

echo "Making scripts executable..."
sudo chmod +x *.sh

echo "=== Permissions applied successfully! ==="

echo "Reloading Apache..."
if sudo systemctl reload apache2; then
    echo "✅ Apache reloaded successfully!"
else
    echo "❌ Apache reload failed. Check 'sudo systemctl status apache2'"
fi

echo "Directory listing:"
ls -l
