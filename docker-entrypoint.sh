#!/bin/sh
set -e
mkdir -p /var/www/projects /var/www/projects/_logs
chown -R www-data:www-data /var/www/projects || true
chmod -R ug+rwX /var/www/projects || true
exec docker-php-entrypoint apache2-foreground
