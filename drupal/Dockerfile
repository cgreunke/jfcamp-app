# drupal/Dockerfile
FROM drupal:11-apache

# Basis‑Tools + psql-Client (Drush nutzt ihn zum Drop/Install)
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl ca-certificates git unzip \
    postgresql-client \
 && rm -rf /var/lib/apt/lists/*

# Apache auf /opt/drupal/web umbiegen
RUN a2enmod rewrite \
 && sed -ri -e 's!/var/www/html!/opt/drupal/web!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e 's!/var/www/!/opt/drupal/web/!g' /etc/apache2/apache2.conf \
 && printf "<Directory /opt/drupal/web/>\n  AllowOverride All\n  Options FollowSymLinks\n  Require all granted\n</Directory>\n" \
    > /etc/apache2/conf-available/drupal-rewrite.conf \
 && a2enconf drupal-rewrite \
 && printf "ServerName localhost\n" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

WORKDIR /opt/drupal

# Slim‑Startscript (Prod wird’s kopieren; in Dev mountest du es)
COPY scripts/start-drupal.sh /opt/drupal/start-drupal.sh
RUN chmod +x /opt/drupal/start-drupal.sh

EXPOSE 80
CMD ["bash", "/opt/drupal/start-drupal.sh"]
