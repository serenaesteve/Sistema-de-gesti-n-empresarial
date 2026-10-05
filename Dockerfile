# Blush ERP: Apache + PHP 8.3 con las extensiones y la configuración que recomienda la vista
# «Sistema» (equivale a scripts/configurar-servidor.sh). Se usa desde docker-compose.yml.
FROM php:8.3-apache

# pdo_sqlite, mbstring, curl y xml ya vienen en la imagen oficial
RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libpng-dev libzip-dev \
 && docker-php-ext-install pdo_mysql intl gd zip \
 && rm -rf /var/lib/apt/lists/*

# php.ini: límites, zona horaria y errores ocultos (producción)
RUN { \
      echo 'memory_limit = 256M'; \
      echo 'upload_max_filesize = 20M'; \
      echo 'post_max_size = 20M'; \
      echo 'max_execution_time = 120'; \
      echo 'date.timezone = Europe/Madrid'; \
      echo 'display_errors = Off'; \
    } > /usr/local/etc/php/conf.d/blush.ini

# .htaccess activo solo en la carpeta de la aplicación (protege datos/)
RUN printf '<Directory /var/www/html>\n  AllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/blush.conf \
 && a2enconf blush

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html/datos
