#!/bin/sh

set -eu

cd /var/www/html || exit 1

sh /var/www/html/init.sh

# Debian cron starts jobs with a minimal environment and drops Docker env_file
# values. Persist the container environment in shell-safe form for every job.
export -p > /var/run/bsn-cron-env.sh
chmod 600 /var/run/bsn-cron-env.sh

echo "Starting cron daemon..."
if command -v crond >/dev/null 2>&1; then
    mkdir -p /etc/crontabs
    cp /var/www/html/cron/root /etc/crontabs/root
    exec crond -f -l 2 -L /dev/stdout
fi

crontab /var/www/html/cron/root
exec cron -f
