#!/usr/bin/env bash
# Ejecuta las pruebas automáticas con un servidor PHP propio y una base de datos temporal:
# no toca los datos de datos/erp.sqlite ni necesita Apache.
#
#   bash tests/ejecutar.sh            pruebas de la API (+ navegador si Playwright está instalado)
#   SOLO_API=1 bash tests/ejecutar.sh solo las pruebas de la API
#   CAPTURAS=docs/capturas bash tests/ejecutar.sh   guarda capturas de pantalla
set -euo pipefail
cd "$(dirname "$0")/.."

PUERTO=${PUERTO:-8765}
TEMPORAL=$(mktemp -d)
export ERP_SQLITE="$TEMPORAL/pruebas.sqlite"

php -S "127.0.0.1:$PUERTO" -t . >"$TEMPORAL/servidor.log" 2>&1 &
SERVIDOR=$!
trap 'kill $SERVIDOR 2>/dev/null || true; rm -rf "$TEMPORAL"' EXIT

for _ in $(seq 50); do
  curl -s -o /dev/null "http://127.0.0.1:$PUERTO/" && break
  sleep 0.1
done

echo "Servidor de pruebas: http://127.0.0.1:$PUERTO (base de datos temporal)"
php tests/api.php "http://127.0.0.1:$PUERTO"

if [[ -z "${SOLO_API:-}" ]]; then
  if python3 -c 'import playwright' 2>/dev/null; then
    python3 tests/navegador.py "http://127.0.0.1:$PUERTO/" ${CAPTURAS:+"$(realpath "$CAPTURAS")"}
  else
    echo "(Playwright no está instalado: se omiten las pruebas en el navegador)"
  fi
fi
