# Second web server for the same checkout: Apache with mod_rewrite and .htaccess, like the
# remote (all-inkl.com). DDEV's own web container stays nginx-fpm.
FROM php:8.4-apache

RUN apt-get update \
  && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
  && docker-php-ext-configure gd --with-jpeg --with-freetype \
  && docker-php-ext-install mysqli gd exif \
  && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite \
  && printf '<Directory /var/www/html>\n  AllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/allow-override.conf \
  && a2enconf allow-override
