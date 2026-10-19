<?php
// Toma una muestra del estado del servidor (CPU, memoria, disco, carga y tamaño de la base de datos)
// y la guarda en la tabla «metricas» para la pantalla de Rendimiento (RA3, criterio f).
// Equivale al monitor de clase con psutil, pero sin dependencias: lee /proc directamente.
//
//   * * * * * php /var/www/html/Sistema-de-gesti-n-empresarial/scripts/monitor.php > /dev/null 2>&1
//
// Opciones: --sqlite=ruta --instalacion=ruta (pruebas automáticas)

$opciones = getopt('', ['sqlite:', 'instalacion:']);
if (isset($opciones['sqlite'])) putenv('ERP_SQLITE=' . $opciones['sqlite']);
if (isset($opciones['instalacion'])) putenv('ERP_INSTALACION=' . $opciones['instalacion']);
require __DIR__ . '/comun.php';

try {
  [$pdo, $config] = arrancar($config, $esquema);
  $m = tomarMuestra($pdo, $config);
  $f = fn($v, $s = ' %') => $v === null ? '—' : $v . $s;
  echo '[' . date('Y-m-d H:i:s') . "] CPU {$f($m['cpu'])} · RAM {$f($m['ram'])} · disco {$f($m['disco'])} · carga {$f($m['carga'], '')} · BD {$m['bd_kb']} KB\n";
} catch (Throwable $e) {
  fwrite(STDERR, "✗ {$e->getMessage()}\n");
  exit(1);
}
