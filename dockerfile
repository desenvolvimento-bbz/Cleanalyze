# Imagem mínima com PHP 8.2 (Alpine) + extensões necessárias + Python + Poppler
FROM alpine:3.20

# Instala PHP (CLI) e extensões usadas pelo PhpSpreadsheet + dependências úteis
RUN apk add --no-cache \
    php82 php82-cli php82-session php82-mbstring php82-zip php82-xml php82-dom php82-simplexml \
    php82-gd php82-curl php82-opcache php82-iconv \
    python3 py3-pip \
    poppler-utils \
    curl unzip git

# Composer (oficial) – funciona bem no Alpine
RUN curl -fsSL https://getcomposer.org/installer -o composer-setup.php \
 && php82 composer-setup.php --install-dir=/usr/local/bin --filename=composer \
 && rm composer-setup.php

# Define o PHP 8.2 como padrão para o cli
RUN ln -sf /usr/bin/php82 /usr/bin/php

WORKDIR /app
COPY . /app

# Instala dependências PHP
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# Instala dependências Python (se tiver requirements.txt)
RUN if [ -f requirements.txt ]; then pip install --no-cache-dir -r requirements.txt; fi

# Cria diretórios de upload/output e dá permissão
RUN mkdir -p /app/uploads /app/output && chmod -R 777 /app/uploads /app/output

# A Railway injeta $PORT. O servidor embutido do PHP vai ouvir nele.
EXPOSE 8080
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t /app"]