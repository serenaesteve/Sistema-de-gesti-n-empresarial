#!/usr/bin/env bash
# Corrige los avisos de la vista «Sistema» (incidencias I-04, I-06 y I-07 del README).
# Uso:  sudo bash scripts/configurar-servidor.sh
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Ejecútalo con sudo: sudo bash $0" >&2
  exit 1
fi

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
INI="/etc/php/$PHP_VERSION/apache2/php.ini"

echo "→ Copia de seguridad de $INI"
cp "$INI" "$INI.bak-$(date +%Y%m%d-%H%M%S)"

echo "→ Instalando extensiones gd e intl"
apt-get install -y "php$PHP_VERSION-gd" "php$PHP_VERSION-intl"

echo "→ Ajustando php.ini de Apache"
ajustar() {  # ajustar <directiva> <valor>: sustituye la línea (aunque esté comentada con ;)
  sed -i -E "s|^;?\s*$1\s*=.*|$1 = $2|" "$INI"
  grep -q "^$1 = $2" "$INI" || echo "$1 = $2" >> "$INI"
}
ajustar memory_limit 256M
ajustar upload_max_filesize 20M
ajustar post_max_size 20M
ajustar max_execution_time 120
ajustar date.timezone Europe/Madrid

echo "→ Reiniciando Apache"
systemctl restart apache2

echo
grep -E '^(memory_limit|upload_max_filesize|post_max_size|max_execution_time|date.timezone)' "$INI"
php -m | grep -E '^(gd|intl)$'
echo "✓ Listo. Vuelve a abrir la vista Sistema del ERP."
