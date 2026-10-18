<?php
// Servicios de acceso (RA2): claves de API para que otros programas (una tienda online, una hoja
// de cálculo, un script…) usen la misma API sin sesión, enviando la cabecera X-API-Key.
// La clave solo se muestra al crearla; en la base de datos se guarda su huella SHA-256.

function usuarioApi(PDO $pdo, string $clave): array {
  $consulta = $pdo->prepare('SELECT * FROM claves_api WHERE hash = ?');
  $consulta->execute([hash('sha256', $clave)]);
  $fila = $consulta->fetch();
  if (!$fila || !(int)$fila['activa']) fallar('Clave de API no válida o revocada.', 401);

  $pdo->prepare('UPDATE claves_api SET ultimo_uso = ? WHERE id = ?')->execute([date('c'), $fila['id']]);
  return $GLOBALS['usuario_actual'] = [
    'id' => 0, 'nombre' => "API · {$fila['nombre']}", 'email' => null, 'rol' => 'api',
    'recursos' => json_decode((string)$fila['recursos'], true) ?: [], 'escritura' => (bool)(int)$fila['escritura'],
  ];
}

function listarClaves(PDO $pdo): array {
  return array_map(fn($f) => [
    'id' => (int)$f['id'], 'nombre' => $f['nombre'], 'prefijo' => $f['prefijo'],
    'recursos' => json_decode((string)$f['recursos'], true) ?: [], 'escritura' => (bool)(int)$f['escritura'],
    'activa' => (bool)(int)$f['activa'], 'ultimo_uso' => $f['ultimo_uso'], 'creado' => $f['creado'],
  ], $pdo->query('SELECT * FROM claves_api ORDER BY id DESC')->fetchAll());
}

function crearClave(PDO $pdo, array $esquema, array $entrada): array {
  $nombre = trim((string)($entrada['nombre'] ?? ''));
  $recursos = is_array($entrada['recursos'] ?? null) ? array_values(array_unique($entrada['recursos'])) : [];
  $errores = [];
  if ($nombre === '' || mb_strlen($nombre) > 100) $errores['nombre'] = 'Indica para qué programa es la clave (máximo 100 caracteres)';
  $validos = array_filter(array_keys($esquema), fn($r) => $r !== 'usuarios' && moduloActivo($r));
  if (!$recursos) $errores['recursos'] = 'Elige al menos un recurso';
  elseif (array_diff($recursos, $validos)) $errores['recursos'] = 'Recurso no válido o de un módulo inactivo';
  if ($errores) fallar('Revisa los campos marcados', 422, $errores);

  $clave = 'blush_' . bin2hex(random_bytes(20));
  $escritura = !empty($entrada['escritura']);
  $pdo->prepare('INSERT INTO claves_api (nombre, prefijo, hash, recursos, escritura, activa) VALUES (?, ?, ?, ?, ?, 1)')
      ->execute([$nombre, substr($clave, 0, 12), hash('sha256', $clave), json_encode($recursos), (int)$escritura]);
  $id = (int)$pdo->lastInsertId();
  registrar($pdo, 'crear', 'claves_api', $id, "Clave de API «{$nombre}» (" . implode(', ', $recursos) . ($escritura ? ', con escritura' : ', solo lectura') . ')');
  return ['id' => $id, 'clave' => $clave, 'prefijo' => substr($clave, 0, 12)];
}

function revocarClave(PDO $pdo, int $id): void {
  $consulta = $pdo->prepare('SELECT nombre FROM claves_api WHERE id = ? AND activa = 1');
  $consulta->execute([$id]);
  $nombre = $consulta->fetchColumn();
  if ($nombre === false) fallar('Clave no encontrada o ya revocada', 404);
  $pdo->prepare('UPDATE claves_api SET activa = 0 WHERE id = ?')->execute([$id]);
  registrar($pdo, 'borrar', 'claves_api', $id, "Clave de API «{$nombre}» revocada");
}
