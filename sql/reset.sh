#!/bin/bash
# Reset the local development database to a clean state.
# Usage: bash sql/reset.sh
#
# Reads DB_USER / DB_PASS / DB_NAME from .env if present; otherwise uses defaults.

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="$SCRIPT_DIR/../.env"

DB_USER="root"
DB_PASS=""
DB_NAME="student_database"

if [ -f "$ENV_FILE" ]; then
    while IFS='=' read -r key value; do
        [[ "$key" =~ ^#.*$ || -z "$key" ]] && continue
        key="${key%%[[:space:]]*}"
        value="${value%%[[:space:]]*}"
        case "$key" in
            DB_USER) DB_USER="$value" ;;
            DB_PASS) DB_PASS="$value" ;;
            DB_NAME) DB_NAME="$value" ;;
        esac
    done < "$ENV_FILE"
fi

if [ -n "$DB_PASS" ]; then
    MYSQL="mysql -u$DB_USER -p$DB_PASS"
else
    MYSQL="mysql -u$DB_USER"
fi

echo "Resetting $DB_NAME..."
$MYSQL -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || { echo "Failed to reset database."; exit 1; }

echo "Applying schema..."
$MYSQL "$DB_NAME" < "$SCRIPT_DIR/schema.sql" || { echo "Failed to apply schema."; exit 1; }

echo ""
echo "Done. Create your first admin account (username = email, per app convention):"
HASH=$(php -r "echo password_hash('admin', PASSWORD_DEFAULT);")
echo "  $MYSQL $DB_NAME -e \"INSERT INTO admins (username, password_hash, email) VALUES ('admin@example.com', '\$HASH', 'admin@example.com');\""
echo ""
echo "Log in with admin@example.com / admin, then change the password immediately."
echo "Then go to /settings to set the current school year before adding tasks."
