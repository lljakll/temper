#!/bin/bash

DB_USER="temper_user"
DB_PASS="temper_password"
DB_NAME="temper_db"
LIST_FILE="patches"

if [ ! -f "$LIST_FILE" ]; then
  echo "Error: $LIST_FILE not found"
  exit 1
fi

while IFS= read -r f || [ -n "$f" ]; do
  # Skip empty lines and comments
  [[ -z "$f" || "$f" =~ ^# ]] && continue

  echo "=== Running $f ==="
  mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$f"
  if [ $? -ne 0 ]; then
    echo "ERROR: Failed on $f"
    exit 1
  fi
done < "$LIST_FILE"

echo "All patches completed successfully."
