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
echo "Apache: $(apache2 -v 2>/dev/null | head -n1 || echo 'Not installed')"
echo "MariaDB Client: $(mysql --version 2>/dev/null || echo 'Not installed')"
echo "Git: $(git --version 2>/dev/null || echo 'Not installed')"
echo ""

echo "=== Web Stack ==="
echo "PHP Version: $(php --version 2>/dev/null | head -n1 || echo 'Not installed')"
echo "Apache Status: $(systemctl is-active apache2 2>/dev/null || echo 'Not running or not installed')"
echo "MariaDB Status: $(systemctl is-active mariadb 2>/dev/null || echo 'Not running or not installed')"
echo ""

echo "=== Other Useful Tools ==="
#echo "VSCodium: $(command -v codium >/dev/null && echo 'Installed' || echo 'Not found in PATH')"
#echo "Ollama: $(ollama --version 2>/dev/null || echo 'Not installed or not in PATH')"
echo ""

echo "=================================================="
echo "Would you like to install missing core tools? (y/n)"
read -r install_choice

if [[ "$install_choice" == "y" || "$install_choice" == "Y" ]]; then
    echo "Installing missing core tools..."
    sudo apt update
    sudo apt install -y php php-mysql php-curl php-gd php-mbstring apache2 mariadb-server git
    echo "Core tools installed."
    echo "Restarting services..."
    sudo systemctl restart apache2 mariadb
    echo "Services restarted."
fi

#echo "=================================================="
#echo "Copy everything above this line and paste it in your next message to Grok."
#echo "=================================================="
