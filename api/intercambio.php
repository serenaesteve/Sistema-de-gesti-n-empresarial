<?php
// Intercambio de datos (RA3, criterios b y d): importar desde CSV o JSON (por ejemplo, la hoja de Excel
// que ya tiene la empresa) y exportar en CSV, Excel o JSON.
//
// · Clientes, productos y proveedores: una fila por registro. Los repetidos (mismo NIF, email o nombre)
//   se pueden omitir, actualizar con los datos del archivo o importar igualmente.
// · Pedidos y compras (v5.1): una fila por línea; las filas con la misma «Referencia» forman un solo
//   pedido. El cliente, el proveedor y el producto se buscan por su nombre o su NIF. Son historial:
//   no cambian el stock (el stock actual se importa en Productos).
// · Cada importación se puede deshacer: se borra lo creado y se recupera lo que se actualizó.
// · Duplicados: se buscan clientes o proveedores repetidos y se fusionan (sus pedidos pasan al que se queda).
//
// La importación va en dos pasos: primero se analiza el archivo y se muestra una vista previa con los
// errores de cada fila; después se importan solo las filas válidas, todas a la vez.

const MAX_FILAS_IMPORTACION = 5000;
const MODOS_IMPORTACION = ['omitir' => 'No importar los repetidos', 'actualizar' => 'Actualizar los que ya existen', 'duplicar' => 'Importarlos igualmente'];

// Recursos que se pueden importar: editables y que no sean usuarios
function recursosImportables(array $esquema, array $usuario): array {
  $lista = [];
  foreach ($esquema as $id => $modulo) {
    if (in_array($id, ['usuarios', 'incidencias'], true) || !empty($modulo['solo_lectura'])) continue;
    if (!puede($esquema, $usuario, 'crear', $id)) continue;
    $columnas = columnasImportacion($modulo);
    $lista[] = ['id' => $id, 'titulo' => $modulo['titulo'], 'lineas' => !empty($modulo['lineas']), 'campos' => array_values(array_map(
      fn($c, $d) => ['clave' => $c, 'etiqueta' => etiquetaImportacion($d), 'tipo' => $d['tipo'], 'obligatorio' => !empty($d['obligatorio']), 'opciones' => $d['opciones'] ?? null],
      array_keys($columnas), $columnas))];
  }
  return $lista;
}

// Columnas que admite la importación de un módulo. En los de líneas: referencia, cabecera y línea.
function columnasImportacion(array $modulo): array {
  $conLineas = !empty($modulo['lineas']);
  $columnas = $conLineas ? ['referencia' => ['etiqueta' => 'Referencia', 'tipo' => 'texto', 'nivel' => 'referencia']] : [];
  foreach ($modulo['campos'] as $c => $d) {
    if (in_array($d['tipo'], ['clave', 'permisos'], true) || !empty($d['auto'])) continue;
    if ($d['tipo'] === 'relacion' && !$conLineas) continue;
    $columnas[$c] = $d + ['nivel' => 'cabecera'];
  }
  foreach ($conLineas ? $modulo['lineas']['campos'] : [] as $c => $d) {
    // El precio de venta es automático en el ERP; al importar historial se puede indicar (si no, el del catálogo)
    if (!empty($d['auto'])) { unset($d['auto']); $d['obligatorio'] = false; }
    $columnas[$c] = $d + ['nivel' => 'linea'];
  }
  return $columnas;
}

function etiquetaImportacion(array $d): string {
  return $d['etiqueta'] . match ($d['tipo']) { 'relacion' => ' (nombre' . ($d['tabla'] === 'productos' ? ')' : ' o NIF)'), default => '' };
}

function exigirImportable(array $esquema, array $usuario, string $recurso): array {
  if (!in_array($recurso, array_column(recursosImportables($esquema, $usuario), 'id'), true)) {
    fallar('Estos datos no se pueden importar o no tienes permiso para crearlos.', 403);
  }
  return $esquema[$recurso];
}

// Plantilla CSV: cabecera con las etiquetas y filas de ejemplo (en pedidos, dos líneas del mismo pedido)
function plantillaImportacion(PDO $pdo, array $esquema, array $usuario, string $recurso): never {
  $modulo = exigirImportable($esquema, $usuario, $recurso);
  $ejemplos = [
    'texto' => 'Ejemplo', 'largo' => 'Observaciones', 'email' => 'contacto@empresa.es', 'tel' => '600 000 000',
    'nif' => 'B12345674', 'moneda' => '19,90', 'entero' => '1', 'fecha' => date('Y-m-d'), 'cp' => '46001', 'iban' => 'ES91 2100 0418 4502 0005 1332',
  ];
  $columnas = columnasImportacion($modulo);
  $fila = [];
  foreach ($columnas as $c => $d) {
    $fila[$c] = match (true) {
      $c === 'referencia' => 'P-001',
      $d['tipo'] === 'opcion' => $d['defecto'] ?? $d['opciones'][0],
      $d['tipo'] === 'relacion' => (string)($pdo->query("SELECT {$d['mostrar']} FROM {$d['tabla']} ORDER BY id LIMIT 1")->fetchColumn() ?: 'Nombre'),
      default => $ejemplos[$d['tipo']] ?? '',
    };
  }
  $filas = [$fila];
  if (!empty($modulo['lineas'])) {
    $segundo = (string)($pdo->query('SELECT nombre FROM productos ORDER BY id LIMIT 1 OFFSET 1')->fetchColumn() ?: 'Otro producto');
    $filas[] = array_merge($fila, ['producto_id' => $segundo, 'cantidad' => '2']);
  }
  $cabecera = array_map(fn($c, $d) => ['clave' => $c, 'etiqueta' => etiquetaImportacion($d) . (!empty($d['obligatorio']) ? ' *' : '')], array_keys($columnas), $columnas);
  header('Content-Type: text/csv; charset=utf-8');
  header("Content-Disposition: attachment; filename=\"plantilla-$recurso.csv\"");
  echo textoCsv(['columnas' => $cabecera, 'filas' => $filas]);
  exit;
}

// Convierte el texto del archivo en una lista de filas (clave => valor) según la cabecera
function leerArchivo(string $texto, string $formato): array {
  $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);   // BOM de Excel
  if (!mb_check_encoding($texto, 'UTF-8')) $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252'); // CSV de Excel antiguo
  if (trim($texto) === '') fallar('El archivo está vacío', 422, ['archivo' => 'El archivo está vacío']);

  if ($formato === 'json' || in_array(ltrim($texto)[0], ['[', '{'], true)) {
    $datos = json_decode($texto, true);
    if (!is_array($datos)) fallar('El JSON no es válido', 422, ['archivo' => 'El JSON no es válido']);
    $datos = $datos['filas'] ?? $datos['datos'] ?? $datos;   // también acepta lo que exporta el propio ERP
    if (!array_is_list($datos)) fallar('El JSON debe ser una lista de objetos', 422, ['archivo' => 'Debe ser una lista de objetos']);
    return array_values(array_filter($datos, 'is_array'));
  }

  // Separador: el que más aparezca en la cabecera («;» en Excel español, «,» en el resto, o tabulador)
  $primera = strtok($texto, "\r\n");
  $cuentas = [';' => substr_count($primera, ';'), ',' => substr_count($primera, ','), "\t" => substr_count($primera, "\t")];
  $separador = array_search(max($cuentas), $cuentas, true);
  // Se lee como archivo: así un campo entre comillas puede tener saltos de línea
  $flujo = fopen('php://temp', 'w+');
  fwrite($flujo, $texto);
  rewind($flujo);
  $cabecera = array_map(fn($c) => trim((string)$c), fgetcsv($flujo, null, $separador, '"', '') ?: []);
  $filas = [];
  while (($valores = fgetcsv($flujo, null, $separador, '"', '')) !== false) {
    if (!array_filter($valores, fn($v) => trim((string)$v) !== '')) continue;   // fila vacía
    $filas[] = array_combine($cabecera, array_pad(array_slice($valores, 0, count($cabecera)), count($cabecera), ''));
  }
  fclose($flujo);
  return $filas;
}

// Quita tildes, mayúsculas, asteriscos y espacios: «Teléfono *» → «telefono»
function claveComparable(string $texto): string {
  $texto = mb_strtolower(trim(str_replace('*', '', $texto)));
  $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
  return preg_replace('/[^a-z0-9]+/', '', $texto);
}

// Relaciona cada columna del archivo con un campo (por su clave o su etiqueta)
function mapearColumnas(array $campos, array $cabecera): array {
  $conocidas = [];
  foreach ($campos as $c => $d) {
    $conocidas[claveComparable($c)] = $c;
    $conocidas[claveComparable($d['etiqueta'])] = $c;
    $conocidas[claveComparable(etiquetaImportacion($d))] = $c;
    if ($d['tipo'] === 'relacion') $conocidas[claveComparable(nombreRelacion($c))] = $c;
    if ($d['tipo'] === 'nif') { $conocidas['nif'] = $c; $conocidas['cif'] = $c; $conocidas['dni'] = $c; }
    if ($d['tipo'] === 'cp') { $conocidas['cp'] = $c; $conocidas['codpostal'] = $c; }
    if ($c === 'referencia') foreach (['ref', 'pedido', 'compra', 'numero', 'numeropedido', 'documento'] as $sinonimo) $conocidas[$sinonimo] = $c;
  }
  $mapa = [];
  $ignoradas = [];
  foreach ($cabecera as $columna) {
    $campo = $conocidas[claveComparable((string)$columna)] ?? null;
    if ($campo && !in_array($campo, $mapa, true)) $mapa[$columna] = $campo;
    else $ignoradas[] = (string)$columna;
  }
  return [$mapa, $ignoradas];
}

// Formatos habituales de una hoja de cálculo española → formato del ERP
function normalizarValor(string $tipo, string $valor): string {
  if ($valor === '') return '';
  return match ($tipo) {
    'moneda' => str_replace(['€', ' '], '', preg_match('/,\d{1,2}$/', $valor) ? str_replace(['.', ','], ['', '.'], $valor) : $valor),
    'opcion' => mb_strtolower($valor),
    'fecha' => preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $valor, $m) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : $valor,  // 15/03/2026
    default => $valor,
  };
}

// Busca un cliente, proveedor o producto por su nombre (sin importar mayúsculas), su NIF o su número
function resolutorRelacion(PDO $pdo, array $def): callable {
  $conNif = in_array('nif', columnas($pdo, $def['tabla']), true);
  $mapa = [];
  foreach ($pdo->query("SELECT id, {$def['mostrar']} AS nombre" . ($conNif ? ', nif' : '') . " FROM {$def['tabla']} ORDER BY id")->fetchAll() as $f) {
    $mapa['n:' . mb_strtolower(trim((string)$f['nombre']))] ??= (int)$f['id'];
    if ($conNif && $f['nif']) $mapa['f:' . normalizarNif((string)$f['nif'])] ??= (int)$f['id'];
    $mapa['i:' . $f['id']] = (int)$f['id'];
  }
  return fn(string $v) => $mapa['n:' . mb_strtolower($v)] ?? $mapa['f:' . normalizarNif($v)] ?? (ctype_digit($v) ? ($mapa['i:' . (int)$v] ?? null) : null);
}

// Analiza el archivo: valida cada fila como si se diera de alta a mano y busca duplicados.
// Devuelve también los datos ya limpios (los usa importar()); la API solo envía la vista previa.
function analizarArchivo(PDO $pdo, array $esquema, array $usuario, string $recurso, array $entrada): array {
  $modulo = exigirImportable($esquema, $usuario, $recurso);
  $filas = leerArchivo((string)($entrada['contenido'] ?? ''), (string)($entrada['formato'] ?? 'csv'));
  if (!$filas) fallar('El archivo no tiene filas de datos', 422, ['archivo' => 'Solo tiene la cabecera']);
  if (count($filas) > MAX_FILAS_IMPORTACION) fallar('Demasiadas filas', 422, ['archivo' => 'Máximo ' . MAX_FILAS_IMPORTACION . ' filas por archivo']);

  $columnas = columnasImportacion($modulo);
  $cabecera = array_keys(array_merge(...array_map(fn($f) => array_fill_keys(array_keys($f), true), $filas)));
  [$mapa, $ignoradas] = mapearColumnas($columnas, $cabecera);
  if (!$mapa) fallar('Ninguna columna coincide con los campos', 422, ['archivo' => 'Ninguna columna coincide: descarga la plantilla para ver los nombres']);
  $conLineas = !empty($modulo['lineas']);
  $resolutores = [];
  foreach ($columnas as $c => $d) if ($d['tipo'] === 'relacion') $resolutores[$c] = resolutorRelacion($pdo, $d);

  // Duplicados (solo sin líneas): por NIF, email o nombre, contra la base de datos y dentro del archivo
  $claves = $conLineas ? [] : array_values(array_filter(['nif', 'email', $modulo['principal']], fn($c) => isset($columnas[$c])));
  $existentes = [];
  foreach ($claves as $c) {
    foreach ($pdo->query("SELECT id, $c FROM $recurso WHERE $c IS NOT NULL")->fetchAll() as $f) $existentes[$c][mb_strtolower((string)$f[$c])] ??= (int)$f['id'];
  }
  $vistos = [];
  $camposPlanos = array_filter($modulo['campos'], fn($d) => !in_array($d['tipo'], ['clave', 'permisos', 'relacion'], true));
  $camposLinea = array_filter($columnas, fn($d) => $d['nivel'] === 'linea');

  $resultado = [];
  $primeras = [];
  foreach ($filas as $i => $fila) {
    $datos = [];
    foreach ($mapa as $columna => $campo) {
      $datos[$campo] = normalizarValor($columnas[$campo]['tipo'], is_scalar($fila[$columna] ?? null) ? trim((string)$fila[$columna]) : '');
    }
    // En pedidos y compras, las filas que siguen a la primera de una referencia heredan su cabecera vacía
    if ($conLineas && ($datos['referencia'] ?? '') !== '') {
      $ref = $datos['referencia'];
      if (isset($primeras[$ref])) {
        foreach ($columnas as $c => $d) if ($d['nivel'] === 'cabecera' && ($datos[$c] ?? '') === '' && ($primeras[$ref][$c] ?? '') !== '') $datos[$c] = $primeras[$ref][$c];
      } else {
        $primeras[$ref] = $datos;
      }
    }
    $r = ['fila' => $i + 2, 'datos' => $datos, 'errores' => [], 'avisos' => [], 'existente' => null, 'repetida' => false];

    // Relaciones por nombre o NIF → número
    $valores = $datos;
    foreach ($resolutores as $c => $buscar) {
      if (($datos[$c] ?? '') === '') continue;
      $id = $buscar($datos[$c]);
      if ($id === null) $r['errores'][$c] = "No existe «{$datos[$c]}»";
      else $valores[$c] = $id;
    }

    if ($conLineas) {
      [$cabeceraLimpia, $errores] = validarCampos($pdo, $modulo['campos'], array_diff_key($valores, $r['errores']), false);
      [$lineaLimpia, $erroresLinea] = validarCampos($pdo, $camposLinea, array_diff_key($valores, $r['errores']), false);
      $r['errores'] += $errores + $erroresLinea;
      $r['cabecera'] = $cabeceraLimpia;
      $r['linea'] = $lineaLimpia;
      $r['grupo'] = ($datos['referencia'] ?? '') !== '' ? 'r:' . $datos['referencia'] : 'f:' . $i;
    } else {
      [$limpio, $errores] = validarCampos($pdo, $camposPlanos, $datos, false);
      $r['errores'] += $errores;
      $r['limpio'] = $limpio;
      // Solo se actualizan las columnas que trae el archivo (y la provincia, si trae el código postal)
      $enArchivo = array_flip(array_values($mapa));
      foreach ($camposPlanos as $c => $d) if (isset($d['desde_cp'], $enArchivo[$d['desde_cp']])) $enArchivo[$c] = true;
      $r['cambios'] = array_intersect_key($limpio, $enArchivo);
      foreach ($claves as $c) {
        $v = mb_strtolower((string)($limpio[$c] ?? ''));
        if ($v === '') continue;
        $etiqueta = mb_strtolower($columnas[$c]['etiqueta']);
        if (isset($vistos[$c][$v])) { $r['avisos'][] = "$etiqueta repetido en la fila {$vistos[$c][$v]}"; $r['repetida'] = true; break; }
        if (isset($existentes[$c][$v])) { $r['avisos'][] = "ya existe un registro con este $etiqueta"; $r['existente'] = $existentes[$c][$v]; break; }
      }
      foreach ($claves as $c) if (($v = mb_strtolower((string)($limpio[$c] ?? ''))) !== '') $vistos[$c][$v] ??= $i + 2;
    }
    $resultado[] = $r;
  }

  // Pedidos y compras: todas las filas de un mismo pedido deben ser correctas y coincidir en la cabecera
  $grupos = [];
  if ($conLineas) {
    foreach ($resultado as $k => $r) $grupos[$r['grupo']][] = $k;
    $camposCabecera = array_keys(array_filter($columnas, fn($d) => $d['nivel'] === 'cabecera'));
    foreach ($grupos as $indices) {
      $primera = $resultado[$indices[0]];
      foreach (array_slice($indices, 1) as $k) {
        foreach ($camposCabecera as $c) {
          if (($resultado[$k]['datos'][$c] ?? '') !== '' && ($resultado[$k]['cabecera'][$c] ?? null) !== ($primera['cabecera'][$c] ?? null)) {
            $resultado[$k]['errores']['referencia'] = mb_strtolower($columnas[$c]['etiqueta']) . ' distinto del de la fila ' . $primera['fila'] . ' (misma referencia)';
          }
        }
      }
      if (array_filter($indices, fn($k) => $resultado[$k]['errores'])) {
        foreach ($indices as $k) if (!$resultado[$k]['errores']) $resultado[$k]['errores']['referencia'] = 'Otra fila de este ' . $modulo['singular'] . ' tiene errores';
      }
    }
  }

  $validas = array_filter($resultado, fn($r) => !$r['errores']);
  return [
    'recurso' => $recurso, 'lineas' => $conLineas,
    'columnas' => array_map(fn($c) => ['clave' => $c, 'etiqueta' => $columnas[$c]['etiqueta']], array_values($mapa)),
    'mapa' => $mapa, 'ignoradas' => $ignoradas, 'filas' => $resultado,
    'validas' => count($validas),
    'con_errores' => count($resultado) - count($validas),
    'duplicadas' => count(array_filter($resultado, fn($r) => $r['avisos'])),
    'existentes' => count(array_filter($validas, fn($r) => $r['existente'] !== null)),
    'registros' => $conLineas ? count(array_unique(array_column($validas, 'grupo'))) : count($validas),
  ];
}

// Vista previa para la pantalla (sin los datos internos)
function analizarImportacion(PDO $pdo, array $esquema, array $usuario, string $recurso, array $entrada): array {
  $analisis = analizarArchivo($pdo, $esquema, $usuario, $recurso, $entrada);
  $analisis['filas'] = array_map(fn($r) => [
    'fila' => $r['fila'], 'datos' => $r['datos'], 'errores' => (object)$r['errores'], 'avisos' => $r['avisos'],
    'existente' => $r['existente'], 'grupo' => $r['grupo'] ?? null,
  ], $analisis['filas']);
  return $analisis + ['modos' => MODOS_IMPORTACION];
}

// Importa las filas válidas en una sola transacción (o todas o ninguna) y la anota para poder deshacerla
function importar(PDO $pdo, array $esquema, array $usuario, string $recurso, array $entrada): array {
  $analisis = analizarArchivo($pdo, $esquema, $usuario, $recurso, $entrada);
  $modo = (string)($entrada['modo'] ?? (array_key_exists('omitir_duplicados', $entrada) && !$entrada['omitir_duplicados'] ? 'duplicar' : 'omitir'));
  if (!isset(MODOS_IMPORTACION[$modo])) fallar('Modo de importación no válido', 422);
  if ($modo === 'actualizar') exigirPermiso($esquema, $usuario, 'editar', $recurso);
  $modulo = $esquema[$recurso];
  $validas = array_values(array_filter($analisis['filas'], fn($r) => !$r['errores']));

  $inicio = hrtime(true);
  $creados = [];
  $actualizados = [];
  $lineas = 0;
  $insertar = function (string $tabla, array $datos) use ($pdo): int {
    $pdo->prepare("INSERT INTO $tabla (" . implode(', ', array_keys($datos)) . ') VALUES (' . implode(', ', array_fill(0, count($datos), '?')) . ')')
        ->execute(array_values($datos));
    return (int)$pdo->lastInsertId();
  };

  $pdo->beginTransaction();
  try {
    if ($analisis['lineas']) {
      // Un registro por grupo; el precio de venta, si no viene, es el del catálogo. No se toca el stock (historial).
      ['tabla' => $tablaLineas, 'clave' => $claveLineas] = $modulo['lineas'];
      $catalogo = $pdo->query('SELECT id, precio FROM productos')->fetchAll(PDO::FETCH_KEY_PAIR);
      $porGrupo = [];
      foreach ($validas as $r) $porGrupo[$r['grupo']][] = $r;
      foreach ($porGrupo as $filasGrupo) {
        $id = $insertar($recurso, $filasGrupo[0]['cabecera']);
        foreach ($filasGrupo as $r) {
          $linea = $r['linea'];
          if (array_key_exists('precio', $linea) && $linea['precio'] === null) $linea['precio'] = round((float)($catalogo[$linea['producto_id']] ?? 0), 2);
          $insertar($tablaLineas, [$claveLineas => $id] + $linea);
          $lineas++;
        }
        $creados[] = $id;
      }
    } else {
      foreach ($validas as $r) {
        if ($r['repetida'] && $modo !== 'duplicar') continue;
        if ($r['existente'] !== null && $modo === 'omitir') continue;
        if ($r['existente'] !== null && $modo === 'actualizar') {
          if (!$r['cambios']) continue;
          $columnasCambio = array_keys($r['cambios']);
          $antes = $pdo->prepare('SELECT ' . implode(', ', $columnasCambio) . " FROM $recurso WHERE id = ?");
          $antes->execute([$r['existente']]);
          $actualizados[] = ['id' => $r['existente'], 'antes' => $antes->fetch()];
          $pdo->prepare("UPDATE $recurso SET " . implode(', ', array_map(fn($c) => "$c = ?", $columnasCambio)) . ' WHERE id = ?')
              ->execute([...array_values($r['cambios']), $r['existente']]);
          continue;
        }
        $creados[] = $insertar($recurso, $r['limpio']);
      }
    }
    if (!$creados && !$actualizados) fallar('No hay filas válidas que importar', 422);

    $archivo = mb_substr(basename((string)($entrada['nombre'] ?? 'archivo')), 0, 120);
    $importacion = $insertar('importaciones', [
      'recurso' => $recurso, 'archivo' => $archivo, 'modo' => $modo, 'creados' => json_encode($creados),
      'actualizados' => json_encode($actualizados, JSON_UNESCAPED_UNICODE), 'filas' => count($creados),
      'usuario' => $usuario['nombre'], 'deshecha' => 0,
    ]);
    $titulo = mb_strtolower($modulo['titulo']);
    registrar($pdo, 'importar', $recurso, $importacion, "Importación de " . count($creados) . " $titulo" . ($lineas ? " ($lineas líneas)" : '')
      . ($actualizados ? ' y ' . count($actualizados) . ' actualizados' : '') . " desde «{$archivo}»"
      . ($analisis['con_errores'] ? " · {$analisis['con_errores']} filas con errores no importadas" : ''));
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
  $usadas = $analisis['lineas'] ? count($validas) : count($creados) + count($actualizados);
  return [
    'importacion' => $importacion, 'importadas' => count($creados), 'actualizadas' => count($actualizados), 'lineas' => $lineas,
    'omitidas' => count($analisis['filas']) - $usadas, 'ms' => round((hrtime(true) - $inicio) / 1e6, 1),
  ];
}

function historialImportaciones(PDO $pdo, array $esquema): array {
  return array_map(fn($f) => [
    'id' => (int)$f['id'], 'recurso' => $f['recurso'], 'titulo' => $esquema[$f['recurso']]['titulo'] ?? $f['recurso'],
    'archivo' => $f['archivo'], 'modo' => $f['modo'], 'creados' => count(json_decode((string)$f['creados'], true) ?: []),
    'actualizados' => count(json_decode((string)$f['actualizados'], true) ?: []), 'usuario' => $f['usuario'],
    'creado' => $f['creado'], 'deshecha' => (bool)$f['deshecha'],
  ], $pdo->query('SELECT * FROM importaciones ORDER BY id DESC LIMIT 15')->fetchAll());
}

// Deshace una importación: recupera lo actualizado y borra lo creado. Si ya se usa (por ejemplo, un pedido
// de un cliente importado), no se toca nada.
function deshacerImportacion(PDO $pdo, array $esquema, array $usuario, int $id): array {
  $consulta = $pdo->prepare('SELECT * FROM importaciones WHERE id = ?');
  $consulta->execute([$id]);
  $importacion = $consulta->fetch() ?: fallar('Importación no encontrada', 404);
  if ($importacion['deshecha']) fallar('Esta importación ya se deshizo.', 409);
  $recurso = $importacion['recurso'];
  exigirPermiso($esquema, $usuario, 'borrar', $recurso);
  $creados = array_map('intval', json_decode((string)$importacion['creados'], true) ?: []);
  $actualizados = json_decode((string)$importacion['actualizados'], true) ?: [];

  $pdo->beginTransaction();
  try {
    foreach ($actualizados as $a) {
      $columnas = array_keys($a['antes']);
      $pdo->prepare("UPDATE $recurso SET " . implode(', ', array_map(fn($c) => "$c = ?", $columnas)) . ' WHERE id = ?')
          ->execute([...array_values($a['antes']), (int)$a['id']]);
    }
    foreach (array_chunk($creados, 500) as $trozo) {
      try {
        $pdo->prepare("DELETE FROM $recurso WHERE id IN (" . implode(', ', array_fill(0, count($trozo), '?')) . ')')->execute($trozo);
      } catch (PDOException $e) {
        if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'FOREIGN KEY')) {
          fallar('No se puede deshacer: ya hay otros datos que usan lo importado (por ejemplo, pedidos de un cliente importado o facturas de un pedido).', 409);
        }
        throw $e;
      }
    }
    $pdo->prepare('UPDATE importaciones SET deshecha = 1 WHERE id = ?')->execute([$id]);
    registrar($pdo, 'deshacer', $recurso, $id, "Importación de «{$importacion['archivo']}» deshecha: " . count($creados) . ' borrados'
      . ($actualizados ? ', ' . count($actualizados) . ' recuperados como estaban' : ''));
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
  return ['borrados' => count($creados), 'recuperados' => count($actualizados)];
}

// ---------- Duplicados ----------

const RECURSOS_DUPLICADOS = ['clientes', 'proveedores'];

// Tablas y columnas que apuntan a un recurso (pedidos.cliente_id → clientes…), también en las líneas
function referenciasA(array $esquema, string $recurso): array {
  $lista = [];
  foreach ($esquema as $tabla => $modulo) {
    foreach ($modulo['campos'] as $c => $d) if ($d['tipo'] === 'relacion' && $d['tabla'] === $recurso) $lista[] = [$tabla, $c];
    foreach ($modulo['lineas']['campos'] ?? [] as $c => $d) if ($d['tipo'] === 'relacion' && $d['tabla'] === $recurso) $lista[] = [$modulo['lineas']['tabla'], $c];
  }
  return $lista;
}

// Grupos de registros repetidos: mismo NIF, email o nombre (sin importar mayúsculas ni espacios)
function gruposDuplicados(PDO $pdo, array $esquema, string $recurso): array {
  if (!in_array($recurso, RECURSOS_DUPLICADOS, true) || !isset($esquema[$recurso])) fallar('Solo se buscan duplicados en clientes y proveedores', 404);
  $filas = $pdo->query("SELECT * FROM $recurso ORDER BY id")->fetchAll();
  $padre = [];
  $raiz = function (int $i) use (&$padre, &$raiz) { return $padre[$i] === $i ? $i : $padre[$i] = $raiz($padre[$i]); };
  $primero = [];
  foreach ($filas as $i => $f) {
    $padre[$i] = $i;
    $claves = ['f:' . normalizarNif((string)$f['nif']), 'e:' . mb_strtolower(trim((string)$f['email'])), 'n:' . mb_strtolower(preg_replace('/\s+/', ' ', trim((string)$f['nombre'])))];
    foreach ($claves as $k) {
      if (in_array($k, ['f:', 'e:', 'n:'], true)) continue;
      if (isset($primero[$k])) $padre[$raiz($i)] = $raiz($primero[$k]);
      else $primero[$k] = $i;
    }
  }
  $referencias = referenciasA($esquema, $recurso);
  $grupos = [];
  foreach ($filas as $i => $f) $grupos[$raiz($i)][] = $f;
  $resultado = [];
  foreach ($grupos as $miembros) {
    if (count($miembros) < 2) continue;
    $resultado[] = array_map(function ($f) use ($pdo, $referencias) {
      $usos = 0;
      foreach ($referencias as [$tabla, $campo]) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM $tabla WHERE $campo = ?");
        $c->execute([$f['id']]);
        $usos += (int)$c->fetchColumn();
      }
      return ['id' => (int)$f['id'], 'nombre' => $f['nombre'], 'empresa' => $f['empresa'] ?? null, 'nif' => $f['nif'], 'email' => $f['email'],
        'telefono' => $f['telefono'], 'ciudad' => $f['ciudad'], 'usos' => $usos, 'creado' => $f['creado']];
    }, $miembros);
  }
  return $resultado;
}

// Fusiona dos registros: lo que apuntaba al que se elimina pasa al que se conserva, y los datos que
// le faltan al conservado se completan con los del eliminado.
function fusionar(PDO $pdo, array $esquema, array $usuario, array $entrada): array {
  $recurso = (string)($entrada['recurso'] ?? '');
  if (!in_array($recurso, RECURSOS_DUPLICADOS, true)) fallar('Solo se fusionan clientes o proveedores', 422);
  exigirPermiso($esquema, $usuario, 'editar', $recurso);
  exigirPermiso($esquema, $usuario, 'borrar', $recurso);
  $conservar = (int)($entrada['conservar'] ?? 0);
  $eliminar = (int)($entrada['eliminar'] ?? 0);
  if ($conservar === $eliminar || $conservar < 1 || $eliminar < 1) fallar('Elige dos registros distintos', 422);
  $leer = function (int $id) use ($pdo, $recurso, $esquema) {
    $c = $pdo->prepare("SELECT * FROM $recurso WHERE id = ?");
    $c->execute([$id]);
    return $c->fetch() ?: fallar(ucfirst($esquema[$recurso]['singular']) . " #$id no encontrado", 404);
  };
  $a = $leer($conservar);
  $b = $leer($eliminar);
  $modulo = $esquema[$recurso];

  $pdo->beginTransaction();
  try {
    $movidos = 0;
    foreach (referenciasA($esquema, $recurso) as [$tabla, $campo]) {
      $u = $pdo->prepare("UPDATE $tabla SET $campo = ? WHERE $campo = ?");
      $u->execute([$conservar, $eliminar]);
      $movidos += $u->rowCount();
    }
    $completar = [];
    foreach ($modulo['campos'] as $c => $d) {
      if (in_array($d['tipo'], ['clave', 'permisos'], true)) continue;
      $va = trim((string)($a[$c] ?? ''));
      $vb = trim((string)($b[$c] ?? ''));
      if ($va === '' && $vb !== '') $completar[$c] = $b[$c];
      elseif ($d['tipo'] === 'largo' && $vb !== '' && $va !== $vb) $completar[$c] = $a[$c] . "\n" . $b[$c];
    }
    if ($completar) {
      $pdo->prepare("UPDATE $recurso SET " . implode(', ', array_map(fn($c) => "$c = ?", array_keys($completar))) . ' WHERE id = ?')
          ->execute([...array_values($completar), $conservar]);
    }
    $pdo->prepare("DELETE FROM $recurso WHERE id = ?")->execute([$eliminar]);
    registrar($pdo, 'fusionar', $recurso, $conservar, ucfirst($modulo['singular']) . " «{$b['nombre']}» (#$eliminar) fusionado en «{$a['nombre']}» (#$conservar): "
      . "$movidos registros relacionados pasan al que se conserva" . ($completar ? ', completados: ' . implode(', ', array_map(fn($c) => mb_strtolower($modulo['campos'][$c]['etiqueta']), array_keys($completar))) : ''));
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
  return ['conservado' => $conservar, 'movidos' => $movidos, 'completados' => array_keys($completar)];
}
