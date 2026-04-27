#!/bin/bash
# Sets up a minimal TYPO3 project with the MCP server extension for integration testing.
#
# Usage:
#   TYPO3_VERSION='^13.4' DB_HOST=127.0.0.1 DB_NAME=typo3_test ./setup-typo3.sh
#
# Environment variables:
#   TYPO3_PROJECT_PATH  - Where to create the TYPO3 project (default: /tmp/typo3-project)
#   EXTENSION_PATH      - Path to the extension (default: auto-detected from script location)
#   TYPO3_VERSION       - TYPO3 version constraint (default: ^13.4)
#   DB_HOST             - Database host (default: 127.0.0.1)
#   DB_PORT             - Database port (default: 3306)
#   DB_NAME             - Database name (default: typo3_test)
#   DB_USER             - Database user (default: root)
#   DB_PASS             - Database password (default: root)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TYPO3_PROJECT_PATH="${TYPO3_PROJECT_PATH:-/tmp/typo3-project}"
EXTENSION_PATH="${EXTENSION_PATH:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
TYPO3_VERSION="${TYPO3_VERSION:-^13.4}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-typo3_test}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"

echo "=== TYPO3 MCP Server Integration Test Setup ==="
echo "  TYPO3 version:  $TYPO3_VERSION"
echo "  Project path:   $TYPO3_PROJECT_PATH"
echo "  Extension path: $EXTENSION_PATH"
echo "  Database:       $DB_USER@$DB_HOST:$DB_PORT/$DB_NAME"
echo ""

# --- Wait for database ---
echo "Waiting for database..."
for i in $(seq 1 30); do
    if mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" -e "SELECT 1" > /dev/null 2>&1; then
        echo "Database is ready."
        break
    fi
    if [ "$i" -eq 30 ]; then
        echo "ERROR: Database not ready after 30 seconds."
        exit 1
    fi
    sleep 1
done

# --- Create database if needed ---
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" \
    -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# --- Create TYPO3 project ---
echo ""
echo "Creating TYPO3 project..."
rm -rf "$TYPO3_PROJECT_PATH"
mkdir -p "$TYPO3_PROJECT_PATH"
cd "$TYPO3_PROJECT_PATH"

cat > composer.json <<'EOF'
{
    "name": "test/typo3-mcp-integration",
    "description": "Integration test project for TYPO3 MCP Server",
    "type": "project",
    "license": "GPL-2.0-or-later",
    "config": {
        "allow-plugins": {
            "typo3/class-alias-loader": true,
            "typo3/cms-composer-installers": true,
            "php-http/discovery": true
        }
    },
    "extra": {
        "typo3/cms": {
            "web-dir": "public"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
EOF

# Add path repository for the extension
composer config repositories.mcp-server \
    "{\"type\": \"path\", \"url\": \"$EXTENSION_PATH\", \"options\": {\"symlink\": true}}"

# Install TYPO3 core + extension + optional extensions for testing
echo ""
echo "Installing TYPO3 packages..."
composer require \
    "typo3/cms-core:$TYPO3_VERSION" \
    "typo3/cms-backend:$TYPO3_VERSION" \
    "typo3/cms-extbase:$TYPO3_VERSION" \
    "typo3/cms-fluid:$TYPO3_VERSION" \
    "typo3/cms-frontend:$TYPO3_VERSION" \
    "typo3/cms-install:$TYPO3_VERSION" \
    "typo3/cms-filelist:$TYPO3_VERSION" \
    "typo3/cms-redirects:$TYPO3_VERSION" \
    "typo3/cms-scheduler:$TYPO3_VERSION" \
    "marekskopal/typo3-mcp-server:@dev" \
    --no-interaction --no-progress

# Install news extension for dynamic tool testing (may not be available for all TYPO3 versions)
echo ""
echo "Installing news extension..."
composer require "georgringer/news" --no-interaction --no-progress \
    || echo "WARNING: georgringer/news not available for TYPO3 $TYPO3_VERSION, dynamic tool tests will be skipped"

# --- Setup TYPO3 ---
echo ""
echo "Running TYPO3 setup..."
vendor/bin/typo3 setup \
    --driver=mysqli \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --dbname="$DB_NAME" \
    --username="$DB_USER" \
    --password="$DB_PASS" \
    --admin-user-password='Password123!' \
    --project-name='TYPO3 MCP Integration Test' \
    --server-type=other \
    --no-interaction \
    --force

# --- Apply extension database schema ---
echo ""
echo "Updating database schema..."
vendor/bin/typo3 extension:setup || true
vendor/bin/typo3 database:updateschema || true

# --- Import test fixtures ---
echo ""
echo "Importing test fixtures..."
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    < "$EXTENSION_PATH/Tests/Integration/Fixtures/test-data.sql"

# --- Create site configuration ---
echo "Creating site configuration..."
mkdir -p config/sites/main
cp "$EXTENSION_PATH/Tests/Integration/Fixtures/site-config.yaml" config/sites/main/config.yaml

# --- Create fileadmin directory ---
echo "Setting up fileadmin..."
mkdir -p public/fileadmin

# --- Verify setup ---
echo ""
echo "Verifying setup..."
vendor/bin/typo3 list mcp 2>/dev/null && echo "MCP commands available." || echo "WARNING: MCP commands not found."

echo ""
echo "=== Setup complete ==="
echo "TYPO3 project ready at: $TYPO3_PROJECT_PATH"
echo "Run tests with: TYPO3_PATH=$TYPO3_PROJECT_PATH node Tests/Integration/run-tests.mjs"
