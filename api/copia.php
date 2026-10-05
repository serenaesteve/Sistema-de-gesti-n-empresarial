<?php
// Copias de seguridad: exportar toda la base de datos a JSON y restaurarla.
// El formato es el mismo para SQLite y MySQL, así que una copia sirve para cambiar de motor.

function exportarCopia(PDO $pdo, array $config, array $esquema): never {
  $copia = [
    'aplicacion' => $config['app']['nombre'],
    'version'    => $config['app']['version'],
    'esquema'    => VERSION_ESQUEMA,
    'motor'      => esMysql($pdo) ? 'mysql' : 'sqlite',
    'fecha'      => date('c'),
    'tablas'     => [],
  ];
  foreach (array_keys(tablas($esquema)) as $tabla) {
    $copia['tablas'][$tabla] = $pdo->query("SELECT * FROM $tabla ORDER BY id")->fetchAll();
  }

  registrar($pdo, 'copia', 'sistema', null, 'Copia de seguridad descargada');
  header('Content-Disposition: attachment; filename="copia-blush-' . date('Y-m-d-His') . '.json"');
  echo json_encode($copia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  exit;
}

// Sustituye todos los datos por los de la copia, dentro de una transacción:
// si algo falla, la base de datos se queda como estaba.
function restaurarCopia(PDO $pdo, array $esquema, array $copia): array {
  $tablas = tablas($esquema);
  if (($copia['esquema'] ?? null) !== VERSION_ESQUEMA || !is_array($copia['tablas'] ?? null)) {
    fallar('El archivo no es una copia de seguridad válida de esta versión (esquema ' . VERSION_ESQUEMA . ').', 422);
  }

  // Columnas permitidas por tabla: nunca se usa un nombre de columna que venga del archivo sin comprobarlo
  $permitidas = [];
  foreach ($tablas as $tabla => $info) {
    $permitidas[$tabla] = array_merge(['id', $info['fecha'] ?? 'creado'], $info['padre'] ? [$info['padre'][0]] : [], array_keys($info['campos']));
  }
  foreach ($copia['tablas'] as $tabla => $filas) {
    if (!isset($tablas[$tabla]) || !is_array($filas)) fallar("La copia contiene una tabla desconocida: $tabla", 422);
    foreach ($filas as $fila) {
      if (!is_array($fila) || array_diff(array_keys($fila), $permitidas[$tabla])) fallar("La tabla $tabla de la copia tiene columnas desconocidas.", 422);
    }
  }
  if (empty($copia['tablas']['usuarios'])) fallar('La copia no tiene usuarios: no se podría iniciar sesión.', 422);

  $pdo->beginTransaction();
  try {
    // Se vacía en orden inverso (primero lo que depende de otras tablas)
    foreach (array_reverse(array_keys($tablas)) as $tabla) {
      if ($tabla === 'facturas') $pdo->exec('DELETE FROM facturas WHERE rectifica_id IS NOT NULL');
      $pdo->exec("DELETE FROM $tabla");
    }
    $total = 0;
    foreach (array_keys($tablas) as $tabla) {
      $filas = $copia['tablas'][$tabla] ?? [];
      usort($filas, fn($a, $b) => $a['id'] <=> $b['id']); // las rectificativas van después de su factura
      foreach ($filas as $fila) {
        $columnas = array_keys($fila);
        $pdo->prepare("INSERT INTO $tabla (" . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')')
            ->execute(array_values($fila));
        $total++;
      }
    }
    registrar($pdo, 'restaurar', 'sistema', null, "Copia de seguridad del {$copia['fecha']} restaurada ($total filas)");
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  return ['filas' => $total];
}

// Cambio de la propia contraseña (cualquier rol)
function cambiarMiClave(PDO $pdo, array $usuario, array $cuerpo): void {
  $consulta = $pdo->prepare('SELECT clave FROM usuarios WHERE id = ?');
  $consulta->execute([$usuario['id']]);
  if (!password_verify((string)($cuerpo['actual'] ?? ''), (string)$consulta->fetchColumn())) {
    usleep(600000);
    fallar('Revisa los campos marcados', 422, ['actual' => 'La contraseña actual no es correcta']);
  }
  $nueva = (string)($cuerpo['nueva'] ?? '');
  if (mb_strlen($nueva) < 8) fallar('Revisa los campos marcados', 422, ['nueva' => 'Mínimo 8 caracteres']);

  $pdo->prepare('UPDATE usuarios SET clave = ? WHERE id = ?')->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuario['id']]);
  registrar($pdo, 'editar', 'usuarios', $usuario['id'], "Usuario «{$usuario['nombre']}» · contraseña cambiada por el propio usuario");
}
