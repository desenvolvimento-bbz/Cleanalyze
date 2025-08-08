# Base mínima com PHP + Python + Poppler
FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive \
    APP_ENV=production \
    PORT=8080

# Dependências do sistema
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl unzip git \
    php-cli php-mbstring php-xml php-zip php-gd php-curl \
    python3 python3-pip \
    poppler-utils \
 && rm -rf /var/lib/apt/lists/*

# Composer
RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
 && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
 && rm /tmp/composer-setup.php

WORKDIR /app

# Instala dependências PHP no container
COPY composer.json composer.lock /app/
RUN composer install --no-dev --prefer-dist --no-interaction

# Copia o restante do app
COPY . /app

# PHP ini custom (uploads maiores + sessão em pasta do app)
COPY docker/php.ini /etc/php/8.2/cli/conf.d/app.ini

# Prepara diretórios de escrita
RUN mkdir -p /app/uploads /app/output /app/logs /app/.sessions \
 && chmod -R 777 /app/uploads /app/output /app/logs /app/.sessions

# Entry-point que cria dirs (com volume) e sobe o servidor
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8080
CMD ["/entrypoint.sh"]