#!/bin/bash
echo "=== Temper Permission Fix Script ==="

# Get current user dynamically
CURRENT_USER=$(whoami)

echo "Current user detected: $CURRENT_USER"
echo "Setting ownership ($CURRENT_USER:www-data)..."

# Set ownership
sudo chown -R $CURRENT_USER:www-data .

# Standard directories and files
echo "Setting standard permissions..."
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;

# Security: config.php should not be world-readable
echo "Securing config.php..."
sudo chmod 640 config.php

# Storage (writable by web server)
echo "Setting storage permissions..."
sudo chmod -R 775 storage/
sudo chown -R www-data:www-data storage/

# Make scripts executable
echo "Making scripts executable..."
sudo chmod +x *.sh

echo "=== Permissions applied successfully! ==="
echo "Running 'sudo systemctl reload apache2' now."
sudo systemctl reload apache2

echo "Listing directory with details"
ls -l
