FROM drupal:11-apache

# Tools + Composer installieren
RUN apt-get update && apt-get install -y git unzip curl && rm -rf /var/lib/apt/lists/* \
  && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Apache: DocumentRoot auf /opt/drupal/web umbiegen
RUN a2enmod rewrite
RUN sed -ri -e 's!/var/www/html!/opt/drupal/web!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e 's!/var/www/!/opt/drupal/web/!g' /etc/apache2/apache2.conf

WORKDIR /opt/drupal
