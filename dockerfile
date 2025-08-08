# ==== base PHP com Composer ====
FROM php:8.2-cli

# Instala dependências do sistema (python3, poppler, zip, git) e extensões úteis
RUN apt-get update && apt-get install -y --no-install-recommends \
    python3 python3-venv python3-pip \
    poppler-utils \
    git unzip \
 && rm -rf /var/lib/apt/lists/*

# Instala Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Cria diretório da app
WORKDIR /app

# Copia composer.* e instala deps PHP (Dompdf, PhpSpreadsheet etc.)
COPY composer.json composer.lock* /app/
RUN composer install --no-dev --prefer-dist --no-interaction || composer install --no-dev --prefer-dist --no-interaction

# Copia código
COPY . /app

# Pastas graváveis em runtime
RUN mkdir -p /app/logs /app/uploads /app/output \
 && chown -R www-data:www-data /app

# Porta será injetada pelo Railway (ENV PORT)
ENV PORT=8080

# Comando: PHP embutido servindo a raiz do app
CMD ["sh", "-c", "php -d variables_order=EGPCS -S 0.0.0.0:${PORT} -t /app"]
