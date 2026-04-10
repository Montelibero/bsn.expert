#!/bin/sh

set -eu

cd /var/www/html || exit 1

sh /var/www/html/init.sh

mkdir -p /etc/crontabs
cp /var/www/html/cron/root /etc/crontabs/root

echo "Starting cron daemon..."
exec crond -f -l 2 -L /dev/stdout
