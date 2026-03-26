FROM php:8.3-apache
RUN apt-get update && apt-get install -y libsqlite3-dev curl && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_sqlite
RUN a2enmod rewrite headers
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf && \
    sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf && \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf
WORKDIR /var/www/html
COPY . .
RUN chmod +x docker/dev/docker-entrypoint.sh
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=3s CMD curl -f http://localhost/ || exit 1
ENTRYPOINT ["./docker/dev/docker-entrypoint.sh"]
