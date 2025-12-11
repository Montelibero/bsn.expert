#!/bin/sh

cd /var/www/html || exit

echo "Ensuring MongoDB indexes..."
tries=0
until php /var/www/html/mongo-indexes.php; do
  tries=$((tries+1))
  if [ "$tries" -ge 5 ]; then
    echo "Failed to ensure MongoDB indexes after $tries attempts."
    break
  fi
  echo "Retrying in 2 seconds..."
  sleep 2
done
