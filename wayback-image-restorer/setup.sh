#!/bin/bash

set -euo pipefail

echo "Wayback Image Restorer - Setup"
echo "=============================="
echo ""

if ! command -v composer >/dev/null 2>&1; then
    echo "Composer is required but was not found in PATH."
    echo "Install Composer or run: php composer.phar install --no-dev --prefer-dist --optimize-autoloader"
    exit 1
fi

echo "Installing Composer dependencies..."
composer install --no-dev --prefer-dist --optimize-autoloader

echo ""
echo "Setup complete!"
echo ""
echo "Next steps:"
echo "1. Build or upload the plugin with the vendor folder included"
echo "2. Activate the plugin in WordPress"
