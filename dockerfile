# Etapa 1: Composer (baixa dependências do PHP)
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Se seu autoload exige ver os fontes, copie tudo:
# COPY . .
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction

# Etapa 2: PHP + Apache (Debian)
FROM php:8.2-apache

# Instala utilitários e dependências do app
RUN apt-get update && apt-get install -y --no-install-recommends \
    poppler-utils python3 python3-pip git unzip \
 && rm -rf /var/lib/apt/lists/*

# Python libs usadas pelo extrator
RUN pip3 install --no-cache-dir pandas openpyxl

# Configura o DocumentRoot do Apache (opcional se já usa /var/www/html)
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# Copia código
WORKDIR /var/www/html
COPY . .

# Copia vendor da etapa do Composer
COPY --from=vendor /app/vendor ./vendor

# Cria dirs graváveis
RUN mkdir -p uploads output logs \
 && chown -R www-data:www-data /var/www/html

EXPOSE 80
CMD ["apache2-foreground"]
