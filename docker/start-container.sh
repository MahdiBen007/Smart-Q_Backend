#!/bin/sh
set -eu

PORT="${PORT:-8080}"

sed -ri "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

mkdir -p \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

if [ -z "${DB_URL:-}" ] && [ -n "${MYSQL_URL:-}" ]; then
    export DB_URL="${MYSQL_URL}"
fi

if [ -z "${DB_HOST:-}" ] && [ -n "${MYSQLHOST:-}" ]; then
    export DB_HOST="${MYSQLHOST}"
fi

if [ -z "${DB_PORT:-}" ] && [ -n "${MYSQLPORT:-}" ]; then
    export DB_PORT="${MYSQLPORT}"
fi

if [ -z "${DB_DATABASE:-}" ] && [ -n "${MYSQLDATABASE:-}" ]; then
    export DB_DATABASE="${MYSQLDATABASE}"
fi

if [ -z "${DB_USERNAME:-}" ] && [ -n "${MYSQLUSER:-}" ]; then
    export DB_USERNAME="${MYSQLUSER}"
fi

if [ -z "${DB_PASSWORD:-}" ] && [ -n "${MYSQLPASSWORD:-}" ]; then
    export DB_PASSWORD="${MYSQLPASSWORD}"
fi

if [ ! -L /var/www/html/public/storage ]; then
    php artisan storage:link >/dev/null 2>&1 || true
fi

php artisan optimize:clear >/dev/null 2>&1 || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    migration_attempt=1

    while [ "${migration_attempt}" -le 10 ]; do
        if php artisan migrate --force; then
            break
        fi

        if [ "${migration_attempt}" -eq 10 ]; then
            exit 1
        fi

        echo "Database is not ready yet. Retrying migrations in 5 seconds..."
        migration_attempt=$((migration_attempt + 1))
        sleep 5
    done
fi

exec "$@"
