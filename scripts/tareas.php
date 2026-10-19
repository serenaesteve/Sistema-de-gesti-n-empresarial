<?php
// Ejecuta las extracciones programadas cuya hora ha llegado (RA3, criterio e).
// Pensado para el programador de tareas del sistema (cron), cada 15 minutos:
//
//   */15 * * * * php /var/www/html/Sistema-de-gesti-n-empresarial/scripts/tareas.php >> /var/log/blush-tareas.log 2>&1
//
// Opciones:
//   --todas    ejecuta todas las tareas activas aunque no les toque (para probar)
//   --id=3     ejecuta solo esa tarea
//   --sqlite=ruta --instalacion=ruta   otras rutas (pruebas automáticas)

$opciones = getopt('', ['todas', 'id:', 'sqlite:', 'instalacion:']);
if (isset($opciones['sqlite'])) putenv('ERP_SQLITE=' . $opciones['sqlite']);
if (isset($opciones['instalacion'])) putenv('ERP_INSTALACION=' . $opciones['instalacion']);
require __DIR__ . '/comun.php';

try {
  [$pdo, $config] = arrancar($config, $esquema);
  $GLOBALS['usuario_actual'] = usuarioTareas();
  if (isset($opciones['id'])) {
    $resultados = ['#' . $opciones['id'] => ejecutarTareaPorId($pdo, $esquema, (int)$opciones['id'])];
  } elseif (isset($opciones['todas'])) {
    $resultados = [];
    foreach ($pdo->query('SELECT * FROM tareas WHERE activa = 1 ORDER BY id')->fetchAll() as $tarea) {
      $resultados[$tarea['nombre']] = ejecutarTarea($pdo, $esquema, $tarea, 'cron');
    }
  } else {
    $resultados = ejecutarPendientes($pdo, $esquema);
  }
  $hora = date('Y-m-d H:i:s');
  if (!$resultados) echo "[$hora] Ninguna tarea pendiente\n";
  $errores = 0;
  foreach ($resultados as $nombre => $r) {
    if ($r['estado'] === 'ok') echo "[$hora] ✓ $nombre: {$r['filas']} filas → datos/extracciones/{$r['archivo']} ({$r['ms']} ms)\n";
    else { $errores++; echo "[$hora] ✗ $nombre: {$r['mensaje']}\n"; }
  }
  exit($errores ? 1 : 0);
} catch (Throwable $e) {
  fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] ✗ {$e->getMessage()}\n");
  exit(1);
}
