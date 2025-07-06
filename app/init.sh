#!/bin/sh

cd /var/www/html || exit

echo "Waiting for DB to be available..."

until mariadb -h db -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" -e "SELECT 1" "$MARIADB_DATABASE" > /dev/null 2>&1; do
  echo "Database is unavailable - sleeping"
  sleep 2
done

echo "Checking if DB is empty..."
ROWS=$(mariadb -h db -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$MARIADB_DATABASE';" | tail -n 1)

if [ "$ROWS" -eq 0 ]; then
  echo "Database is empty. Importing schema..."
  mariadb -h db -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" < ./init.sql
else
  echo "Database already has tables. Skipping import."
fi
