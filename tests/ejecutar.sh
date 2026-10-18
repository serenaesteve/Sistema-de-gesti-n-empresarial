#!/usr/bin/env bash
# Ejecuta las pruebas automáticas con servidores PHP propios y bases de datos temporales:
# no toca datos/erp.sqlite ni datos/instalacion.json, ni necesita Apache.
#
#   bash tests/ejecutar.sh                          instalación + API (+ navegador si hay Playwright)
#   SOLO_API=1 bash tests/ejecutar.sh               sin las pruebas en el navegador
#   CAPTURAS=docs/capturas bash tests/ejecutar.sh   guarda capturas de pantalla
#
# Entornos que se preparan:
#   :8766  ERP sin instalar           → tests/instalacion.php (asistente, módulos, ajustes, supuestos)
#   :8765  ERP instalado por terminal → tests/api.php y tests/navegador.py (perfil completo con demo)
#   :8767  ERP sin instalar           → asistente de instalación en el navegador
set -euo pipefail
cd "$(dirname "$0")/.."

TEMPORAL=$(mktemp -d)
SERVIDORES=()
trap 'kill "${SERVIDORES[@]}" 2>/dev/null || true; rm -rf "$TEMPORAL"' EXIT

# arrancar <puerto> <carpeta>: servidor PHP con su propia base de datos e instalacion.json
arrancar() {
  mkdir -p "$TEMPORAL/$2"
  ERP_SQLITE="$TEMPORAL/$2/erp.sqlite" ERP_INSTALACION="$TEMPORAL/$2/instalacion.json" \
    php -S "127.0.0.1:$1" -t . >"$TEMPORAL/$2.log" 2>&1 &
  SERVIDORES+=($!)
  for _ in $(seq 50); do curl -s -o /dev/null "http://127.0.0.1:$1/" && return; sleep 0.1; done
}

echo "Pruebas de instalación y configuración (ERP sin instalar en :8766)"
arrancar 8766 sin-instalar
php tests/instalacion.php "http://127.0.0.1:8766" "$TEMPORAL/sin-instalar/instalacion.json"

echo
echo "Pruebas de la API (instalación desatendida, perfil completo con demo, en :8765)"
mkdir -p "$TEMPORAL/instalado"
php scripts/instalar.php --perfil=distribuidora --empresa="Blush Studio S.L." --nif=B12345678 \
  --direccion="C/ de la Paz 12, 46003 Valencia" --email=hola@blush.test \
  --admin-nombre="Serena Esteve" --admin-email=serena@blush.test --admin-clave=blush2026 --demo --entorno=pruebas \
  --sqlite="$TEMPORAL/instalado/erp.sqlite" --instalacion="$TEMPORAL/instalado/instalacion.json" >/dev/null
arrancar 8765 instalado
php tests/api.php "http://127.0.0.1:8765"

if [[ -z "${SOLO_API:-}" ]]; then
  if python3 -c 'import playwright' 2>/dev/null; then
    arrancar 8767 asistente
    URL_INSTALADOR="http://127.0.0.1:8767/" \
      python3 tests/navegador.py "http://127.0.0.1:8765/" ${CAPTURAS:+"$(realpath "$CAPTURAS")"}
  else
    echo "(Playwright no está instalado: se omiten las pruebas en el navegador)"
  fi
fi
