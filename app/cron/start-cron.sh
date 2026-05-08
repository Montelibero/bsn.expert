#!/bin/sh

set -eu

cd /var/www/html || exit 1

sh /var/www/html/init.sh

echo "Starting cron daemon..."
if command -v crond >/dev/null 2>&1; then
    mkdir -p /etc/crontabs
    cp /var/www/html/cron/root /etc/crontabs/root
    exec crond -f -l 2 -L /dev/stdout
fi

crontab /var/www/html/cron/root
exec cron -f
