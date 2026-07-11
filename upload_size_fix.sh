#!/bin/bash
echo "=== Temper PHP Upload Size Fix ==="

# Auto-detect PHP version
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null)
PHP_INI="/etc/php/$PHP_VERSION/apache2/php.ini"

if [ ! -f "$PHP_INI" ]; then
    echo "❌ Could not find php.ini for PHP $PHP_VERSION"
    exit 1
fi

while true; do
    echo "Detected PHP version: $PHP_VERSION"
    echo "✅ Found php.ini: $PHP_INI"
    echo "Current settings:"
    echo "----------------"

    memory=$(grep '^memory_limit' $PHP_INI | awk '{print $3}')
    post=$(grep '^post_max_size' $PHP_INI | awk '{print $3}')
    upload=$(grep '^upload_max_filesize' $PHP_INI | awk '{print $3}')

    echo "a) memory_limit = ${memory} (Recommended: 256M for safety)"
    echo "b) post_max_size = ${post} (Recommended: 8M or higher than upload_max_filesize)"
    echo "c) upload_max_filesize = ${upload} (Recommended: 5M, max 20M)"
    echo ""
    echo "d) All of the above"
    echo "r) Refresh current values"
    echo "q) Quit"
    echo ""

    read -p "Which setting to update? (a/b/c/d/r/q): " choice

    case $choice in
        a)
            read -p "New memory_limit in MB (default 256): " newval
            newval=${newval:-256}
            sudo sed -i "s/^memory_limit = .*/memory_limit = ${newval}M/" $PHP_INI
            ;;
        b)
            read -p "New post_max_size in MB (default 8): " newval
            newval=${newval:-8}
            sudo sed -i "s/^post_max_size = .*/post_max_size = ${newval}M/" $PHP_INI
            ;;
        c)
            read -p "New upload_max_filesize in MB (default 5): " newval
            newval=${newval:-5}
            if (( newval > 20 )); then
                read -p "⚠️  Large value ($newval MB). Confirm? (y/N): " sure
                [[ ! "$sure" =~ ^[Yy]$ ]] && continue
            fi
            sudo sed -i "s/^upload_max_filesize = .*/upload_max_filesize = ${newval}M/" $PHP_INI
            ;;
        d)
            echo "Updating all with recommended values..."
            sudo sed -i 's/^memory_limit = .*/memory_limit = 256M/' $PHP_INI
            sudo sed -i 's/^post_max_size = .*/post_max_size = 8M/' $PHP_INI
            sudo sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 5M/' $PHP_INI
            ;;
        r)
            echo "Refreshing..."
            continue
            ;;
        q)
            echo "Exiting..."
            break
            ;;
        *)
            echo "Invalid choice."
            continue
            ;;
    esac

    echo "Restarting Apache..."
    sudo systemctl restart apache2

    echo "Updated settings:"
    grep -E '^memory_limit|^post_max_size|^upload_max_filesize' $PHP_INI
    echo ""
done

echo "=== Script completed! ==="
