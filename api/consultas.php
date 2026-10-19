<?php
// Consultas (RA3, criterio a): herramientas de consulta sobre los datos del ERP.
//
// · Constructor: se eligen recurso, columnas, filtros, agrupación, totales y orden; el ERP
//   genera el SQL (que se muestra) solo con columnas de una lista blanca y valores parametrizados.
// · Consola SQL (solo administración): SELECT de solo lectura, una sentencia, sin tablas sensibles.
// · Consultas guardadas para repetirlas o exportarlas.
// · Tabla cruzada (v5.1): agrupar por dos campos a la vez, por ejemplo clientes × meses.
// · «Explicar» (v5.1): cómo ejecuta la base de datos una consulta (EXPLAIN), para ver si usa índices.

const OPERADORES = [
  'igual' => '=', 'distinto' => '<>', 'mayor' => '>', 'menor' => '<', 'mayor_igual' => '>=', 'menor_igual' => '<=',
  'contiene' => 'LIKE', 'empieza' => 'LIKE', 'vacio' => 'IS NULL', 'no_vacio' => 'IS NOT NULL',
];
const AGREGACIONES = ['contar' => 'COUNT', 'suma' => 'SUM', 'media' => 'AVG', 'minimo' => 'MIN', 'maximo' => 'MAX'];
const LIMITE_FILAS = 1000;

// Campos que se pueden usar en el constructor para un recurso: los mismos que se pueden ordenar
function camposConsulta(array $esquema, string $recurso): array {
  [, , $ordenables] = consultaBase($esquema, $recurso);
  $modulo = $esquema[$recurso];
  $campos = ['id' => ['etiqueta' => 'Nº', 'tipo' => 'entero', 'sql' => 't.id']];
  foreach ($ordenables as $clave => $sql) {
    if ($clave === 'id') continue;
    $def = $modulo['campos'][$clave] ?? $modulo['campos'][$clave . '_id'] ?? $modulo['calculados'][$clave] ?? null;
    if (!$def || in_array($def['tipo'], ['clave', 'permisos'], true) || !empty($def['oculto'])) continue;
    $campos[$clave] = ['etiqueta' => $def['etiqueta'], 'tipo' => $def['tipo'] === 'relacion' ? 'texto' : $def['tipo'], 'sql' => $sql];
    // Las fechas también se pueden agrupar por mes o por año (SUBSTR funciona igual en SQLite y MySQL)
    if ($def['tipo'] === 'fecha') {
      $campos[$clave . '__mes'] = ['etiqueta' => $def['etiqueta'] . ' (mes)', 'tipo' => 'texto', 'sql' => "SUBSTR($sql, 1, 7)"];
      $campos[$clave . '__ano'] = ['etiqueta' => $def['etiqueta'] . ' (año)', 'tipo' => 'texto', 'sql' => "SUBSTR($sql, 1, 4)"];
    }
  }
  return $campos;
}

// Recursos consultables por el usuario, con sus campos (para el formulario del constructor)
function recursosConsultables(array $esquema, array $usuario): array {
  $lista = [];
  foreach ($esquema as $id => $modulo) {
    if ($id === 'usuarios' || !puede($esquema, $usuario, 'ver', $id)) continue;
    $lista[] = ['id' => $id, 'titulo' => $modulo['titulo'],
      'campos' => array_map(fn($k, $c) => ['clave' => $k, 'etiqueta' => $c['etiqueta'], 'tipo' => $c['tipo']],
        array_keys(camposConsulta($esquema, $id)), camposConsulta($esquema, $id))];
  }
  return $lista;
}

// Traduce la definición del constructor a SQL parametrizado
function construirConsulta(array $esquema, array $usuario, array $def): array {
  $recurso = (string)($def['recurso'] ?? '');
  if (!isset($esquema[$recurso]) || $recurso === 'usuarios') fallar('Elige qué datos consultar', 422, ['recurso' => 'Elige qué datos consultar']);
  exigirPermiso($esquema, $usuario, 'ver', $recurso);
  $campos = camposConsulta($esquema, $recurso);
  [, , , $desde] = consultaBase($esquema, $recurso);   // FROM tabla t LEFT JOIN …
  $campo = fn($c) => $campos[$c] ?? fallar("Campo desconocido: $c", 422, ['campos' => "Campo desconocido: $c"]);

  $where = [];
  $valores = [];
  foreach (array_slice((array)($def['filtros'] ?? []), 0, 10) as $i => $f) {
    $c = $campo((string)($f['campo'] ?? ''));
    $op = (string)($f['operador'] ?? '');
    if (!isset(OPERADORES[$op])) fallar('Operador no válido', 422, ["filtros.$i" => 'Operador no válido']);
    if (in_array($op, ['vacio', 'no_vacio'], true)) { $where[] = "{$c['sql']} " . OPERADORES[$op]; continue; }
    $valor = trim((string)($f['valor'] ?? ''));
    if ($valor === '') fallar('Falta el valor de un filtro', 422, ["filtros.$i" => 'Escribe un valor']);
    $where[] = "{$c['sql']} " . OPERADORES[$op] . ' ?';
    $valores[] = match ($op) { 'contiene' => "%$valor%", 'empieza' => "$valor%", default => $valor };
  }

  $agrupar = (string)($def['agrupar'] ?? '');
  $agregacion = (string)($def['agregacion'] ?? '');
  $sobre = (string)($def['sobre'] ?? 'id');
  $cruzar = $agrupar !== '' ? (string)($def['cruzar'] ?? '') : '';

  if ($cruzar !== '') {
    // Tabla cruzada: se agrupa por los dos campos y se da la vuelta en ejecutarConstructor()
    if ($cruzar === $agrupar) fallar('Elige dos campos distintos para la tabla cruzada', 422, ['cruzar' => 'Debe ser distinto de «Agrupar por»']);
    $g = $campo($agrupar);
    $x = $campo($cruzar);
    if (!isset(AGREGACIONES[$agregacion])) $agregacion = 'contar';
    $s = $campo($sobre);
    $sql = "SELECT {$g['sql']} AS fila, {$x['sql']} AS columna, " . AGREGACIONES[$agregacion] . "({$s['sql']}) AS valor$desde"
      . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " GROUP BY {$g['sql']}, {$x['sql']} LIMIT 20000";
    return [$sql, $valores, [
      'cruce' => true, 'fila' => ['clave' => $agrupar, 'etiqueta' => $g['etiqueta'], 'tipo' => $g['tipo']],
      'columna' => $x['etiqueta'], 'agregacion' => $agregacion,
      'tipo' => $agregacion === 'contar' ? 'entero' : ($s['tipo'] === 'moneda' ? 'moneda' : 'decimal'),
      'limite' => max(1, min(LIMITE_FILAS, (int)($def['limite'] ?? 100))),
    ]];
  }

  if ($agrupar !== '') {
    $g = $campo($agrupar);
    if (!isset(AGREGACIONES[$agregacion])) $agregacion = 'contar';
    $s = $campo($sobre);
    $nombreTotal = AGREGACIONES[$agregacion] === 'COUNT' ? 'registros' : strtolower($agregacion) . '_' . $sobre;
    $select = "{$g['sql']} AS $agrupar, " . AGREGACIONES[$agregacion] . "({$s['sql']}) AS $nombreTotal";
    $columnas = [
      ['clave' => $agrupar, 'etiqueta' => $g['etiqueta'], 'tipo' => $g['tipo']],
      ['clave' => $nombreTotal, 'etiqueta' => ['contar' => 'Registros', 'suma' => 'Suma de', 'media' => 'Media de', 'minimo' => 'Mínimo de', 'maximo' => 'Máximo de'][$agregacion] . ($agregacion === 'contar' ? '' : ' ' . mb_strtolower($s['etiqueta'])),
       'tipo' => $agregacion === 'contar' ? 'entero' : ($s['tipo'] === 'moneda' ? 'moneda' : 'decimal')],
    ];
    $grupo = " GROUP BY {$g['sql']}";
    $ordenPor = $nombreTotal;
  } else {
    $elegidas = array_values(array_unique(array_map('strval', (array)($def['columnas'] ?? []))));
    if (!$elegidas) $elegidas = array_slice(array_keys($campos), 0, 6);
    $select = implode(', ', array_map(fn($c) => $campo($c)['sql'] . " AS $c", $elegidas));
    $columnas = array_map(fn($c) => ['clave' => $c, 'etiqueta' => $campos[$c]['etiqueta'], 'tipo' => $campos[$c]['tipo']], $elegidas);
    $grupo = '';
    $ordenPor = null;
  }

  $orden = (string)($def['orden'] ?? '');
  $dir = strtolower((string)($def['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
  $ordenSql = $orden !== '' && in_array($orden, array_column($columnas, 'clave'), true) ? " ORDER BY $orden $dir"
    : ($ordenPor ? " ORDER BY $ordenPor DESC" : ' ORDER BY t.id DESC');
  $limite = max(1, min(LIMITE_FILAS, (int)($def['limite'] ?? 100)));

  $sql = "SELECT $select$desde" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "$grupo$ordenSql LIMIT $limite";
  return [$sql, $valores, $columnas];
}

function ejecutarConstructor(PDO $pdo, array $esquema, array $usuario, array $def, bool $explicar = false): array {
  [$sql, $valores, $columnas] = construirConsulta($esquema, $usuario, $def);
  // SQL «legible»: los valores en su sitio, solo para mostrarlo (se ejecuta parametrizado)
  $legible = $sql;
  foreach ($valores as $v) $legible = preg_replace('/\?/', $pdo->quote((string)$v), $legible, 1);
  if ($explicar) return explicarConsulta($pdo, $sql, $valores) + ['sql' => $legible];

  $inicio = hrtime(true);
  $consulta = $pdo->prepare($sql);
  $consulta->execute($valores);
  $filas = $consulta->fetchAll();
  $ms = round((hrtime(true) - $inicio) / 1e6, 1);
  anotarLenta($pdo, 'constructor', $esquema[$def['recurso']]['titulo'], $sql, $valores, $ms, count($filas));
  if (!empty($columnas['cruce'])) return cruzarFilas($columnas, $filas) + ['sql' => $legible, 'ms' => $ms, 'limite' => LIMITE_FILAS];
  return ['columnas' => $columnas, 'filas' => normalizarFilas($columnas, $filas), 'sql' => $legible, 'ms' => $ms, 'limite' => LIMITE_FILAS];
}

// Da la vuelta a las filas (fila, columna, valor): una columna por cada valor distinto (como mucho 24) y un total
function cruzarFilas(array $def, array $filas): array {
  $valoresColumna = array_values(array_unique(array_map(fn($f) => (string)($f['columna'] ?? ''), $filas)));
  sort($valoresColumna, SORT_NATURAL);
  $recortadas = count($valoresColumna) > 24;
  $valoresColumna = array_slice($valoresColumna, 0, 24);
  $indice = array_flip($valoresColumna);
  $sumable = in_array($def['agregacion'], ['contar', 'suma'], true);
  $tabla = [];
  foreach ($filas as $f) {
    $clave = (string)($f['fila'] ?? '');
    $tabla[$clave] ??= [$def['fila']['clave'] => $f['fila']];
    $columna = (string)($f['columna'] ?? '');
    if (!isset($indice[$columna])) continue;
    $valor = $def['tipo'] === 'entero' ? (int)$f['valor'] : round((float)$f['valor'], 2);
    $tabla[$clave]['c' . $indice[$columna]] = $valor;
    if ($sumable) $tabla[$clave]['total'] = round(($tabla[$clave]['total'] ?? 0) + $valor, 2);
  }
  $tabla = array_values($tabla);
  if ($sumable) usort($tabla, fn($a, $b) => ($b['total'] ?? 0) <=> ($a['total'] ?? 0));
  $tabla = array_slice($tabla, 0, $def['limite']);
  $columnas = [$def['fila']];
  foreach ($valoresColumna as $i => $v) $columnas[] = ['clave' => "c$i", 'etiqueta' => $v === '' ? '(vacío)' : $v, 'tipo' => $def['tipo']];
  if ($sumable) $columnas[] = ['clave' => 'total', 'etiqueta' => 'Total', 'tipo' => $def['tipo']];
  $totales = [];
  if ($sumable) foreach (array_slice($columnas, 1) as $c) $totales[$c['clave']] = round(array_sum(array_map(fn($f) => (float)($f[$c['clave']] ?? 0), $tabla)), 2);
  return ['columnas' => $columnas, 'filas' => $tabla, 'totales' => $totales, 'cruce' => ['columna' => $def['columna'], 'recortada' => $recortadas]];
}

// Plan de ejecución: SQLite (EXPLAIN QUERY PLAN) y MySQL (EXPLAIN) lo dan en columnas distintas
function explicarConsulta(PDO $pdo, string $sql, array $valores = []): array {
  $consulta = $pdo->prepare((esMysql($pdo) ? 'EXPLAIN ' : 'EXPLAIN QUERY PLAN ') . $sql);
  $consulta->execute($valores);
  $filas = $consulta->fetchAll(PDO::FETCH_ASSOC);
  $claves = $filas ? array_keys($filas[0]) : [];
  $usaIndice = (bool)array_filter($filas, fn($f) => preg_match('/USING (COVERING )?INDEX|SEARCH/i', implode(' ', array_map('strval', $f)))
    || !empty($f['key']));
  $recorre = array_values(array_filter(array_map(fn($f) => preg_match('/^SCAN (\w+)/i', (string)($f['detail'] ?? ''), $m) ? $m[1]
    : (($f['type'] ?? '') === 'ALL' ? ($f['table'] ?? null) : null), $filas)));
  return [
    'plan' => true,
    'columnas' => array_map(fn($c) => ['clave' => $c, 'etiqueta' => $c, 'tipo' => 'texto'], $claves),
    'filas' => $filas, 'usa_indice' => $usaIndice, 'recorre' => array_values(array_unique($recorre)),
  ];
}

function normalizarFilas(array $columnas, array $filas): array {
  return array_map(function ($f) use ($columnas) {
    foreach ($columnas as $c) {
      $v = $f[$c['clave']] ?? null;
      if ($v === null) continue;
      if (in_array($c['tipo'], ['moneda', 'decimal'], true)) $f[$c['clave']] = round((float)$v, 2);
      elseif ($c['tipo'] === 'entero') $f[$c['clave']] = (int)$v;
    }
    return $f;
  }, $filas);
}

// ---------- Consola SQL (solo administración) ----------

const TABLAS_PROTEGIDAS = ['usuarios', 'claves_api', 'sqlite_master', 'sqlite_schema', 'information_schema', 'mysql', 'performance_schema'];

function ejecutarSql(array $config, array $esquema, string $sql, ?PDO $registro = null, bool $explicar = false): array {
  $sql = trim($sql);
  $sql = rtrim($sql, "; \n\t");
  if ($sql === '') fallar('Escribe una consulta', 422, ['sql' => 'Escribe una consulta']);
  if (mb_strlen($sql) > 5000) fallar('Consulta demasiado larga', 422, ['sql' => 'Máximo 5000 caracteres']);
  // Una sola sentencia SELECT (o WITH … SELECT), sin comentarios que puedan ocultar otra
  if (str_contains($sql, ';') || preg_match('/--|\/\*/', $sql)) fallar('Solo una sentencia, sin «;» ni comentarios', 422, ['sql' => 'Solo una sentencia, sin «;» ni comentarios']);
  if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) fallar('Solo se permiten consultas SELECT', 422, ['sql' => 'La consola es de solo lectura: empieza por SELECT']);
  if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|REPLACE|ATTACH|DETACH|PRAGMA|VACUUM|GRANT|LOAD_FILE|OUTFILE|DUMPFILE)\b/i', $sql)) {
    fallar('La consulta contiene una orden no permitida', 422, ['sql' => 'Solo lectura: no se permiten órdenes que modifiquen datos']);
  }
  foreach (TABLAS_PROTEGIDAS as $tabla) {
    if (preg_match('/\b' . preg_quote($tabla, '/') . '\b/i', $sql)) fallar("La tabla «{$tabla}» no se puede consultar desde aquí", 422, ['sql' => "La tabla «{$tabla}» está protegida (contraseñas y claves)"]);
  }

  // Conexión aparte, en modo solo lectura del propio motor (segunda barrera además de las comprobaciones)
  $pdo = conectar($config, $esquema);
  if (esMysql($pdo)) {
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
  } else {
    $pdo->exec('PRAGMA query_only = ON');
  }
  if ($explicar) {
    try {
      return explicarConsulta($pdo, $sql) + ['sql' => $sql];
    } catch (PDOException $e) {
      fallar('Error en la consulta', 422, ['sql' => preg_replace('/^SQLSTATE\[\w+\]: [^:]+: \d* ?/', '', $e->getMessage())]);
    } finally {
      if ($pdo->inTransaction()) $pdo->rollBack();
    }
  }
  $inicio = hrtime(true);
  try {
    $consulta = $pdo->query($sql);
    $filas = [];
    while (count($filas) < LIMITE_FILAS && ($fila = $consulta->fetch(PDO::FETCH_ASSOC)) !== false) $filas[] = $fila;
    $hayMas = $consulta->fetch() !== false;
    $consulta->closeCursor();
  } catch (PDOException $e) {
    fallar('Error en la consulta', 422, ['sql' => preg_replace('/^SQLSTATE\[\w+\]: [^:]+: \d* ?/', '', $e->getMessage())]);
  } finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
  }
  if ($registro) anotarLenta($registro, 'consola', 'Consola SQL', $sql, [], (hrtime(true) - $inicio) / 1e6, count($filas));
  $claves = $filas ? array_keys($filas[0]) : [];
  // Tipo de cada columna según sus valores: números (se alinean y se exportan con coma decimal), fechas o texto
  $tipo = function (string $c) use ($filas) {
    $valores = array_filter(array_column($filas, $c), fn($v) => $v !== null && $v !== '');
    if (!$valores) return 'texto';
    if (!array_filter($valores, fn($v) => !is_numeric($v))) return array_filter($valores, fn($v) => !preg_match('/^-?\d+$/', (string)$v)) ? 'decimal' : 'entero';
    if (!array_filter($valores, fn($v) => !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v))) return 'fecha';
    return 'texto';
  };
  $columnas = array_map(fn($c) => ['clave' => $c, 'etiqueta' => $c, 'tipo' => $tipo($c)], $claves);
  return [
    'columnas' => $columnas,
    // Números como números (sin redondear: es la consola, se ve el dato tal cual)
    'filas' => array_map(fn($f) => array_map(fn($v) => is_string($v) && is_numeric($v) && !preg_match('/^0\d/', $v) ? $v + 0 : $v, $f), $filas), 'sql' => $sql, 'ms' => round((hrtime(true) - $inicio) / 1e6, 1),
    'recortado' => $hayMas, 'limite' => LIMITE_FILAS,
  ];
}

// ---------- Consultas guardadas ----------

function listarConsultas(PDO $pdo, array $usuario): array {
  $filas = $pdo->query('SELECT * FROM consultas ORDER BY nombre')->fetchAll();
  return array_values(array_filter(array_map(fn($f) => [
    'id' => (int)$f['id'], 'nombre' => $f['nombre'], 'tipo' => $f['tipo'],
    'definicion' => json_decode($f['definicion'], true), 'usuario' => $f['usuario'], 'creado' => $f['creado'],
  ], $filas), fn($c) => $c['tipo'] !== 'sql' || $usuario['rol'] === 'admin'));
}

function guardarConsulta(PDO $pdo, array $usuario, array $entrada): array {
  $nombre = trim((string)($entrada['nombre'] ?? ''));
  $tipo = ($entrada['tipo'] ?? '') === 'sql' ? 'sql' : 'constructor';
  if ($nombre === '' || mb_strlen($nombre) > 100) fallar('Revisa los campos marcados', 422, ['nombre' => 'Ponle un nombre (máximo 100 caracteres)']);
  if ($tipo === 'sql' && $usuario['rol'] !== 'admin') fallar('Solo administración puede guardar consultas SQL', 403);
  $definicion = $entrada['definicion'] ?? null;
  if (!is_array($definicion)) fallar('Consulta vacía', 422);
  $pdo->prepare('INSERT INTO consultas (nombre, tipo, definicion, usuario) VALUES (?, ?, ?, ?)')
      ->execute([$nombre, $tipo, json_encode($definicion, JSON_UNESCAPED_UNICODE), $usuario['nombre']]);
  $id = (int)$pdo->lastInsertId();
  registrar($pdo, 'crear', 'consultas', $id, "Consulta guardada «{$nombre}»");
  return ['id' => $id];
}

function borrarConsulta(PDO $pdo, int $id): void {
  $consulta = $pdo->prepare('SELECT nombre FROM consultas WHERE id = ?');
  $consulta->execute([$id]);
  $nombre = $consulta->fetchColumn() ?: fallar('Consulta no encontrada', 404);
  $pdo->prepare('DELETE FROM consultas WHERE id = ?')->execute([$id]);
  registrar($pdo, 'borrar', 'consultas', $id, "Consulta «{$nombre}» borrada");
}
