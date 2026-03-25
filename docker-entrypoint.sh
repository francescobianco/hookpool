#!/bin/bash
set -e
mkdir -p /var/www/html/data/emails
chown -R www-data:www-data /var/www/html/data
chmod 755 /var/www/html/data
php -f /var/www/html/migrate.php
exec apache2-foreground
