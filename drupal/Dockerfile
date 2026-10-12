FROM drupal:11-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      bash \
      libpng-dev \
      libjpeg62-turbo-dev \
      libfreetype6-dev \
      libpq-dev \
      curl \
      ca-certificates \
      git \
      unzip \
      postgresql-client \
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd pdo_pgsql

RUN a2enmod rewrite reqtimeout headers expires \
 && sed -ri -e 's!/var/www/html!/opt/drupal/web!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e 's!/var/www/!/opt/drupal/web/!g' /etc/apache2/apache2.conf \
 && printf "<Directory /opt/drupal/web/>\n  AllowOverride All\n  Options FollowSymLinks\n  Require all granted\n</Directory>\n" \
      > /etc/apache2/conf-available/drupal-rewrite.conf \
 && a2enconf drupal-rewrite \
 && printf "ServerName localhost\n" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/drupal

# Dependencies are part of the immutable image.
COPY composer.json composer.lock ./
RUN COMPOSER_MEMORY_LIMIT=-1 composer install \
      --no-dev \
      --prefer-dist \
      --no-interaction \
      --no-progress \
      --optimize-autoloader

# Application/configuration sources.
COPY config/ config/
COPY scripts/ scripts/
COPY web/modules/custom/ web/modules/custom/
COPY web/sites/default/settings.php web/sites/default/settings.php
COPY web/sites/default/cors.local.yml web/sites/default/cors.local.yml

COPY apache/*.conf /etc/apache2/conf-enabled/
COPY php/*.ini /usr/local/etc/php/conf.d/

RUN chmod +x scripts/*.sh \
 && mkdir -p web/sites/default/files private \
 && chown -R www-data:www-data web/sites/default/files private

EXPOSE 80

CMD ["/opt/drupal/scripts/start-drupal.sh"]
