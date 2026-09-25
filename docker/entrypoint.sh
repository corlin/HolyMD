#!/bin/sh
# One-command HolyMD demo: migrate, seed example content, publish, then serve.
set -eu

cd /app

echo "Waiting for the database..."
attempt=0
until php bin/holymd-migrate.php; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "Database did not become ready in time." >&2
        exit 1
    fi
    sleep 2
done

mkdir -p content/articles content/pages content/media content/versions
if [ -z "$(ls -A content/articles)" ] && [ -z "$(ls -A content/pages)" ]; then
    echo "Seeding example content..."
    cp -R examples/content/. content/
fi

if ! php bin/holymd-admin.php list | grep -qF "$HOLYMD_DEMO_ADMIN_EMAIL"; then
    HOLYMD_ADMIN_PASSWORD="$HOLYMD_DEMO_ADMIN_PASSWORD" \
        php bin/holymd-admin.php create --email "$HOLYMD_DEMO_ADMIN_EMAIL" --display-name 'Demo administrator'
fi

php bin/holymd-prepare-release.php
php bin/holymd-build.php --rebuild

# Process publish and GEO review jobs queued from the admin.
(while true; do php bin/holymd-worker.php >/dev/null 2>&1 || true; sleep 2; done) &

echo "HolyMD is ready: public site http://localhost:${HOLYMD_DEMO_PORT:-8080}/ , admin http://localhost:${HOLYMD_DEMO_PORT:-8080}/admin/login"
exec php -S 0.0.0.0:8080 -t public bin/holymd-dev-router.php
