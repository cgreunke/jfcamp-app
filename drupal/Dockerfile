# drupal/Dockerfile
FROM drupal:11-apache

# Systemlibs inkl. bash
RUN apt-get update && apt-get install -y --no-install-recommends \
      bash \
      libpng-dev \
      libjpeg62-turbo-dev \
      libfreetype6-dev \
      libpq-dev \
      curl ca-certificates git unzip postgresql-client \
  && rm -rf /var/lib/apt/lists/*

# PHP-Extensions: gd + pdo_pgsql
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd pdo_pgsql

# Apache auf /opt/drupal/web umbiegen + Rewrite erlauben (deins ist okay)
RUN a2enmod rewrite \
 && sed -ri -e 's!/var/www/html!/opt/drupal/web!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e 's!/var/www/!/opt/drupal/web/!g' /etc/apache2/apache2.conf \
 && printf "<Directory /opt/drupal/web/>\n  AllowOverride All\n  Options FollowSymLinks\n  Require all granted\n</Directory>\n" \
      > /etc/apache2/conf-available/drupal-rewrite.conf \
 && a2enconf drupal-rewrite \
 && printf "ServerName localhost\n" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

WORKDIR /opt/drupal

# Composer bereitstellen (wichtig, sonst kein vendor/bin/drush)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Startscript (PROD mitkopieren; in DEV darfst du es mounten)
COPY scripts/start-drupal.sh /opt/drupal/start-drupal.sh
RUN chmod +x /opt/drupal/start-drupal.sh

EXPOSE 80
CMD ["bash", "/opt/drupal/start-drupal.sh"]

# Apache-Module, die für obige Config nötig/ sinnvoll sind
RUN a2enmod reqtimeout headers rewrite expires

# Tuning-Dateien einspielen
COPY apache/*.conf /etc/apache2/conf-enabled/
COPY php/*.ini /usr/local/etc/php/conf.d/

