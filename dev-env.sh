#!/bin/bash
echo "=================================================="
echo "Hope Baptist Church Treasurer App - Dev Environment Check"
echo "Kubuntu 26.04 Diagnostic Report"
echo "Date: $(date)"
echo "=================================================="
echo ""

echo "=== System Info ==="
echo "OS: $(cat /etc/os-release | grep PRETTY_NAME)"
echo "Kernel: $(uname -r)"
echo "Architecture: $(uname -m)"
echo ""

echo "=== Core Development Tools ==="
echo "PHP: $(php --version 2>/dev/null | head -n1 || echo 'Not installed')"
echo "Composer: $(composer --version 2>/dev/null || echo 'Not installed')"
echo "Node.js: $(node --version 2>/dev/null || echo 'Not installed')"
echo "npm: $(npm --version 2>/dev/null || echo 'Not installed')"
echo "Git: $(git --version 2>/dev/null || echo 'Not installed')"
echo ""

echo "=== Docker ==="
echo "Docker: $(docker --version 2>/dev/null || echo 'Not installed')"
echo "Docker Compose: $(docker compose version 2>/dev/null || echo 'Not installed (or old docker-compose)')"
echo ""

echo "=== Laravel (if project exists) ==="
if [ -f "artisan" ]; then
    echo "Laravel Version: $(php artisan --version 2>/dev/null || echo 'artisan found but could not run')"
    echo "Project Path: $(pwd)"
else
    echo "Laravel project not found in current directory"
fi
echo ""

echo "=== Database Tools ==="
echo "MariaDB/MySQL client: $(mysql --version 2>/dev/null || echo 'Not installed')"
echo "PostgreSQL client: $(psql --version 2>/dev/null || echo 'Not installed')"
echo ""

echo "=== Other Useful Tools ==="
echo "VSCodium: $(command -v codium >/dev/null && echo 'Installed' || echo 'Not found in PATH')"
echo "Ollama: $(ollama --version 2>/dev/null || echo 'Not installed or not in PATH')"
echo ""

echo "=================================================="
echo "Copy everything above this line and paste it in your next message to Grok."
echo "=================================================="
