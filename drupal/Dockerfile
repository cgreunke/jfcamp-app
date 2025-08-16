# drupal/Dockerfile
FROM drupal:11-apache

# ---- Systempakete ----
# curl/git/unzip für Composer; Build-Deps für GD; libpq-dev für PostgreSQL
RUN apt-get update && apt-get install -y \
    git unzip curl \
    libjpeg62-turbo-dev libpng-dev libfreetype6-dev libwebp-dev libavif-dev \
    libpq-dev \
 && rm -rf /var/lib/apt/lists/*

# ---- PHP-Extensions ----
# GD (mit JPEG/PNG/WebP/AVIF), PDO, PostgreSQL, Opcache
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif \
 && docker-php-ext-install -j"$(nproc)" gd pdo pdo_pgsql opcache

# ---- Composer (global) ----
RUN curl -sS https://getcomposer.org/installer \
 | php -- --install-dir=/usr/local/bin --filename=composer

# ---- Apache korrekt konfigurieren ----
# DocumentRoot auf /opt/drupal/web umbiegen, mod_rewrite aktivieren,
# Directory-Rechte erlauben, ServerName warnung abstellen.
RUN a2enmod rewrite \
 && sed -ri -e 's!/var/www/html!/opt/drupal/web!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e 's!/var/www/!/opt/drupal/web/!g' /etc/apache2/apache2.conf

# Directory-Konfiguration für Drupal (AllowOverride für .htaccess)
RUN printf "<Directory /opt/drupal/web/>\n  AllowOverride All\n  Options FollowSymLinks\n  Require all granted\n</Directory>\n" \
  > /etc/apache2/conf-available/drupal-rewrite.conf \
 && a2enconf drupal-rewrite \
 && printf "ServerName localhost\n" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

WORKDIR /opt/drupal

# ---- Start-Script hinzufügen ----
COPY start-drupal.sh /usr/local/bin/start-drupal.sh
RUN chmod +x /usr/local/bin/start-drupal.sh

EXPOSE 80

# Start: Composer-Setup (falls nötig) -> Apache
CMD ["bash", "/usr/local/bin/start-drupal.sh"]
