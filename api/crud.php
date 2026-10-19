<?php
// Operaciones genéricas de listado, alta, edición y borrado para cualquier módulo del esquema,
// incluidos los que tienen líneas (pedidos y compras: varios productos por registro).
//
// Parámetros del listado (GET):
//   q=texto              búsqueda en los campos de texto y en los nombres relacionados
//   orden=campo&dir=asc  ordenación (solo columnas conocidas)
//   pagina=2             paginación; sin este parámetro se devuelven todas las filas
//   desde=&hasta=        filtro por el campo de fecha del módulo (aaaa-mm-dd)
//   cliente_id=3         filtro por cualquier campo de relación
//   formato=csv|json     descarga en CSV (para Excel) o JSON

function crud(PDO $pdo, array $config, array $esquema, array $usuario, string $tabla, string $metodo, ?int $id): never {
  $modulo = $esquema[$tabla];
  $accion = ['GET' => 'ver', 'POST' => 'crear', 'PUT' => 'editar', 'DELETE' => 'borrar'][$metodo] ?? null;
  if (!$accion) fallar('Método no permitido', 405);
  if ($accion !== 'ver' && !empty($modulo['solo_lectura'])) fallar("Las {$modulo['titulo']} no se pueden modificar desde aquí.", 405);
  exigirPermiso($esquema, $usuario, $accion, $tabla);

  if ($metodo === 'GET') {
    if ($id !== null) responder(obtener($pdo, $esquema, $tabla, $id));
    if (($_GET['formato'] ?? '') === 'csv') exportarCsv($pdo, $esquema, $tabla);
    if (($_GET['formato'] ?? '') === 'json') exportarJson($pdo, $config, $esquema, $tabla);
    if (($_GET['formato'] ?? '') === 'xlsx') exportarResultado(resultadoListado($pdo, $esquema, $tabla) + ['titulo' => $modulo['titulo']], 'xlsx', $tabla);
    responder(listar($pdo, $config, $esquema, $tabla));
  }

  $pdo->beginTransaction();
  try {
    switch ($metodo) {
      case 'POST':
        [$datos, $lineas] = validarRegistro($pdo, $modulo, leerCuerpo(), false);
        [$datos, $lineas] = antesDeGuardar($pdo, $tabla, $datos, $lineas, null);
        $columnas = array_keys($datos);
        $huecos = implode(', ', array_fill(0, count($columnas), '?'));
        $pdo->prepare("INSERT INTO $tabla (" . implode(', ', $columnas) . ") VALUES ($huecos)")
            ->execute(array_values($datos));
        $id = (int)$pdo->lastInsertId();
        guardarLineas($pdo, $modulo, $id, $lineas);
        $fila = obtener($pdo, $esquema, $tabla, $id);
        registrar($pdo, 'crear', $tabla, $id, describir($modulo, $fila));
        $codigo = 201;
        break;

      case 'PUT':
        exigirId($id);
        $anterior = obtener($pdo, $esquema, $tabla, $id);
        [$datos, $lineas] = validarRegistro($pdo, $modulo, leerCuerpo(), true);
        [$datos, $lineas] = antesDeGuardar($pdo, $tabla, $datos, $lineas, $anterior);
        $asignaciones = implode(', ', array_map(fn($c) => "$c = ?", array_keys($datos)));
        $pdo->prepare("UPDATE $tabla SET $asignaciones WHERE id = ?")
            ->execute([...array_values($datos), $id]);
        guardarLineas($pdo, $modulo, $id, $lineas);
        $fila = obtener($pdo, $esquema, $tabla, $id);
        registrar($pdo, 'editar', $tabla, $id, describir($modulo, $fila) . cambios($modulo, $anterior, $fila, $datos));
        $codigo = 200;
        break;

      case 'DELETE':
        exigirId($id);
        $fila = obtener($pdo, $esquema, $tabla, $id);
        antesDeBorrar($pdo, $tabla, $fila);
        try {
          $pdo->prepare("DELETE FROM $tabla WHERE id = ?")->execute([$id]);
        } catch (PDOException $e) {
          // Código 23000: violación de integridad (otro registro depende de este).
          if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'FOREIGN KEY')) {
            fallar('No se puede borrar: hay otros registros que dependen de ' . articulo($modulo) . " {$modulo['singular']}.", 409);
          }
          throw $e;
        }
        registrar($pdo, 'borrar', $tabla, $id, describir($modulo, $fila));
        $fila = ['id' => $id];
        $codigo = 200;
        break;
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Campo único repetido (por ejemplo, un email de usuario que ya existe)
    if ($e instanceof PDOException && $e->getCode() === '23000') {
      foreach ($modulo['campos'] as $campo => $def) {
        if (!empty($def['unico']) && str_contains($e->getMessage(), $campo)) {
          fallar('Revisa los campos marcados', 422, [$campo => 'Ya existe otro registro con este valor']);
        }
      }
    }
    throw $e;
  }
  responder($fila, $codigo);
}

// ---------- Consultas ----------

// SELECT con los JOIN de las relaciones y las columnas calculadas.
// Devuelve la consulta, las columnas en las que se puede buscar, las que se pueden ordenar
// y la parte FROM … JOIN (para las consultas del constructor, api/consultas.php).
function consultaBase(array $esquema, string $tabla): array {
  $modulo = $esquema[$tabla];
  $select = ['t.*'];
  $joins = [];
  $buscables = ['t.id'];
  $ordenables = ['id' => 't.id'];

  foreach ($modulo['campos'] as $campo => $def) {
    if ($def['tipo'] === 'clave') continue;
    if ($def['tipo'] === 'relacion') {
      $alias = "r_$campo";
      $nombre = nombreRelacion($campo);
      $joins[] = "LEFT JOIN {$def['tabla']} $alias ON $alias.id = t.$campo";
      $select[] = "$alias.{$def['mostrar']} AS $nombre";
      $buscables[] = "$alias.{$def['mostrar']}";
      $ordenables[$nombre] = "$alias.{$def['mostrar']}";
    } else {
      if (in_array($def['tipo'], ['texto', 'email', 'tel', 'opcion', 'nif'], true)) $buscables[] = "t.$campo";
      $ordenables[$campo] = "t.$campo";
    }
  }
  foreach ($modulo['calculados'] ?? [] as $campo => $def) {
    $select[] = "{$def['sql']} AS $campo";
    $ordenables[$campo] = $def['sql'];
  }

  $desde = " FROM $tabla t " . implode(' ', $joins);
  return ['SELECT ' . implode(', ', $select) . $desde, $buscables, $ordenables, $desde];
}

// cliente_id -> cliente
function nombreRelacion(string $campo): string {
  return preg_replace('/_id$/', '', $campo);
}

// Primer campo de tipo fecha del módulo (para el filtro desde/hasta)
function campoFecha(array $modulo): ?string {
  foreach ($modulo['campos'] as $campo => $def) if ($def['tipo'] === 'fecha') return $campo;
  return null;
}

// Construye WHERE y ORDER BY a partir de los parámetros de la petición.
function condiciones(array $esquema, string $tabla, array $buscables, array $ordenables): array {
  $where = [];
  $parametros = [];

  $q = trim((string)($_GET['q'] ?? ''));
  if ($q !== '') {
    $partes = [];
    foreach ($buscables as $i => $columna) {
      $partes[] = "$columna LIKE :q$i";
      $parametros["q$i"] = "%$q%";
    }
    $where[] = '(' . implode(' OR ', $partes) . ')';
  }

  foreach ($esquema[$tabla]['campos'] as $campo => $def) {
    if ($def['tipo'] === 'relacion' && isset($_GET[$campo])) {
      $where[] = "t.$campo = :f_$campo";
      $parametros["f_$campo"] = (int)$_GET[$campo];
    }
  }

  $fecha = campoFecha($esquema[$tabla]);
  foreach (['desde' => '>=', 'hasta' => '<='] as $parametro => $operador) {
    $valor = $_GET[$parametro] ?? '';
    if ($fecha && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
      $where[] = "t.$fecha $operador :$parametro";
      $parametros[$parametro] = $valor;
    }
  }

  // Solo se ordena por columnas de la lista blanca; el nombre nunca llega tal cual al SQL.
  $orden = $ordenables[$_GET['orden'] ?? 'id'] ?? 't.id';
  $direccion = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

  return [($where ? ' WHERE ' . implode(' AND ', $where) : ''), $parametros, " ORDER BY $orden $direccion, t.id $direccion"];
}

function listar(PDO $pdo, array $config, array $esquema, string $tabla): array {
  [$sql, $buscables, $ordenables] = consultaBase($esquema, $tabla);
  [$where, $parametros, $orden] = condiciones($esquema, $tabla, $buscables, $ordenables);
  $modulo = $esquema[$tabla];

  if (!isset($_GET['pagina'])) {
    $consulta = $pdo->prepare($sql . $where . $orden);
    $consulta->execute($parametros);
    return conLineas($pdo, $modulo, array_map(fn($f) => normalizar($modulo, $f), $consulta->fetchAll()));
  }

  $contar = $pdo->prepare("SELECT COUNT(*) FROM ($sql$where) sub");
  $contar->execute($parametros);
  $total = (int)$contar->fetchColumn();

  $por = (int)$config['por_pagina'];
  $paginas = max(1, (int)ceil($total / $por));
  $pagina = min(max(1, (int)$_GET['pagina']), $paginas);
  $desde = ($pagina - 1) * $por;

  // LIMIT con enteros ya validados: con prepares emulados (MySQL) un parámetro se enviaría entre comillas.
  $inicio = hrtime(true);
  $consulta = $pdo->prepare($sql . $where . $orden . " LIMIT $por OFFSET $desde");
  $consulta->execute($parametros);
  if (function_exists('anotarLenta')) anotarLenta($pdo, 'listado', $modulo['titulo'], $sql . $where . $orden . " LIMIT $por OFFSET $desde", $parametros, (hrtime(true) - $inicio) / 1e6, $total);

  return [
    'filas'   => conLineas($pdo, $modulo, array_map(fn($f) => normalizar($modulo, $f), $consulta->fetchAll())),
    'total'   => $total,
    'pagina'  => $pagina,
    'paginas' => $paginas,
    'por'     => $por,
  ];
}

function obtener(PDO $pdo, array $esquema, string $tabla, int $id): array {
  [$sql] = consultaBase($esquema, $tabla);
  $consulta = $pdo->prepare("$sql WHERE t.id = ?");
  $consulta->execute([$id]);
  $fila = $consulta->fetch();
  if (!$fila) fallar(ucfirst($esquema[$tabla]['singular']) . " #$id no encontrad" . (empty($esquema[$tabla]['femenino']) ? 'o' : 'a'), 404);
  return conLineas($pdo, $esquema[$tabla], [normalizar($esquema[$tabla], $fila)])[0];
}

// ---------- Líneas ----------

// Añade a cada fila sus líneas (una sola consulta para toda la página).
function conLineas(PDO $pdo, array $modulo, array $filas): array {
  if (empty($modulo['lineas']) || !$filas) return $filas;
  ['tabla' => $tabla, 'clave' => $clave, 'campos' => $campos] = $modulo['lineas'];

  $select = ['l.*'];
  $joins = [];
  foreach ($campos as $campo => $def) {
    if ($def['tipo'] !== 'relacion') continue;
    $joins[] = "LEFT JOIN {$def['tabla']} r_$campo ON r_$campo.id = l.$campo";
    $select[] = "r_$campo.{$def['mostrar']} AS " . nombreRelacion($campo);
  }
  $ids = array_column($filas, 'id');
  $huecos = implode(', ', array_fill(0, count($ids), '?'));
  $consulta = $pdo->prepare('SELECT ' . implode(', ', $select) . " FROM $tabla l " . implode(' ', $joins) . " WHERE l.$clave IN ($huecos) ORDER BY l.id");
  $consulta->execute($ids);

  $porPadre = [];
  foreach ($consulta->fetchAll() as $linea) {
    $linea = normalizar(['campos' => $campos], $linea);
    $padre = (int)$linea[$clave];
    unset($linea[$clave], $linea['creado']);
    $porPadre[$padre][] = $linea;
  }
  foreach ($filas as &$fila) $fila['lineas'] = $porPadre[$fila['id']] ?? [];
  return $filas;
}

function guardarLineas(PDO $pdo, array $modulo, int $id, array $lineas): void {
  if (empty($modulo['lineas'])) return;
  ['tabla' => $tabla, 'clave' => $clave] = $modulo['lineas'];
  $pdo->prepare("DELETE FROM $tabla WHERE $clave = ?")->execute([$id]);
  foreach ($lineas as $linea) {
    $columnas = array_keys($linea);
    $pdo->prepare("INSERT INTO $tabla ($clave, " . implode(', ', $columnas) . ') VALUES (?' . str_repeat(', ?', count($columnas)) . ')')
        ->execute([$id, ...array_values($linea)]);
  }
}

// «2 × Portátil ProBook 15, 1 × Hub USB-C 8 en 1»
function resumenLineas(array $lineas): string {
  return implode(', ', array_map(fn($l) => "{$l['cantidad']} × " . ($l['producto'] ?? $l['concepto'] ?? ''), $lineas));
}

// ---------- Validación ----------

// SQLite y MySQL devuelven los números como texto: los convertimos para que el JSON tenga tipos reales.
// Las contraseñas (aunque estén cifradas) nunca salen de la API.
function normalizar(array $modulo, array $fila): array {
  $fila['id'] = (int)$fila['id'];
  $tipos = array_merge(
    array_map(fn($d) => $d['tipo'], $modulo['campos']),
    array_map(fn($d) => $d['tipo'], $modulo['calculados'] ?? [])
  );
  foreach ($tipos as $campo => $tipo) {
    if ($tipo === 'clave') { unset($fila[$campo]); continue; }
    if ($tipo === 'permisos') { $fila[$campo] = isset($fila[$campo]) ? json_decode((string)$fila[$campo], true) : null; continue; }
    if (!isset($fila[$campo])) continue;
    if ($tipo === 'moneda') $fila[$campo] = round((float)$fila[$campo], 2);
    if ($tipo === 'entero' || $tipo === 'relacion') $fila[$campo] = (int)$fila[$campo];
  }
  return $fila;
}

// Valida el registro y sus líneas. Si hay errores responde 422 con todos a la vez.
function validarRegistro(PDO $pdo, array $modulo, array $entrada, bool $editando): array {
  [$datos, $errores] = validarCampos($pdo, $modulo['campos'], $entrada, $editando);
  $lineas = [];

  if (!empty($modulo['lineas'])) {
    $recibidas = $entrada['lineas'] ?? [];
    if (!is_array($recibidas) || !$recibidas) {
      $errores['lineas'] = 'Añade al menos una línea';
    } else {
      foreach (array_values($recibidas) as $i => $linea) {
        [$limpia, $erroresLinea] = validarCampos($pdo, $modulo['lineas']['campos'], is_array($linea) ? $linea : [], false, "lineas.$i.");
        $lineas[] = $limpia;
        $errores += $erroresLinea;
      }
    }
  }

  if ($errores) fallar('Revisa los campos marcados', 422, $errores);
  return [$datos, $lineas];
}

// Comprueba y limpia los campos según el esquema. Devuelve [datos, errores].
function validarCampos(PDO $pdo, array $campos, array $entrada, bool $editando, string $prefijo = ''): array {
  $limpio = [];
  $errores = [];

  foreach ($campos as $campo => $def) {
    if (!empty($def['auto'])) continue;
    $error = function (string $mensaje) use (&$errores, $prefijo, $campo) { $errores[$prefijo . $campo] = $mensaje; };

    $valor = $entrada[$campo] ?? null;
    if (is_string($valor)) $valor = trim($valor);

    if ($valor === null || $valor === '') {
      if ($def['tipo'] === 'clave' && $editando) continue; // vacía al editar = no cambiarla
      if (array_key_exists('defecto', $def)) {
        $limpio[$campo] = $def['defecto'] === 'hoy' ? date('Y-m-d') : $def['defecto'];
      } elseif (!empty($def['obligatorio'])) {
        $error('Este campo es obligatorio');
      } else {
        $limpio[$campo] = null;
      }
      continue;
    }

    // Permisos por módulo: {"ventas": ["ver", "crear"], …} (como texto JSON o como objeto)
    if ($def['tipo'] === 'permisos') {
      $permisos = is_string($valor) ? json_decode($valor, true) : $valor;
      $modulos = array_diff(array_keys($GLOBALS['catalogo'] ?? []), ['nucleo']);
      $limpios = [];
      if (!is_array($permisos)) $error('Formato de permisos no válido');
      else foreach ($permisos as $modulo => $acciones) {
        if (!in_array($modulo, $modulos, true) || !is_array($acciones) || array_diff($acciones, ['ver', 'crear', 'editar', 'borrar'])) {
          $error("Permisos no válidos para «{$modulo}»");
          break;
        }
        // Quien puede crear, editar o borrar también tiene que poder ver
        if ($acciones) $limpios[$modulo] = array_values(array_unique(array_merge(['ver'], $acciones)));
      }
      $limpio[$campo] = json_encode((object)$limpios);
      continue;
    }

    if (!is_scalar($valor)) {
      $error('Valor no válido');
      continue;
    }

    switch ($def['tipo']) {
      case 'email':
        if (!filter_var($valor, FILTER_VALIDATE_EMAIL)) $error('Email no válido');
        break;

      case 'clave':
        if (mb_strlen((string)$valor) < 8) $error('Mínimo 8 caracteres');
        else $valor = password_hash((string)$valor, PASSWORD_DEFAULT);
        break;

      case 'moneda':
        if (!is_numeric($valor) || (float)$valor < 0) $error('Debe ser un importe positivo');
        else $valor = round((float)$valor, 2);
        break;

      case 'entero':
        $minimo = $def['minimo'] ?? PHP_INT_MIN;
        if (filter_var($valor, FILTER_VALIDATE_INT) === false || (int)$valor < $minimo) {
          $error('Debe ser un número entero' . (isset($def['minimo']) ? " mayor o igual que $minimo" : ''));
        } else {
          $valor = (int)$valor;
        }
        break;

      case 'nif':
        $valor = normalizarNif((string)$valor);
        if (!nifValido($valor)) $error('NIF, NIE o CIF no válido (revisa la letra o el dígito de control)');
        break;

      case 'iban':
        $valor = normalizarIban((string)$valor);
        if (!ibanValido($valor)) $error('IBAN no válido (revisa los dígitos de control)');
        break;

      case 'cp':
        $valor = trim((string)$valor);
        if (!provinciaDeCp($valor)) $error('Código postal no válido (5 cifras, de 01000 a 52999)');
        break;

      case 'tel':
        $valor = trim((string)$valor);
        if (!telefonoValido($valor)) $error('Teléfono no válido (9 cifras, o con prefijo internacional: +44…)');
        break;

      case 'largo':
        $valor = (string)$valor;
        if (mb_strlen($valor) > 5000) $error('Máximo 5000 caracteres');
        break;

      case 'fecha':
        $fecha = DateTime::createFromFormat('!Y-m-d', (string)$valor);
        if (!$fecha || $fecha->format('Y-m-d') !== $valor) $error('Fecha no válida (aaaa-mm-dd)');
        break;

      case 'opcion':
        if (!in_array($valor, $def['opciones'], true)) $error('Opción no válida');
        break;

      case 'relacion':
        $existe = $pdo->prepare("SELECT 1 FROM {$def['tabla']} WHERE id = ?");
        $existe->execute([(int)$valor]);
        if (!$existe->fetchColumn()) $error('No existe el registro seleccionado');
        else $valor = (int)$valor;
        break;

      default:
        $valor = (string)$valor;
        if (mb_strlen($valor) > 255) $error('Máximo 255 caracteres');
    }

    $limpio[$campo] = $valor;
  }

  // Campos calculados a partir de otros: la provincia sale del código postal
  foreach ($campos as $campo => $def) {
    if (isset($def['desde_cp']) && array_key_exists($def['desde_cp'], $limpio)) $limpio[$campo] = provinciaDeCp($limpio[$def['desde_cp']]);
  }

  return [$limpio, $errores];
}

// ---------- Exportación ----------

// Descarga CSV con separador «;» y BOM UTF-8 para que Excel en español lo abra bien.
function exportarCsv(PDO $pdo, array $esquema, string $tabla): never {
  $modulo = $esquema[$tabla];
  [$sql, $buscables, $ordenables] = consultaBase($esquema, $tabla);
  [$where, $parametros, $orden] = condiciones($esquema, $tabla, $buscables, $ordenables);
  $consulta = $pdo->prepare($sql . $where . $orden);
  $consulta->execute($parametros);
  $filas = conLineas($pdo, $modulo, array_map(fn($f) => normalizar($modulo, $f), $consulta->fetchAll()));

  $columnas = ['id' => 'Nº'];
  foreach ($modulo['campos'] as $campo => $def) {
    if (in_array($def['tipo'], ['clave', 'permisos'], true)) continue;
    $columnas[$def['tipo'] === 'relacion' ? nombreRelacion($campo) : $campo] = $def['etiqueta'];
  }
  if (!empty($modulo['lineas'])) $columnas['lineas'] = $modulo['lineas']['etiqueta'];
  foreach ($modulo['calculados'] ?? [] as $campo => $def) {
    if (empty($def['oculto'])) $columnas[$campo] = $def['etiqueta'];
  }

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $tabla . '-' . date('Y-m-d') . '.csv"');

  $salida = fopen('php://output', 'w');
  fwrite($salida, "\xEF\xBB\xBF");
  fputcsv($salida, array_values($columnas), ';', '"', '');
  foreach ($filas as $fila) {
    fputcsv($salida, array_map(function ($clave) use ($fila) {
      $valor = $clave === 'lineas' ? resumenLineas($fila['lineas']) : (string)($fila[$clave] ?? '');
      if (is_numeric($valor) && str_contains($valor, '.')) $valor = str_replace('.', ',', $valor); // decimales a la española
      // Evita la «inyección de fórmulas»: una celda que empieza por = + - @ se trata como texto.
      return preg_match('/^[=+\-@\t\r]/', $valor) && !is_numeric($valor) ? "'$valor" : $valor;
    }, array_keys($columnas)), ';', '"', '');
  }
  fclose($salida);
  exit;
}

// Descarga JSON con los mismos filtros que el listado (para otros programas o para volver a importar)
function exportarJson(PDO $pdo, array $config, array $esquema, string $tabla): never {
  unset($_GET['pagina']);
  $filas = listar($pdo, $config, $esquema, $tabla);
  header('Content-Disposition: attachment; filename="' . $tabla . '-' . date('Y-m-d') . '.json"');
  echo json_encode(['recurso' => $tabla, 'exportado' => date('c'), 'total' => count($filas), 'filas' => $filas],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Listado con los filtros de la petición como {columnas, filas} (para el Excel)
function resultadoListado(PDO $pdo, array $esquema, string $tabla): array {
  $modulo = $esquema[$tabla];
  [$sql, $buscables, $ordenables] = consultaBase($esquema, $tabla);
  [$where, $parametros, $orden] = condiciones($esquema, $tabla, $buscables, $ordenables);
  $consulta = $pdo->prepare($sql . $where . $orden);
  $consulta->execute($parametros);
  $filas = conLineas($pdo, $modulo, array_map(fn($f) => normalizar($modulo, $f), $consulta->fetchAll()));
  $columnas = [['clave' => 'id', 'etiqueta' => 'Nº', 'tipo' => 'entero']];
  foreach ($modulo['campos'] as $campo => $def) {
    if (in_array($def['tipo'], ['clave', 'permisos'], true)) continue;
    $columnas[] = ['clave' => $def['tipo'] === 'relacion' ? nombreRelacion($campo) : $campo, 'etiqueta' => $def['etiqueta'],
      'tipo' => in_array($def['tipo'], ['moneda', 'entero', 'fecha'], true) ? $def['tipo'] : 'texto'];
  }
  if (!empty($modulo['lineas'])) $columnas[] = ['clave' => 'lineas', 'etiqueta' => $modulo['lineas']['etiqueta'], 'tipo' => 'texto'];
  foreach ($modulo['calculados'] ?? [] as $campo => $def) {
    if (empty($def['oculto'])) $columnas[] = ['clave' => $campo, 'etiqueta' => $def['etiqueta'], 'tipo' => in_array($def['tipo'], ['moneda', 'entero'], true) ? $def['tipo'] : 'texto'];
  }
  if (!empty($modulo['lineas'])) $filas = array_map(fn($f) => ['lineas' => resumenLineas($f['lineas'])] + $f, $filas);
  return ['columnas' => $columnas, 'filas' => $filas];
}

// ---------- Textos para el registro ----------

// «Cliente «Lucía Martín»» o «Pedido #12».
function describir(array $modulo, array $fila): string {
  $campo = $modulo['principal'];
  $nombre = ucfirst($modulo['singular']);
  return $campo === 'id' ? "$nombre #{$fila['id']}" : "$nombre «{$fila[$campo]}»";
}

function articulo(array $modulo): string {
  return empty($modulo['femenino']) ? 'este' : 'esta';
}

// Lista de campos cambiados en una edición: « · estado: potencial → activo».
function cambios(array $modulo, array $antes, array $despues, array $enviados): string {
  $partes = [];
  foreach ($modulo['campos'] as $campo => $def) {
    if ($def['tipo'] === 'clave') {
      if (isset($enviados[$campo])) $partes[] = 'contraseña cambiada';
      continue;
    }
    if ($def['tipo'] === 'permisos') {
      if (json_encode($antes[$campo] ?? null) !== json_encode($despues[$campo] ?? null)) $partes[] = 'permisos por módulo cambiados';
      continue;
    }
    if ($def['tipo'] === 'largo') {
      if (($antes[$campo] ?? '') !== ($despues[$campo] ?? '')) $partes[] = mb_strtolower($def['etiqueta']) . ' modificadas';
      continue;
    }
    $clave = $def['tipo'] === 'relacion' ? nombreRelacion($campo) : $campo;
    $de = (string)($antes[$clave] ?? '');
    $a  = (string)($despues[$clave] ?? '');
    if ($de !== $a) {
      $partes[] = mb_strtolower($def['etiqueta']) . ': ' . ($de === '' ? '—' : $de) . ' → ' . ($a === '' ? '—' : $a);
    }
  }
  if (!empty($modulo['lineas']) && resumenLineas($antes['lineas']) !== resumenLineas($despues['lineas'])) {
    $partes[] = mb_strtolower($modulo['lineas']['etiqueta']) . ': ' . resumenLineas($despues['lineas']);
  }
  return $partes ? ' · ' . implode(', ', $partes) : ' · sin cambios';
}

function leerCuerpo(): array {
  $cuerpo = json_decode(file_get_contents('php://input') ?: '', true);
  if (!is_array($cuerpo)) fallar('El cuerpo de la petición debe ser un objeto JSON', 400);
  return $cuerpo;
}

function exigirId(?int $id): void {
  if ($id === null || $id < 1) fallar('Falta el parámetro id', 400);
}
