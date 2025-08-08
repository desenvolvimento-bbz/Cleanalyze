#!/bin/sh
set -e

# Se Railway montar um volume em /data, sincronizamos as pastas
if [ -d "/data" ]; then
  mkdir -p /data/uploads /data/output /data/logs /data/sessions
  # aponta as pastas do app para o volume
  rm -rf /app/uploads /app/output /app/logs /app/.sessions 2>/dev/null || true
  ln -sf /data/uploads /app/uploads
  ln -sf /data/output  /app/output
  ln -sf /data/logs    /app/logs
  ln -sf /data/sessions /app/.sessions
fi

echo "Iniciando PHP em 0.0.0.0:${PORT:-8080}…"
exec php -S 0.0.0.0:${PORT:-8080} -t /app
