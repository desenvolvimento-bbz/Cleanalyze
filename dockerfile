# Etapa 1: Composer (baixa dependências do PHP)
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction \
  --ignore-platform-req=ext-gd --ignore-platform-req=ext-zip

# Etapa 2: PHP + Apache (Debian)
FROM php:8.2-apache

# Pacotes do sistema (adicione libzip-dev e zlib1g-dev)
RUN apt-get update && apt-get install -y --no-install-recommends \
    poppler-utils \
    python3 python3-pip python3-pandas python3-openpyxl \
    git unzip \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
    libzip-dev zlib1g-dev \
  && rm -rf /var/lib/apt/lists/*

# Extensões do PHP: gd + zip
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd zip

# (Opcional) Mudar DocumentRoot – já é /var/www/html por padrão
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# Copia o app
WORKDIR /var/www/html
COPY . .

# Copia vendor do stage do Composer
COPY --from=vendor /app/vendor ./vendor

# Dirs graváveis
RUN mkdir -p uploads output logs \
 && chown -R www-data:www-data /var/www/html

EXPOSE 80
CMD ["apache2-foreground"]

# Faz o Apache ouvir na porta $PORT (Koyeb) ou 8080 (fallback) e inicia
CMD ["sh","-c","sed -ri \"s/Listen 80/Listen ${PORT:-8080}/\" /etc/apache2/ports.conf && apache2-foreground"]

