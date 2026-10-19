<?php
// Extracciones programadas (RA3, criterio e): un informe que se genera solo cada día, semana o mes
// y se guarda como CSV o JSON en datos/extracciones/. Las lanza el programador de tareas del
// sistema (cron) con scripts/tareas.php; también se pueden lanzar a mano con «Ejecutar».
// Desde la v5.1 también pueden extraer una consulta guardada («consulta:3») y guardar en Excel; si una tarea
// falla, se abre sola una incidencia (una por tarea mientras siga sin resolver).
//
//   crontab -e
//   */15 * * * * php /var/www/html/Sistema-de-gesti-n-empresarial/scripts/tareas.php >> /var/log/blush-tareas.log 2>&1

const FRECUENCIAS = ['diaria' => 'Cada día', 'semanal' => 'Cada lunes', 'mensual' => 'El día 1 de cada mes'];
const PERIODOS_TAREA = [
  'ayer' => 'Ayer', 'semana_anterior' => 'La semana anterior', 'mes_anterior' => 'El mes anterior',
  'ultimos_30' => 'Los últimos 30 días', 'ano_actual' => 'Lo que va de año',
];
const HORA_TAREAS = '07:00';
const FORMATOS_TAREA = ['csv' => 'CSV (Excel)', 'xlsx' => 'Excel (.xlsx)', 'json' => 'JSON (otros programas)'];
const CONSERVAR_EXTRACCIONES = 30;   // archivos por tarea; los más antiguos se borran

// Fechas reales de un periodo relativo, calculadas el día en que se ejecuta
function fechasPeriodo(string $periodo, ?int $momento = null): array {
  $hoy = $momento ?? time();
  $f = fn(string $expresion) => date('Y-m-d', strtotime($expresion, $hoy));
  return match ($periodo) {
    'ayer'            => [$f('yesterday'), $f('yesterday')],
    'semana_anterior' => [$f('monday last week'), $f('sunday last week')],
    'mes_anterior'    => [$f('first day of last month'), $f('last day of last month')],
    'ano_actual'      => [$f('first day of january this year'), $f('today')],
    default           => [$f('-30 days'), $f('yesterday')],
  };
}

// Próxima ejecución a partir de ahora
function proximaEjecucion(string $frecuencia, ?int $desde = null): string {
  $desde ??= time();
  $hoy = strtotime(date('Y-m-d') . ' ' . HORA_TAREAS, $desde);
  $siguiente = match ($frecuencia) {
    'semanal' => strtotime('next monday ' . HORA_TAREAS, $desde),
    'mensual' => strtotime('first day of next month ' . HORA_TAREAS, $desde),
    default   => $hoy > $desde ? $hoy : strtotime('tomorrow ' . HORA_TAREAS, $desde),
  };
  return date('Y-m-d H:i:s', $siguiente);
}

function carpetaExtracciones(): string {
  $carpeta = dirname(rutaInstalacion()) . '/extracciones';
  if (!is_dir($carpeta) && !@mkdir($carpeta, 0777, true)) {
    throw new RuntimeException('No se pudo crear la carpeta datos/extracciones/ para guardar las extracciones.');
  }
  return $carpeta;
}

// Usuario con el que se ejecutan las tareas: administración, pero sin persona detrás
function usuarioTareas(): array {
  return ['id' => 0, 'nombre' => 'Tareas programadas', 'email' => '', 'rol' => 'admin', 'permisos' => null];
}

function listarTareas(PDO $pdo, array $esquema, array $usuario): array {
  $tareas = $pdo->query('SELECT * FROM tareas ORDER BY id')->fetchAll();
  $ultimas = $pdo->query('SELECT e.*, t.nombre AS tarea FROM tarea_ejecuciones e LEFT JOIN tareas t ON t.id = e.tarea_id ORDER BY e.id DESC LIMIT 30')->fetchAll();
  $titulos = array_column(array_map(fn($k, $i) => ['id' => $k, 'titulo' => $i['titulo']], array_keys(catalogoInformes()), catalogoInformes()), 'titulo', 'id');
  $guardadas = listarConsultas($pdo, $usuario);
  foreach ($guardadas as $c) $titulos["consulta:{$c['id']}"] = "Consulta «{$c['nombre']}»";
  return [
    'tareas' => array_map(fn($t) => [
      'id' => (int)$t['id'], 'nombre' => $t['nombre'], 'informe' => $t['informe'], 'informe_titulo' => $titulos[$t['informe']] ?? $t['informe'],
      'periodo' => $t['periodo'], 'formato' => $t['formato'], 'frecuencia' => $t['frecuencia'], 'activa' => (bool)$t['activa'],
      'proxima' => $t['proxima'], 'fechas' => fechasPeriodo($t['periodo']),
    ], $tareas),
    'ejecuciones' => array_map(fn($e) => [
      'id' => (int)$e['id'], 'tarea_id' => (int)$e['tarea_id'], 'tarea' => $e['tarea'], 'estado' => $e['estado'],
      'archivo' => $e['archivo'] ? basename($e['archivo']) : null, 'disponible' => $e['archivo'] && is_file($e['archivo']),
      'filas' => $e['filas'] === null ? null : (int)$e['filas'], 'ms' => $e['ms'] === null ? null : (int)$e['ms'],
      'mensaje' => $e['mensaje'], 'origen' => $e['origen'], 'creado' => $e['creado'],
    ], $ultimas),
    'informes' => informesDisponibles($esquema, $usuario),
    'consultas' => array_map(fn($c) => ['id' => "consulta:{$c['id']}", 'titulo' => $c['nombre'], 'tipo' => $c['tipo']], $guardadas),
    'formatos' => FORMATOS_TAREA,
    'periodos' => PERIODOS_TAREA, 'frecuencias' => FRECUENCIAS, 'hora' => HORA_TAREAS,
    'cron' => '*/15 * * * * php ' . realpath(__DIR__ . '/../scripts/tareas.php') . ' >> /var/log/blush-tareas.log 2>&1',
    'ultima_cron' => $pdo->query("SELECT MAX(creado) FROM tarea_ejecuciones WHERE origen = 'cron'")->fetchColumn() ?: null,
  ];
}

function validarTarea(PDO $pdo, array $esquema, array $usuario, array $entrada): array {
  $errores = [];
  $nombre = trim((string)($entrada['nombre'] ?? ''));
  if ($nombre === '' || mb_strlen($nombre) > 100) $errores['nombre'] = 'Ponle un nombre (máximo 100 caracteres)';
  $informe = (string)($entrada['informe'] ?? '');
  $consultas = array_map(fn($c) => "consulta:{$c['id']}", listarConsultas($pdo, $usuario));
  if (!in_array($informe, [...array_column(informesDisponibles($esquema, $usuario), 'id'), ...$consultas], true)) $errores['informe'] = 'Elige un informe o una consulta guardada';
  $periodo = (string)($entrada['periodo'] ?? 'mes_anterior');
  if (!isset(PERIODOS_TAREA[$periodo])) $errores['periodo'] = 'Periodo no válido';
  $formato = (string)($entrada['formato'] ?? 'csv');
  if (!isset(FORMATOS_TAREA[$formato])) $errores['formato'] = 'Formato no válido';
  $frecuencia = (string)($entrada['frecuencia'] ?? 'mensual');
  if (!isset(FRECUENCIAS[$frecuencia])) $errores['frecuencia'] = 'Frecuencia no válida';
  if ($errores) fallar('Revisa los campos marcados', 422, $errores);
  return ['nombre' => $nombre, 'informe' => $informe, 'periodo' => $periodo, 'formato' => $formato, 'frecuencia' => $frecuencia,
    'activa' => array_key_exists('activa', $entrada) ? (int)(bool)$entrada['activa'] : 1];
}

function guardarTarea(PDO $pdo, array $esquema, array $usuario, array $entrada, ?int $id): array {
  if ($id !== null) {
    $consulta = $pdo->prepare('SELECT * FROM tareas WHERE id = ?');
    $consulta->execute([$id]);
    $actual = $consulta->fetch() ?: fallar('Tarea no encontrada', 404);
    $datos = validarTarea($pdo, $esquema, $usuario, $entrada + $actual);
    $pdo->prepare('UPDATE tareas SET nombre = ?, informe = ?, periodo = ?, formato = ?, frecuencia = ?, activa = ?, proxima = ? WHERE id = ?')
        ->execute([...array_values($datos), proximaEjecucion($datos['frecuencia']), $id]);
    registrar($pdo, 'editar', 'tareas', $id, "Tarea «{$datos['nombre']}»" . ($datos['activa'] ? '' : ' (pausada)'));
  } else {
    $datos = validarTarea($pdo, $esquema, $usuario, $entrada);
    $pdo->prepare('INSERT INTO tareas (nombre, informe, periodo, formato, frecuencia, activa, proxima) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([...array_values($datos), proximaEjecucion($datos['frecuencia'])]);
    $id = (int)$pdo->lastInsertId();
    registrar($pdo, 'crear', 'tareas', $id, "Tarea programada «{$datos['nombre']}» · " . mb_strtolower(FRECUENCIAS[$datos['frecuencia']]));
  }
  return ['id' => $id];
}

function borrarTarea(PDO $pdo, int $id): void {
  $consulta = $pdo->prepare('SELECT nombre FROM tareas WHERE id = ?');
  $consulta->execute([$id]);
  $nombre = $consulta->fetchColumn() ?: fallar('Tarea no encontrada', 404);
  $pdo->prepare('DELETE FROM tareas WHERE id = ?')->execute([$id]);
  registrar($pdo, 'borrar', 'tareas', $id, "Tarea «{$nombre}» borrada (sus extracciones se conservan)");
}

// Ejecuta una tarea: genera el informe del periodo, lo guarda en un archivo y lo anota.
// Si falla, la incidencia queda registrada con su mensaje (criterio g).
function ejecutarTarea(PDO $pdo, array $esquema, array $tarea, string $origen): array {
  [$desde, $hasta] = fechasPeriodo($tarea['periodo']);
  $inicio = hrtime(true);
  $archivo = null;
  try {
    if (str_starts_with($tarea['informe'], 'consulta:')) {
      // Consulta guardada: no tiene periodo, se extrae tal cual
      $id = (int)substr($tarea['informe'], 9);
      $guardada = array_values(array_filter(listarConsultas($pdo, usuarioTareas()), fn($c) => $c['id'] === $id))[0] ?? throw new RuntimeException('La consulta guardada ya no existe');
      $resultado = $guardada['tipo'] === 'sql'
        ? ejecutarSql($GLOBALS['config_erp'], $esquema, (string)($guardada['definicion']['sql'] ?? ''), $pdo)
        : ejecutarConstructor($pdo, $esquema, usuarioTareas(), $guardada['definicion']);
      $resultado['titulo'] = $guardada['nombre'];
      $desde = $hasta = date('Y-m-d');
    } else {
      $resultado = ejecutarInforme($pdo, $esquema, usuarioTareas(), $tarea['informe'], ['desde' => $desde, 'hasta' => $hasta, 'agrupacion' => 'dia']);
    }
    $nombre = slugFormulario($tarea['nombre']) ?: 'tarea';
    $archivo = carpetaExtracciones() . "/$nombre-$desde" . ($desde !== $hasta ? "_$hasta" : '') . '-' . date('His') . ".{$tarea['formato']}";
    $contenido = match ($tarea['formato']) {
      'json' => json_encode($resultado + ['tarea' => $tarea['nombre']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      'xlsx' => libroExcel($resultado, $resultado['titulo']),
      default => textoCsv($resultado),
    };
    if (file_put_contents($archivo, $contenido) === false) throw new RuntimeException('No se pudo escribir en datos/extracciones/');
    @chmod($archivo, 0666);
    $estado = 'ok';
    $filas = count($resultado['filas']);
    $mensaje = str_starts_with($tarea['informe'], 'consulta:') ? "Consulta «{$resultado['titulo']}»" : "{$resultado['titulo']} del $desde al $hasta";
  } catch (Throwable $e) {
    $estado = 'error';
    $filas = null;
    $archivo = null;
    $mensaje = mb_substr($e instanceof ErrorApi && $e->errores ? $e->getMessage() . ': ' . implode(' · ', $e->errores) : $e->getMessage(), 0, 250);
    abrirIncidenciaTarea($pdo, $tarea, $mensaje);
  }
  $ms = (int)round((hrtime(true) - $inicio) / 1e6);
  $pdo->prepare('INSERT INTO tarea_ejecuciones (tarea_id, estado, archivo, filas, ms, mensaje, origen) VALUES (?, ?, ?, ?, ?, ?, ?)')
      ->execute([$tarea['id'], $estado, $archivo, $filas, $ms, $mensaje, $origen]);
  $pdo->prepare('UPDATE tareas SET proxima = ? WHERE id = ?')->execute([proximaEjecucion($tarea['frecuencia']), $tarea['id']]);
  $GLOBALS['usuario_actual'] ??= usuarioTareas();
  registrar($pdo, $estado === 'ok' ? 'extraer' : 'incidencia', 'tareas', (int)$tarea['id'],
    "Tarea «{$tarea['nombre']}» ($origen): " . ($estado === 'ok' ? "$filas filas en " . basename($archivo) : "ERROR · $mensaje"));
  limpiarExtracciones($pdo, (int)$tarea['id']);
  return ['estado' => $estado, 'archivo' => $archivo ? basename($archivo) : null, 'filas' => $filas, 'ms' => $ms, 'mensaje' => $mensaje];
}

// Una tarea que falla abre una incidencia (si no hay ya una abierta para ella)
function abrirIncidenciaTarea(PDO $pdo, array $tarea, string $mensaje): void {
  try {
    $titulo = mb_substr("Falla la tarea programada «{$tarea['nombre']}»", 0, 255);
    $abierta = $pdo->prepare("SELECT COUNT(*) FROM incidencias WHERE titulo = ? AND estado <> 'resuelta'");
    $abierta->execute([$titulo]);
    if ((int)$abierta->fetchColumn()) return;
    $pdo->prepare('INSERT INTO incidencias (titulo, fecha, tipo, gravedad, estado, descripcion) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$titulo, date('Y-m-d'), 'tarea', 'media', 'abierta', "Error al ejecutar la tarea: $mensaje"]);
  } catch (Throwable) {
    // Sin la tabla de incidencias (antes de la v5.1) solo queda en el registro
  }
}

function ejecutarTareaPorId(PDO $pdo, array $esquema, int $id): array {
  $consulta = $pdo->prepare('SELECT * FROM tareas WHERE id = ?');
  $consulta->execute([$id]);
  $tarea = $consulta->fetch() ?: fallar('Tarea no encontrada', 404);
  return ejecutarTarea($pdo, $esquema, $tarea, 'manual');
}

// Para cron: todas las tareas activas cuya hora ya ha llegado
function ejecutarPendientes(PDO $pdo, array $esquema): array {
  $consulta = $pdo->prepare('SELECT * FROM tareas WHERE activa = 1 AND proxima <= ? ORDER BY proxima');
  $consulta->execute([date('Y-m-d H:i:s')]);
  $resultados = [];
  foreach ($consulta->fetchAll() as $tarea) $resultados[$tarea['nombre']] = ejecutarTarea($pdo, $esquema, $tarea, 'cron');
  return $resultados;
}

// Conserva los últimos archivos de cada tarea y borra los más antiguos
function limpiarExtracciones(PDO $pdo, int $tareaId): void {
  $consulta = $pdo->prepare('SELECT id, archivo FROM tarea_ejecuciones WHERE tarea_id = ? AND archivo IS NOT NULL ORDER BY id DESC');
  $consulta->execute([$tareaId]);
  foreach (array_slice($consulta->fetchAll(), CONSERVAR_EXTRACCIONES) as $vieja) {
    if (is_file($vieja['archivo'])) @unlink($vieja['archivo']);
    $pdo->prepare('UPDATE tarea_ejecuciones SET archivo = NULL WHERE id = ?')->execute([$vieja['id']]);
  }
}

function descargarExtraccion(PDO $pdo, int $id): never {
  $consulta = $pdo->prepare('SELECT archivo FROM tarea_ejecuciones WHERE id = ?');
  $consulta->execute([$id]);
  $archivo = $consulta->fetchColumn();
  // Solo archivos de la carpeta de extracciones (nunca una ruta arbitraria)
  $ruta = $archivo ? realpath($archivo) : false;
  if (!$ruta || dirname($ruta) !== realpath(carpetaExtracciones())) fallar('El archivo ya no está disponible', 404);
  header(str_ends_with($ruta, '.json') ? 'Content-Type: application/json; charset=utf-8' : 'Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . basename($ruta) . '"');
  readfile($ruta);
  exit;
}
