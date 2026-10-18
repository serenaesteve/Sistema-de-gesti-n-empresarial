<?php
// Actualizaciones (RA2): migraciones numeradas en instalacion/migraciones/*.php.
// Las aplicadas se anotan en la tabla «migraciones». Mientras haya alguna pendiente,
// el ERP queda en mantenimiento hasta que un administrador lo actualice.

function migracionesDisponibles(): array {
  $lista = [];
  foreach (glob(__DIR__ . '/../instalacion/migraciones/[0-9][0-9][0-9]-*.php') as $archivo) {
    $id = basename($archivo, '.php');
    $lista[$id] = ['id' => $id] + require $archivo;
  }
  ksort($lista);
  return $lista;
}

function asegurarTablaMigraciones(PDO $pdo): void {
  $fecha = esMysql($pdo) ? 'TIMESTAMP' : 'DATETIME';
  $pdo->exec("CREATE TABLE IF NOT EXISTS migraciones (
    id VARCHAR(80) PRIMARY KEY,
    version VARCHAR(20) NOT NULL,
    descripcion VARCHAR(255),
    aplicada $fecha DEFAULT CURRENT_TIMESTAMP
  )" . (esMysql($pdo) ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
}

function migracionesAplicadas(PDO $pdo): array {
  return $pdo->query('SELECT id, version, descripcion, aplicada FROM migraciones ORDER BY id')->fetchAll();
}

function migracionesPendientes(PDO $pdo): array {
  $aplicadas = array_column(migracionesAplicadas($pdo), 'id');
  return array_values(array_map(
    fn($m) => ['id' => $m['id'], 'version' => $m['version'], 'descripcion' => $m['descripcion']],
    array_filter(migracionesDisponibles(), fn($m) => !in_array($m['id'], $aplicadas, true))
  ));
}

// Carpeta de copias automáticas: junto a instalacion.json (datos/copias/)
function carpetaCopias(): string {
  $carpeta = dirname(rutaInstalacion()) . '/copias';
  if (!is_dir($carpeta) && !mkdir($carpeta, 0777, true)) {
    throw new RuntimeException('No se pudo crear la carpeta datos/copias/ para la copia de seguridad.');
  }
  return $carpeta;
}

// Copia de seguridad completa en un archivo: SQLite se copia tal cual (VACUUM INTO, consistente
// aunque haya conexiones abiertas); MySQL se exporta al mismo JSON que «Descargar copia».
function copiaEnArchivo(PDO $pdo, array $config, array $esquema, string $motivo): string {
  $base = carpetaCopias() . '/erp-' . date('Ymd-His') . "-$motivo";
  if (esMysql($pdo)) {
    $ruta = "$base.json";
    file_put_contents($ruta, json_encode(datosCopia($pdo, $config, $esquema), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  } else {
    $ruta = "$base.sqlite";
    $pdo->prepare('VACUUM INTO ?')->execute([$ruta]);
  }
  @chmod($ruta, 0666);
  return $ruta;
}

// Aplica las migraciones pendientes. Con $copia = true (actualización) guarda antes una copia.
function aplicarMigraciones(PDO $pdo, array $config, array $esquema, bool $copia = true): array {
  asegurarTablaMigraciones($pdo);
  $pendientes = migracionesPendientes($pdo);
  if (!$pendientes) return ['aplicadas' => [], 'copia' => null];

  $rutaCopia = $copia ? copiaEnArchivo($pdo, $config, $esquema, 'antes-de-actualizar') : null;
  $disponibles = migracionesDisponibles();
  $mysql = esMysql($pdo);
  $hechas = [];

  // SQLite: todo en una transacción (si una migración falla, no queda nada a medias).
  // MySQL: el DDL hace commit implícito, así que se aplican una a una.
  if (!$mysql) $pdo->beginTransaction();
  try {
    foreach ($pendientes as $m) {
      ($disponibles[$m['id']]['aplicar'])($pdo, $esquema);
      $pdo->prepare('INSERT INTO migraciones (id, version, descripcion) VALUES (?, ?, ?)')
          ->execute([$m['id'], $m['version'], $m['descripcion']]);
      $hechas[] = $m['id'];
    }
    if (!$mysql) $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $falla = $pendientes[count($hechas)]['id'];
    throw new RuntimeException("La migración $falla ha fallado: {$e->getMessage()}. "
      . ($mysql ? 'Las anteriores sí se aplicaron; ' : 'No se ha aplicado ningún cambio; ')
      . ($rutaCopia ? 'copia de seguridad en datos/copias/' . basename($rutaCopia) . '.' : ''));
  }
  return ['aplicadas' => $hechas, 'copia' => $rutaCopia ? basename($rutaCopia) : null];
}

// Actualización pedida por un administrador desde la pantalla «Actualizar»
function actualizar(PDO $pdo, array $config, array $esquema): array {
  $instalada = $config['instalacion']['version'] ?? '?';
  $resultado = aplicarMigraciones($pdo, $config, $esquema, true);
  if (!$resultado['aplicadas']) fallar('No hay actualizaciones pendientes.', 409);

  escribirInstalacion(['version' => VERSION_APP, 'actualizada' => date('c')] + $config['instalacion']);
  registrar($pdo, 'actualizar', 'sistema', null,
    "Actualización $instalada → " . VERSION_APP . ': ' . implode(', ', $resultado['aplicadas']) . " · copia: {$resultado['copia']}");
  return $resultado + ['desde' => $instalada, 'hasta' => VERSION_APP];
}
