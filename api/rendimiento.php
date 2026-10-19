<?php
// Rendimiento (RA3, criterio f): estado del servidor y tiempos de respuesta del ERP.
//
// · Muestras del servidor (CPU, memoria, disco, carga y tamaño de la base de datos) en «metricas».
//   Las toma scripts/monitor.php (cron, cada minuto) y también esta pantalla al abrirse.
// · Cada petición a la API anota su recurso, método, código y milisegundos en «peticiones».
// · «Medir ahora» ejecuta varias veces las consultas más pesadas y da sus tiempos.
// · Consultas lentas (v5.1): las de informes, constructor, consola y listados que pasan del umbral se
//   guardan con su SQL, y se puede ver cómo las ejecuta la base de datos («Explicar»).

const DIAS_PETICIONES = 7;
const DIAS_METRICAS = 30;
const UMBRALES = ['cpu' => 80, 'ram' => 85, 'disco' => 90, 'p95' => 500];   // % y milisegundos

// A partir de cuántos milisegundos una consulta se considera lenta (las pruebas usan 0 para anotarlas todas)
function umbralLenta(): float {
  $valor = getenv('ERP_UMBRAL_LENTA');
  return $valor !== false && is_numeric($valor) ? (float)$valor : 500.0;
}

function anotarLenta(PDO $pdo, string $origen, string $detalle, string $sql, array $valores, float $ms, int $filas): void {
  if ($ms < umbralLenta()) return;
  try {
    $pdo->prepare('INSERT INTO consultas_lentas (origen, detalle, sql, valores, ms, filas) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$origen, mb_substr($detalle, 0, 255), mb_substr($sql, 0, 20000), json_encode($valores, JSON_UNESCAPED_UNICODE), round($ms, 2), $filas]);
    if (random_int(1, 100) === 1) $pdo->prepare('DELETE FROM consultas_lentas WHERE creado < ?')->execute([gmdate('Y-m-d H:i:s', time() - DIAS_METRICAS * 86400)]);
  } catch (Throwable) {
    // Antes de actualizar a la 5.1 la tabla no existe: medir nunca puede romper una respuesta
  }
}

function consultasLentas(PDO $pdo, int $horas): array {
  $consulta = $pdo->prepare('SELECT id, origen, detalle, sql, ms, filas, creado FROM consultas_lentas WHERE creado >= ? ORDER BY ms DESC LIMIT 20');
  $consulta->execute([gmdate('Y-m-d H:i:s', time() - $horas * 3600)]);
  return array_map(fn($f) => ['id' => (int)$f['id'], 'origen' => $f['origen'], 'detalle' => $f['detalle'], 'sql' => preg_replace('/\s+/', ' ', $f['sql']),
    'ms' => (float)$f['ms'], 'filas' => (int)$f['filas'], 'creado' => $f['creado']], $consulta->fetchAll());
}

// Plan de una consulta lenta guardada, con sus valores, en una conexión de solo lectura
function explicarLenta(PDO $pdo, array $config, array $esquema, int $id): array {
  $consulta = $pdo->prepare('SELECT sql, valores FROM consultas_lentas WHERE id = ?');
  $consulta->execute([$id]);
  $fila = $consulta->fetch() ?: fallar('Consulta no encontrada', 404);
  $lectura = conectar($config, $esquema);
  if (esMysql($lectura)) { $lectura->exec('SET SESSION TRANSACTION READ ONLY'); } else { $lectura->exec('PRAGMA query_only = ON'); }
  return explicarConsulta($lectura, $fila['sql'], json_decode((string)$fila['valores'], true) ?: []) + ['sql' => $fila['sql']];
}

// CPU ocupada (%): dos lecturas de /proc/stat separadas por un momento
function usoCpu(): ?float {
  $leer = function () {
    $linea = @file('/proc/stat')[0] ?? '';
    if (!str_starts_with($linea, 'cpu ')) return null;
    $v = array_map('intval', preg_split('/\s+/', trim(substr($linea, 4))));
    return [array_sum($v), ($v[3] ?? 0) + ($v[4] ?? 0)];   // total, inactivo (idle + iowait)
  };
  $a = $leer();
  if (!$a) return null;
  usleep(250000);
  $b = $leer();
  $total = $b[0] - $a[0];
  return $total > 0 ? round(100 * (1 - ($b[1] - $a[1]) / $total), 1) : 0.0;
}

function usoRam(): ?float {
  $info = @file_get_contents('/proc/meminfo');
  if (!$info || !preg_match('/MemTotal:\s+(\d+)/', $info, $t) || !preg_match('/MemAvailable:\s+(\d+)/', $info, $d)) return null;
  return round(100 * (1 - $d[1] / $t[1]), 1);
}

function tamanoBd(PDO $pdo, array $config): int {
  if (esMysql($pdo)) {
    return (int)($pdo->query('SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() / 1024);
  }
  return (int)(@filesize($config['sqlite']) / 1024);
}

function tomarMuestra(PDO $pdo, array $config): array {
  $carpeta = dirname(rutaInstalacion());
  $total = @disk_total_space($carpeta) ?: 0;
  $muestra = [
    'cpu' => usoCpu(), 'ram' => usoRam(),
    'disco' => $total ? round(100 * (1 - disk_free_space($carpeta) / $total), 1) : null,
    'carga' => function_exists('sys_getloadavg') ? round(sys_getloadavg()[0], 2) : null,
    'bd_kb' => tamanoBd($pdo, $config),
  ];
  $pdo->prepare('INSERT INTO metricas (cpu, ram, disco, carga, bd_kb) VALUES (?, ?, ?, ?, ?)')->execute(array_values($muestra));
  $pdo->prepare('DELETE FROM metricas WHERE creado < ?')->execute([gmdate('Y-m-d H:i:s', time() - DIAS_METRICAS * 86400)]);
  return $muestra;
}

// Se llama al terminar cada petición (register_shutdown_function en api.php)
function anotarPeticion(PDO $pdo, string $recurso, string $metodo, float $inicio): void {
  try {
    $ms = round((hrtime(true) - $inicio) / 1e6, 2);
    $pdo->prepare('INSERT INTO peticiones (recurso, metodo, codigo, ms) VALUES (?, ?, ?, ?)')
        ->execute([mb_substr($recurso ?: '(ninguno)', 0, 40), $metodo, http_response_code() ?: 200, $ms]);
    // Limpieza de vez en cuando (1 de cada 200 peticiones)
    if (random_int(1, 200) === 1) {
      $pdo->prepare('DELETE FROM peticiones WHERE creado < ?')->execute([gmdate('Y-m-d H:i:s', time() - DIAS_PETICIONES * 86400)]);
    }
  } catch (Throwable) {
    // Medir nunca puede romper una respuesta
  }
}

function percentil(array $valores, float $p): float {
  if (!$valores) return 0;
  sort($valores);
  return round($valores[(int)min(count($valores) - 1, floor($p / 100 * count($valores)))], 1);
}

function datosRendimiento(PDO $pdo, array $config, array $esquema, int $horas): array {
  $horas = max(1, min(24 * DIAS_METRICAS, $horas));
  // Si la última muestra tiene más de un minuto, se toma una nueva al abrir la pantalla
  $ultima = $pdo->query('SELECT MAX(creado) FROM metricas')->fetchColumn();
  if (!$ultima || strtotime($ultima . ' UTC') < time() - 60) tomarMuestra($pdo, $config);

  $desde = gmdate('Y-m-d H:i:s', time() - $horas * 3600);
  $consulta = $pdo->prepare('SELECT * FROM metricas WHERE creado >= ? ORDER BY id');
  $consulta->execute([$desde]);
  $metricas = array_map(fn($m) => [
    'fecha' => $m['creado'], 'cpu' => $m['cpu'] === null ? null : (float)$m['cpu'], 'ram' => $m['ram'] === null ? null : (float)$m['ram'],
    'disco' => $m['disco'] === null ? null : (float)$m['disco'], 'carga' => $m['carga'] === null ? null : (float)$m['carga'], 'bd_kb' => (int)$m['bd_kb'],
  ], $consulta->fetchAll());
  // Como mucho 240 puntos por gráfico: se toma una muestra de cada N
  if (count($metricas) > 240) {
    $paso = (int)ceil(count($metricas) / 240);
    $metricas = array_values(array_filter($metricas, fn($i) => $i % $paso === 0 || $i === count($metricas) - 1, ARRAY_FILTER_USE_KEY));
  }

  $consulta = $pdo->prepare('SELECT recurso, metodo, codigo, ms, creado FROM peticiones WHERE creado >= ?');
  $consulta->execute([$desde]);
  $peticiones = $consulta->fetchAll();
  $porRecurso = [];
  foreach ($peticiones as $p) $porRecurso[$p['recurso']][] = $p;
  $recursos = [];
  foreach ($porRecurso as $recurso => $lista) {
    $ms = array_map(fn($p) => (float)$p['ms'], $lista);
    $recursos[] = [
      'recurso' => $recurso, 'peticiones' => count($lista), 'media' => round(array_sum($ms) / count($ms), 1),
      'p95' => percentil($ms, 95), 'maximo' => round(max($ms), 1),
      'errores' => count(array_filter($lista, fn($p) => (int)$p['codigo'] >= 500)),
      'rechazos' => count(array_filter($lista, fn($p) => (int)$p['codigo'] >= 400 && (int)$p['codigo'] < 500)),
    ];
  }
  usort($recursos, fn($a, $b) => $b['p95'] <=> $a['p95']);

  // Peticiones por hora (para el gráfico de actividad)
  $porHora = [];
  foreach ($peticiones as $p) {
    $hora = substr($p['creado'], 0, 13);
    $porHora[$hora] ??= ['hora' => $hora, 'peticiones' => 0, 'ms' => []];
    $porHora[$hora]['peticiones']++;
    $porHora[$hora]['ms'][] = (float)$p['ms'];
  }
  ksort($porHora);
  $actividad = array_values(array_map(fn($h) => ['hora' => $h['hora'], 'peticiones' => $h['peticiones'], 'p95' => percentil($h['ms'], 95)], $porHora));

  $todas = array_map(fn($p) => (float)$p['ms'], $peticiones);
  $actual = end($metricas) ?: null;
  $avisos = [];
  if ($actual) {
    foreach (['cpu' => 'El procesador', 'ram' => 'La memoria', 'disco' => 'El disco'] as $c => $nombre) {
      if ($actual[$c] !== null && $actual[$c] >= UMBRALES[$c]) $avisos[] = "$nombre está al {$actual[$c]} % (aviso a partir del " . UMBRALES[$c] . ' %).';
    }
  }
  if ($todas && percentil($todas, 95) >= UMBRALES['p95']) $avisos[] = 'El 5 % de las peticiones más lentas tarda más de ' . UMBRALES['p95'] . ' ms.';
  $errores = count(array_filter($peticiones, fn($p) => (int)$p['codigo'] >= 500));
  if ($errores) $avisos[] = "$errores peticiones han terminado con error del servidor: revisa el registro y el log de Apache.";

  return [
    'horas' => $horas, 'umbrales' => UMBRALES, 'actual' => $actual, 'metricas' => $metricas,
    'resumen' => [
      'peticiones' => count($peticiones), 'media' => $todas ? round(array_sum($todas) / count($todas), 1) : 0,
      'p95' => percentil($todas, 95), 'errores' => $errores,
    ],
    'recursos' => $recursos, 'actividad' => $actividad,
    'tablas' => contarFilas($pdo, $esquema), 'avisos' => $avisos,
    'lentas' => consultasLentas($pdo, $horas), 'umbral_lenta' => umbralLenta(),
    'monitor' => '* * * * * php ' . realpath(__DIR__ . '/../scripts/monitor.php') . ' > /dev/null 2>&1',
  ];
}

// Filas de cada tabla: para ver cuánto crecen los datos reales
function contarFilas(PDO $pdo, array $esquema): array {
  $filas = [];
  foreach (array_keys(tablas($esquema)) as $tabla) {
    $filas[] = ['tabla' => $tabla, 'filas' => (int)$pdo->query("SELECT COUNT(*) FROM $tabla")->fetchColumn()];
  }
  usort($filas, fn($a, $b) => $b['filas'] <=> $a['filas']);
  return $filas;
}

// Prueba de velocidad: cada consulta pesada se repite 5 veces (lectura, no cambia nada)
function medirRendimiento(PDO $pdo, array $config, array $esquema, array $usuario): array {
  $pruebas = [];
  foreach (informesDisponibles($esquema, $usuario) as $informe) {
    $pruebas["Informe · {$informe['titulo']}"] = fn() => ejecutarInforme($pdo, $esquema, $usuario, $informe['id'], ['agrupacion' => 'mes']);
  }
  foreach ($esquema as $recurso => $modulo) {
    if (!puede($esquema, $usuario, 'ver', $recurso)) continue;
    $pruebas["Listado · {$modulo['titulo']}"] = function () use ($pdo, $esquema, $recurso) {
      [$sql] = consultaBase($esquema, $recurso);
      $pdo->query("$sql ORDER BY t.id DESC LIMIT 25")->fetchAll();
    };
  }
  if (function_exists('panel') && moduloActivo('pedidos')) $pruebas['Panel de inicio'] = fn() => panel($pdo, $esquema);

  $resultados = [];
  foreach ($pruebas as $nombre => $prueba) {
    $tiempos = [];
    for ($i = 0; $i < 5; $i++) {
      $inicio = hrtime(true);
      $prueba();
      $tiempos[] = (hrtime(true) - $inicio) / 1e6;
    }
    $resultados[] = ['prueba' => $nombre, 'media' => round(array_sum($tiempos) / 5, 2), 'minimo' => round(min($tiempos), 2), 'maximo' => round(max($tiempos), 2)];
  }
  usort($resultados, fn($a, $b) => $b['media'] <=> $a['media']);
  $total = round(array_sum(array_column($resultados, 'media')), 1);
  registrar($pdo, 'medir', 'rendimiento', null, count($resultados) . " pruebas de velocidad · la más lenta: {$resultados[0]['prueba']} ({$resultados[0]['media']} ms)");
  return ['pruebas' => $resultados, 'total' => $total, 'motor' => $config['motor'], 'php' => PHP_VERSION];
}
