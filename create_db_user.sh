#!/bin/bash
echo "=== Create MariaDB User ==="

CONFIG_FILE="config.php"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "❌ config.php not found!"
    exit 1
fi

# Extract values from config.php
DB_NAME=$(grep "define('DB_NAME'" $CONFIG_FILE | sed -E "s/.*'([^']+)'.*/\1/")
DB_USER=$(grep "define('DB_USER'" $CONFIG_FILE | sed -E "s/.*'([^']+)'.*/\1/")
DB_PASS=$(grep "define('DB_PASS'" $CONFIG_FILE | sed -E "s/.*'([^']+)'.*/\1/")

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ] || [ -z "$DB_PASS" ]; then
    echo "❌ Could not parse database credentials from config.php"
    exit 1
fi

echo "Using credentials from config.php:"
echo "Database: $DB_NAME"
echo "User: $DB_USER"

read -p "Create user and grant privileges? (y/N): " confirm
if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
    echo "Cancelled."
    exit 0
fi

sudo mysql -e "
CREATE DATABASE IF NOT EXISTS $DB_NAME;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
"

echo "✅ User created and privileges granted successfully!"
