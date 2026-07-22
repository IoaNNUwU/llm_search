#!/bin/sh
set -e
mkdir -p /var/www/projects /var/www/projects/_logs
chown -R www-data:www-data /var/www/projects || true
chmod -R ug+rwX /var/www/projects || true

# Resume evaluations interrupted by container stop/restart.
php /var/www/html/worker/resume.php >> /var/www/projects/_logs/resume.log 2>&1 &

# Keep one global enrichment worker alive. PostgreSQL advisory lock prevents duplicates.
(while true; do
    php /var/www/html/worker/enrich.php
    sleep 5
done) >> /var/www/projects/_logs/enrichment.log 2>&1 &

exec docker-php-entrypoint apache2-foreground
