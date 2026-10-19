<?php
// Formularios web (RA3, criterio b): formularios públicos para que los datos reales entren solos
// en el ERP. Por ejemplo, «Pide presupuesto» en la web de la empresa crea un cliente potencial,
// y «Alta de proveedor» crea un proveedor. Se publican en formulario.html?f=<slug>.
//
// Protecciones del envío público (sin sesión): solo los campos elegidos, la misma validación que
// el alta a mano, un campo trampa contra robots y un máximo de envíos por hora, origen y formulario.

const RECURSOS_FORMULARIO = ['clientes', 'proveedores', 'productos'];
const ENVIOS_POR_HORA = 5;

function camposFormulario(array $esquema, string $recurso): array {
  return array_filter($esquema[$recurso]['campos'], fn($d) => !in_array($d['tipo'], ['clave', 'permisos', 'relacion'], true) && empty($d['auto']));
}

function filaFormulario(array $f): array {
  return [
    'id' => (int)$f['id'], 'titulo' => $f['titulo'], 'slug' => $f['slug'], 'recurso' => $f['recurso'],
    'campos' => json_decode($f['campos'], true) ?: [], 'mensaje' => $f['mensaje'], 'activo' => (bool)$f['activo'],
    'envios' => (int)$f['envios'], 'creado' => $f['creado'],
  ];
}

function listarFormularios(PDO $pdo, array $esquema): array {
  return [
    'formularios' => array_map('filaFormulario', $pdo->query('SELECT * FROM formularios ORDER BY id DESC')->fetchAll()),
    'recursos' => array_values(array_filter(array_map(fn($r) => isset($esquema[$r]) && moduloActivo($r) ? [
      'id' => $r, 'titulo' => $esquema[$r]['titulo'],
      'campos' => array_values(array_map(fn($c, $d) => ['clave' => $c, 'etiqueta' => $d['etiqueta'], 'obligatorio' => !empty($d['obligatorio']) && !array_key_exists('defecto', $d)],
        array_keys(camposFormulario($esquema, $r)), camposFormulario($esquema, $r))),
    ] : null, RECURSOS_FORMULARIO))),
  ];
}

function slugFormulario(string $texto): string {
  $texto = strtr(mb_strtolower($texto), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
  return trim(preg_replace('/[^a-z0-9]+/', '-', $texto), '-');
}

function validarFormulario(PDO $pdo, array $esquema, array $entrada, ?int $id): array {
  $errores = [];
  $titulo = trim((string)($entrada['titulo'] ?? ''));
  if ($titulo === '' || mb_strlen($titulo) > 120) $errores['titulo'] = 'Ponle un título (máximo 120 caracteres)';
  $recurso = (string)($entrada['recurso'] ?? '');
  if (!in_array($recurso, RECURSOS_FORMULARIO, true) || !isset($esquema[$recurso]) || !moduloActivo($recurso)) $errores['recurso'] = 'Elige qué crea el formulario';
  $slug = slugFormulario((string)($entrada['slug'] ?? '') ?: $titulo);
  if ($slug === '' || strlen($slug) > 60) $errores['slug'] = 'Dirección no válida';
  else {
    $repetido = $pdo->prepare('SELECT id FROM formularios WHERE slug = ? AND id <> ?');
    $repetido->execute([$slug, $id ?? 0]);
    if ($repetido->fetchColumn()) $errores['slug'] = 'Ya hay otro formulario con esta dirección';
  }
  $campos = [];
  if (!isset($errores['recurso'])) {
    $disponibles = camposFormulario($esquema, $recurso);
    $campos = array_values(array_intersect(array_map('strval', (array)($entrada['campos'] ?? [])), array_keys($disponibles)));
    // Los obligatorios sin valor por defecto tienen que estar en el formulario
    foreach ($disponibles as $c => $d) {
      if (!empty($d['obligatorio']) && !array_key_exists('defecto', $d) && !in_array($c, $campos, true)) {
        $errores['campos'] = "«{$d['etiqueta']}» es obligatorio: tiene que estar en el formulario";
        break;
      }
    }
    if (!$campos) $errores['campos'] = 'Elige al menos un campo';
  }
  $mensaje = trim((string)($entrada['mensaje'] ?? '')) ?: '¡Gracias! Hemos recibido tus datos y te contactaremos pronto.';
  if (mb_strlen($mensaje) > 255) $errores['mensaje'] = 'Máximo 255 caracteres';
  if ($errores) fallar('Revisa los campos marcados', 422, $errores);
  return ['titulo' => $titulo, 'slug' => $slug, 'recurso' => $recurso, 'campos' => json_encode($campos),
    'mensaje' => $mensaje, 'activo' => array_key_exists('activo', $entrada) ? (int)(bool)$entrada['activo'] : 1];
}

function guardarFormulario(PDO $pdo, array $esquema, array $entrada, ?int $id): array {
  if ($id !== null) {
    $actual = $pdo->prepare('SELECT * FROM formularios WHERE id = ?');
    $actual->execute([$id]);
    $fila = $actual->fetch() ?: fallar('Formulario no encontrado', 404);
    // Lo que no se envía se queda como estaba (así se puede pausar enviando solo {activo: false})
    $datos = validarFormulario($pdo, $esquema, $entrada + filaFormulario($fila), $id);
    $pdo->prepare('UPDATE formularios SET titulo = ?, slug = ?, recurso = ?, campos = ?, mensaje = ?, activo = ? WHERE id = ?')
        ->execute([...array_values($datos), $id]);
    registrar($pdo, 'editar', 'formularios', $id, "Formulario web «{$datos['titulo']}»" . ($datos['activo'] ? '' : ' (pausado)'));
  } else {
    $datos = validarFormulario($pdo, $esquema, $entrada, null);
    $pdo->prepare('INSERT INTO formularios (titulo, slug, recurso, campos, mensaje, activo, envios) VALUES (?, ?, ?, ?, ?, ?, 0)')
        ->execute(array_values($datos));
    $id = (int)$pdo->lastInsertId();
    registrar($pdo, 'crear', 'formularios', $id, "Formulario web «{$datos['titulo']}» publicado en formulario.html?f={$datos['slug']}");
  }
  $consulta = $pdo->prepare('SELECT * FROM formularios WHERE id = ?');
  $consulta->execute([$id]);
  return filaFormulario($consulta->fetch());
}

function borrarFormulario(PDO $pdo, int $id): void {
  $consulta = $pdo->prepare('SELECT titulo FROM formularios WHERE id = ?');
  $consulta->execute([$id]);
  $titulo = $consulta->fetchColumn() ?: fallar('Formulario no encontrado', 404);
  $pdo->prepare('DELETE FROM formulario_envios WHERE formulario_id = ?')->execute([$id]);
  $pdo->prepare('DELETE FROM formularios WHERE id = ?')->execute([$id]);
  registrar($pdo, 'borrar', 'formularios', $id, "Formulario web «{$titulo}» borrado (los registros que creó se conservan)");
}

// ---------- Parte pública (sin sesión) ----------

function formularioPublico(PDO $pdo, array $esquema, string $slug): array {
  $consulta = $pdo->prepare('SELECT * FROM formularios WHERE slug = ? AND activo = 1');
  $consulta->execute([$slug]);
  $fila = $consulta->fetch();
  if (!$fila || !isset($esquema[$fila['recurso']]) || !moduloActivo($fila['recurso'])) fallar('Este formulario no existe o ya no está disponible.', 404);
  return filaFormulario($fila);
}

function definicionPublica(array $config, array $esquema, array $formulario): array {
  $campos = camposFormulario($esquema, $formulario['recurso']);
  return [
    'titulo' => $formulario['titulo'], 'empresa' => $config['empresa']['nombre'],
    'campos' => array_map(fn($c) => [
      'clave' => $c, 'etiqueta' => $campos[$c]['etiqueta'], 'tipo' => $campos[$c]['tipo'],
      'obligatorio' => !empty($campos[$c]['obligatorio']), 'opciones' => $campos[$c]['opciones'] ?? null,
    ], array_values(array_filter($formulario['campos'], fn($c) => isset($campos[$c])))),
  ];
}

function enviarFormulario(PDO $pdo, array $config, array $esquema, array $formulario, array $entrada): array {
  // Campo trampa: invisible para las personas; si viene relleno, es un robot. Se responde «ok» sin guardar.
  if (trim((string)($entrada['web'] ?? '')) !== '') return ['mensaje' => $formulario['mensaje']];

  // Huella del origen (no se guarda la IP): límite de envíos por hora en cada formulario
  $origen = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($config['instalacion']['fecha'] ?? 'blush'));
  $hace = gmdate('Y-m-d H:i:s', time() - 3600);   // «creado» se guarda en UTC
  $recientes = $pdo->prepare('SELECT COUNT(*) FROM formulario_envios WHERE origen = ? AND formulario_id = ? AND creado >= ?');
  $recientes->execute([$origen, $formulario['id'], $hace]);
  if ((int)$recientes->fetchColumn() >= ENVIOS_POR_HORA) fallar('Has enviado demasiados formularios seguidos. Inténtalo dentro de un rato.', 429);

  $recurso = $formulario['recurso'];
  $modulo = $esquema[$recurso];
  $campos = array_intersect_key(camposFormulario($esquema, $recurso), array_flip($formulario['campos']));
  $datos = array_intersect_key((array)($entrada['datos'] ?? []), $campos);
  // Los campos que no están en el formulario toman su valor por defecto (un cliente entra como «potencial»)
  $resto = array_diff_key(camposFormulario($esquema, $recurso), $campos);
  [$limpio, $errores] = validarCampos($pdo, $campos + $resto, $datos, false);
  if ($errores) fallar('Revisa los campos marcados', 422, $errores);
  if (isset($modulo['campos']['estado']['defecto']) && !isset($campos['estado'])) $limpio['estado'] = $modulo['campos']['estado']['defecto'];

  $pdo->beginTransaction();
  try {
    $columnas = array_keys($limpio);
    $pdo->prepare("INSERT INTO $recurso (" . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')')
        ->execute(array_values($limpio));
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO formulario_envios (formulario_id, origen, registro_id) VALUES (?, ?, ?)')->execute([$formulario['id'], $origen, $id]);
    $pdo->prepare('UPDATE formularios SET envios = envios + 1 WHERE id = ?')->execute([$formulario['id']]);
    // En el registro aparece el formulario como autor
    $GLOBALS['usuario_actual'] = ['id' => 0, 'nombre' => "Formulario web · {$formulario['titulo']}", 'rol' => 'formulario'];
    registrar($pdo, 'crear', $recurso, $id, ucfirst($modulo['singular']) . " «" . ($limpio[$modulo['principal']] ?? "#$id") . "» recibido por el formulario web");
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  return ['mensaje' => $formulario['mensaje']];
}
