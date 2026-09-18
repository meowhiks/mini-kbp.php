FROM php:8.3-apache-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libcurl4-openssl-dev \
    libzip-dev \
    unzip \
    certbot \
    python3-certbot-apache \
    && docker-php-ext-install curl opcache \
    && a2enmod rewrite ssl headers expires \
    && rm -rf /var/lib/apt/lists/*

# App lives in /var/www/app; DocumentRoot = public/
ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache/default-ssl.conf /etc/apache2/sites-available/default-ssl.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && a2ensite default-ssl \
    && mkdir -p /var/www/certbot /etc/letsencrypt

WORKDIR /var/www/app
COPY . /var/www/app
RUN chown -R www-data:www-data /var/www/app/cache \
    && chmod -R 775 /var/www/app/cache

EXPOSE 80 443
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
