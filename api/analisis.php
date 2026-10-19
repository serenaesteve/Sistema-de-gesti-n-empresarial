<?php
// Análisis (RA3, criterio h): información útil para decidir, sacada de los datos del ERP.
// Cada bloque solo aparece si su módulo está activo y el usuario puede ver esos datos.
//
// · Tendencia del mes: ventas del mes frente al anterior y previsión a fin de mes.
// · Concentración (Pareto): cuántos clientes hacen el 80 % de las ventas.
// · Clientes en riesgo: compraban con regularidad y llevan más de lo normal sin comprar.
// · Cobertura de stock: días que dura cada producto al ritmo de venta actual y cuánto reponer.
// · Margen por producto: precio medio de venta frente al coste medio de compra.
// · Pendiente de facturar y mejor día de la semana.
// · Calidad de los datos: NIF no válidos, contactos sin email ni teléfono y posibles duplicados.
// · Recomendaciones en texto a partir de todo lo anterior.

const DIAS_SEMANA = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

function analisis(PDO $pdo, array $esquema, array $usuario): array {
  $ver = fn(string $recurso) => isset($esquema[$recurso]) && puede($esquema, $usuario, 'ver', $recurso);
  $filas = function (string $sql, array $valores = []) use ($pdo) {
    $consulta = $pdo->prepare($sql);
    $consulta->execute($valores);
    return $consulta->fetchAll();
  };
  $importe = 'l.cantidad * l.precio';
  $ventas = "FROM pedidos pe JOIN pedido_lineas l ON l.pedido_id = pe.id WHERE pe.estado <> 'cancelado'";
  $resultado = ['generado' => date('c')];
  $recomendaciones = [];

  if ($ver('pedidos')) {
    // ---------- Tendencia del mes ----------
    $mes = date('Y-m');
    $anterior = date('Y-m', strtotime('first day of last month'));
    $hastaAnterior = date('Y-m-d', min(strtotime('last day of last month'), strtotime('-1 month')));   // mismo día del mes pasado
    $suma = fn(string $desde, string $hasta) => round((float)($filas("SELECT COALESCE(SUM($importe), 0) AS t $ventas AND pe.fecha BETWEEN ? AND ?", [$desde, $hasta])[0]['t']), 2);
    $actual = $suma("$mes-01", date('Y-m-d'));
    $mismoDia = $suma("$anterior-01", $hastaAnterior);
    $completo = $suma("$anterior-01", date('Y-m-t', strtotime("$anterior-01")));
    $dia = (int)date('j');
    $diasMes = (int)date('t');
    $prevision = round($actual / $dia * $diasMes, 2);
    $resultado['tendencia'] = [
      'mes' => $mes, 'actual' => $actual, 'anterior_mismo_dia' => $mismoDia, 'anterior_completo' => $completo,
      'variacion' => $mismoDia > 0 ? round(($actual - $mismoDia) * 100 / $mismoDia, 1) : null,
      'prevision' => $prevision, 'dia' => $dia, 'dias_mes' => $diasMes,
    ];
    if ($mismoDia > 0 && $actual < $mismoDia * 0.85) $recomendaciones[] = ['tipo' => 'aviso', 'texto' => 'Las ventas del mes van un ' . str_replace('.', ',', (string)abs($resultado['tendencia']['variacion'])) . ' % por debajo del mes pasado a estas alturas. Conviene revisar los clientes en riesgo.'];
    elseif ($mismoDia > 0 && $actual > $mismoDia * 1.15) $recomendaciones[] = ['tipo' => 'bien', 'texto' => 'Las ventas del mes van un ' . str_replace('.', ',', (string)$resultado['tendencia']['variacion']) . ' % por encima del mes pasado.'];

    // ---------- Pareto de clientes (últimos 12 meses) ----------
    $hace12 = date('Y-m-d', strtotime('-12 months'));
    $clientes = $filas("SELECT c.id, c.nombre, c.empresa, SUM($importe) AS importe
      FROM pedidos pe JOIN pedido_lineas l ON l.pedido_id = pe.id JOIN clientes c ON c.id = pe.cliente_id
      WHERE pe.estado <> 'cancelado' AND pe.fecha >= ?
      GROUP BY c.id, c.nombre, c.empresa ORDER BY importe DESC", [$hace12]);
    $total = array_sum(array_map(fn($c) => (float)$c['importe'], $clientes)) ?: 0;
    $acumulado = 0;
    $pareto = [];
    $n80 = 0;
    foreach ($clientes as $i => $c) {
      $acumulado += (float)$c['importe'];
      $pct = $total ? round($acumulado * 100 / $total, 1) : 0;
      if (!$n80 && $pct >= 80) $n80 = $i + 1;
      if ($i < 10) $pareto[] = ['id' => (int)$c['id'], 'nombre' => $c['nombre'], 'empresa' => $c['empresa'], 'importe' => round((float)$c['importe'], 2), 'acumulado' => $pct];
    }
    $resultado['pareto'] = ['total' => round($total, 2), 'clientes' => count($clientes), 'clientes_80' => $n80, 'top' => $pareto];
    if (count($clientes) >= 5 && $n80 && $n80 <= max(1, (int)ceil(count($clientes) * 0.2))) {
      $recomendaciones[] = ['tipo' => 'aviso', 'texto' => "Solo $n80 de " . count($clientes) . ' clientes hacen el 80 % de las ventas: dependes mucho de pocos clientes.'];
    }

    // ---------- Clientes en riesgo ----------
    // Con al menos 2 pedidos: si llevan sin comprar más del doble de su intervalo habitual (y más de 30 días)
    $historial = $filas("SELECT c.id, c.nombre, c.empresa, c.telefono, COUNT(DISTINCT pe.id) AS pedidos, MIN(pe.fecha) AS primero,
        MAX(pe.fecha) AS ultimo, SUM($importe) AS importe
      FROM clientes c JOIN pedidos pe ON pe.cliente_id = c.id JOIN pedido_lineas l ON l.pedido_id = pe.id
      WHERE pe.estado <> 'cancelado' AND c.estado <> 'inactivo'
      GROUP BY c.id, c.nombre, c.empresa, c.telefono HAVING COUNT(DISTINCT pe.id) >= 2");
    $riesgo = [];
    foreach ($historial as $c) {
      $intervalo = max(1, (strtotime($c['ultimo']) - strtotime($c['primero'])) / 86400 / ($c['pedidos'] - 1));
      $sin = (int)((strtotime(date('Y-m-d')) - strtotime($c['ultimo'])) / 86400);
      if ($sin > max(30, 2 * $intervalo)) {
        $riesgo[] = ['id' => (int)$c['id'], 'nombre' => $c['nombre'], 'empresa' => $c['empresa'], 'telefono' => $c['telefono'],
          'ultimo' => $c['ultimo'], 'dias' => $sin, 'intervalo' => (int)round($intervalo), 'importe' => round((float)$c['importe'], 2)];
      }
    }
    usort($riesgo, fn($a, $b) => $b['importe'] <=> $a['importe']);
    $resultado['riesgo'] = array_slice($riesgo, 0, 10);
    if ($riesgo) $recomendaciones[] = ['tipo' => 'accion', 'texto' => count($riesgo) . ' clientes habituales llevan más tiempo del normal sin comprar. Empieza por ' . $riesgo[0]['nombre'] . ' (' . number_format($riesgo[0]['importe'], 2, ',', '.') . ' € comprados).'];

    // ---------- Previsión de ventas (tendencia lineal de los 12 últimos meses cerrados) ----------
    $resultado['prevision'] = previsionVentas($pdo);
    if ($resultado['prevision']['tendencia'] !== null && $resultado['prevision']['fiable']) {
      $t = $resultado['prevision']['tendencia'];
      if ($t < 0) $recomendaciones[] = ['tipo' => 'aviso', 'texto' => 'La tendencia de las ventas es a la baja: unos ' . number_format(abs($t), 0, ',', '.') . ' € menos cada mes.'];
    }

    // ---------- Clasificación de clientes (RFM: cuándo, cada cuánto y cuánto compran) ----------
    $resultado['rfm'] = segmentosRfm($pdo);
    $enRiesgo = array_values(array_filter($resultado['rfm'], fn($g) => $g['id'] === 'riesgo'))[0] ?? null;
    if ($enRiesgo && $enRiesgo['clientes']) $recomendaciones[] = ['tipo' => 'accion', 'texto' => count($enRiesgo['clientes']) . ' clientes que compraban mucho se están enfriando (segmento «En riesgo»): un buen momento para una oferta.'];

    // ---------- Mejor día de la semana (últimos 12 meses) ----------
    $dow = esMysql($pdo) ? 'DAYOFWEEK(pe.fecha) - 1' : "CAST(strftime('%w', pe.fecha) AS INTEGER)";
    $porDia = array_column($filas("SELECT $dow AS dia, COUNT(DISTINCT pe.id) AS pedidos, SUM($importe) AS importe $ventas AND pe.fecha >= ? GROUP BY $dow", [$hace12]), null, 'dia');
    $semana = [];
    foreach ([1, 2, 3, 4, 5, 6, 0] as $d) $semana[] = ['dia' => DIAS_SEMANA[$d], 'pedidos' => (int)($porDia[$d]['pedidos'] ?? 0), 'importe' => round((float)($porDia[$d]['importe'] ?? 0), 2)];
    $resultado['dias_semana'] = $semana;
  }

  if ($ver('productos') && $ver('pedidos')) {
    // ---------- Cobertura de stock (ritmo de los últimos 90 días) ----------
    $cobertura = coberturaStock($pdo);
    $resultado['cobertura'] = $cobertura;
    $urgentes = array_filter($cobertura, fn($p) => $p['dias'] !== null && $p['dias'] < 15 && $p['reponer'] > 0);
    if ($urgentes) {
      $p = reset($urgentes);
      $recomendaciones[] = ['tipo' => 'accion', 'texto' => count($urgentes) . " productos se agotan en menos de 15 días. El más urgente: {$p['nombre']} (quedan {$p['dias']} días; pide unas {$p['reponer']} uds.)."];
    }
    $parados = array_filter($cobertura, fn($p) => $p['vendidas_90'] === 0 && $p['stock'] > 0);
    if ($parados) $recomendaciones[] = ['tipo' => 'aviso', 'texto' => count($parados) . ' productos con stock no se han vendido en 90 días (por ejemplo, ' . reset($parados)['nombre'] . '). Valora una promoción.'];
  }

  if ($ver('productos') && $ver('pedidos') && moduloActivo('compras') && $ver('compras')) {
    // ---------- Margen por producto (últimos 12 meses) ----------
    $hace12 ??= date('Y-m-d', strtotime('-12 months'));
    $margenes = [];
    foreach ($filas("SELECT pr.id, pr.nombre,
        (SELECT SUM(l.cantidad * l.precio) / SUM(l.cantidad) FROM pedido_lineas l JOIN pedidos pe ON pe.id = l.pedido_id
           WHERE l.producto_id = pr.id AND pe.estado <> 'cancelado' AND pe.fecha >= ?) AS venta,
        (SELECT SUM(l.cantidad) FROM pedido_lineas l JOIN pedidos pe ON pe.id = l.pedido_id
           WHERE l.producto_id = pr.id AND pe.estado <> 'cancelado' AND pe.fecha >= ?) AS unidades,
        (SELECT SUM(cl.cantidad * cl.coste) / SUM(cl.cantidad) FROM compra_lineas cl JOIN compras co ON co.id = cl.compra_id
           WHERE cl.producto_id = pr.id AND co.estado = 'recibida') AS coste
      FROM productos pr", [$hace12, $hace12]) as $p) {
      if ($p['venta'] === null || $p['coste'] === null) continue;
      $venta = (float)$p['venta'];
      $coste = (float)$p['coste'];
      $margenes[] = ['id' => (int)$p['id'], 'nombre' => $p['nombre'], 'venta' => round($venta, 2), 'coste' => round($coste, 2),
        'margen' => $venta > 0 ? round(($venta - $coste) * 100 / $venta, 1) : 0, 'beneficio' => round(($venta - $coste) * (int)$p['unidades'], 2)];
    }
    usort($margenes, fn($a, $b) => $b['beneficio'] <=> $a['beneficio']);
    $resultado['margenes'] = $margenes;
    $bajos = array_filter($margenes, fn($m) => $m['margen'] < 15);
    if ($bajos) $recomendaciones[] = ['tipo' => 'aviso', 'texto' => count($bajos) . ' productos dejan menos del 15 % de margen (por ejemplo, ' . reset($bajos)['nombre'] . ', ' . str_replace('.', ',', (string)reset($bajos)['margen']) . ' %). Revisa su precio o su proveedor.'];
  }

  if ($ver('facturas') && $ver('pedidos')) {
    // ---------- Pendiente de facturar ----------
    $pendiente = $filas("SELECT COUNT(DISTINCT pe.id) AS pedidos, COALESCE(SUM($importe), 0) AS importe, MIN(pe.fecha) AS desde
      FROM pedidos pe JOIN pedido_lineas l ON l.pedido_id = pe.id
      WHERE pe.estado = 'entregado' AND NOT EXISTS (SELECT 1 FROM facturas f WHERE f.pedido_id = pe.id AND f.tipo = 'ordinaria'
        AND NOT EXISTS (SELECT 1 FROM facturas r WHERE r.rectifica_id = f.id))")[0];
    $resultado['sin_facturar'] = ['pedidos' => (int)$pendiente['pedidos'], 'importe' => round((float)$pendiente['importe'], 2), 'desde' => $pendiente['desde']];
    if ((int)$pendiente['pedidos'] > 0) $recomendaciones[] = ['tipo' => 'accion', 'texto' => "Hay {$pendiente['pedidos']} pedidos entregados sin facturar por " . number_format((float)$pendiente['importe'], 2, ',', '.') . ' € (sin IVA).'];
  }

  // ---------- Calidad de los datos (importantes al cargar datos reales) ----------
  $calidad = calidadDatos($pdo, $esquema, $usuario);
  foreach ($calidad as $c) {
    if ($c['nif_no_valido']) $recomendaciones[] = ['tipo' => 'aviso', 'texto' => "{$c['nif_no_valido']} " . mb_strtolower($c['titulo']) . " tienen un NIF/CIF no válido (por ejemplo, {$c['ejemplos_nif'][0]['nombre']}: {$c['ejemplos_nif'][0]['nif']}). Corrígelos antes de facturar."];
    if ($c['duplicados']) $recomendaciones[] = ['tipo' => 'accion', 'texto' => "Hay {$c['duplicados']} " . ($c['duplicados'] === 1
      ? $esquema[$c['recurso']]['singular'] . ' que parece repetido' : mb_strtolower($c['titulo']) . ' que parecen repetidos') . '. Revísalos y fusiónalos en «Calidad de los datos».'];
  }
  if ($calidad) $resultado['calidad'] = $calidad;

  if (!$recomendaciones) $recomendaciones[] = ['tipo' => 'bien', 'texto' => 'No hay nada urgente: ventas, stock y facturación están en orden.'];
  $resultado['recomendaciones'] = $recomendaciones;
  return $resultado;
}

// Días que dura el stock de cada producto al ritmo de venta de los últimos 90 días y cuánto pedir (stock para 45 días)
function coberturaStock(PDO $pdo): array {
  $hace90 = date('Y-m-d', strtotime('-90 days'));
  $enCamino = moduloActivo('compras')
    ? "(SELECT COALESCE(SUM(cl.cantidad), 0) FROM compra_lineas cl JOIN compras co ON co.id = cl.compra_id WHERE cl.producto_id = pr.id AND co.estado = 'pedida')"
    : '0';
  $consulta = $pdo->prepare("SELECT pr.id, pr.nombre, pr.stock, pr.precio, $enCamino AS en_camino,
      (SELECT COALESCE(SUM(l.cantidad), 0) FROM pedido_lineas l JOIN pedidos pe ON pe.id = l.pedido_id
         WHERE l.producto_id = pr.id AND pe.estado <> 'cancelado' AND pe.fecha >= ?) AS vendidas
    FROM productos pr");
  $consulta->execute([$hace90]);
  $productos = $consulta->fetchAll();
  $cobertura = [];
  foreach ($productos as $p) {
    $ritmo = (int)$p['vendidas'] / 90;
    $dias = $ritmo > 0 ? (int)floor((int)$p['stock'] / $ritmo) : null;
    $reponer = $ritmo > 0 ? max(0, (int)ceil($ritmo * 45) - (int)$p['stock'] - (int)$p['en_camino']) : 0;   // stock para 45 días
    $cobertura[] = ['id' => (int)$p['id'], 'nombre' => $p['nombre'], 'stock' => (int)$p['stock'], 'en_camino' => (int)$p['en_camino'],
      'vendidas_90' => (int)$p['vendidas'], 'por_semana' => round($ritmo * 7, 1), 'dias' => $dias, 'reponer' => $reponer];
  }
  usort($cobertura, fn($a, $b) => ($a['dias'] ?? PHP_INT_MAX) <=> ($b['dias'] ?? PHP_INT_MAX));
  return $cobertura;
}

// NIF no válidos, contactos sin email ni teléfono y posibles duplicados (para cargar datos reales)
function calidadDatos(PDO $pdo, array $esquema, array $usuario): array {
  $calidad = [];
  foreach (RECURSOS_DUPLICADOS as $recurso) {
    if (!isset($esquema[$recurso]) || !puede($esquema, $usuario, 'ver', $recurso)) continue;
    $filas = $pdo->query("SELECT id, nombre, nif, email, telefono FROM $recurso")->fetchAll();
    $nifMal = array_values(array_filter($filas, fn($f) => $f['nif'] !== null && $f['nif'] !== '' && !nifValido((string)$f['nif'])));
    $sinContacto = array_filter($filas, fn($f) => trim((string)$f['email']) === '' && trim((string)$f['telefono']) === '');
    $grupos = gruposDuplicados($pdo, $esquema, $recurso);
    $calidad[] = ['recurso' => $recurso, 'titulo' => $esquema[$recurso]['titulo'], 'total' => count($filas),
      'nif_no_valido' => count($nifMal), 'ejemplos_nif' => array_map(fn($f) => ['id' => (int)$f['id'], 'nombre' => $f['nombre'], 'nif' => $f['nif']], array_slice($nifMal, 0, 5)),
      'sin_contacto' => count($sinContacto), 'duplicados' => array_sum(array_map(fn($g) => count($g) - 1, $grupos)), 'grupos' => count($grupos),
      'puede_fusionar' => puede($esquema, $usuario, 'editar', $recurso) && puede($esquema, $usuario, 'borrar', $recurso)];
  }
  return $calidad;
}

// Regresión lineal sobre los 12 últimos meses cerrados; se prevén el mes en curso y los dos siguientes
function previsionVentas(PDO $pdo): array {
  $serie = ventasPorMes($pdo, 13);
  $historico = array_slice($serie, 0, 12);
  $n = count($historico);
  $xs = range(0, $n - 1);
  $ys = array_column($historico, 'total');
  $mx = array_sum($xs) / $n;
  $my = array_sum($ys) / $n;
  $sxy = $sxx = $syy = 0;
  foreach ($xs as $i => $x) { $sxy += ($x - $mx) * ($ys[$i] - $my); $sxx += ($x - $mx) ** 2; $syy += ($ys[$i] - $my) ** 2; }
  $pendiente = $sxx ? $sxy / $sxx : 0;
  $origen = $my - $pendiente * $mx;
  $r2 = $syy ? ($sxy ** 2) / ($sxx * $syy) : 0;
  $conVentas = count(array_filter($ys, fn($y) => $y > 0));
  $mes = new DateTime('first day of this month');
  $prevision = [];
  for ($i = 0; $i < 3; $i++) {
    $prevision[] = ['mes' => $mes->format('Y-m'), 'total' => round(max(0, $origen + $pendiente * ($n + $i)), 2), 'prevision' => true,
      'real' => $i === 0 ? end($serie)['total'] : null];
    $mes->modify('+1 month');
  }
  return [
    'historico' => array_map(fn($m) => ['mes' => $m['mes'], 'total' => $m['total'], 'prevision' => false], $historico),
    'prevision' => $prevision,
    'tendencia' => $conVentas >= 3 ? round($pendiente, 2) : null,   // € más (o menos) cada mes
    'r2' => round($r2, 2),
    // Con menos de 6 meses con ventas o una tendencia que explica poco, la previsión es orientativa
    'fiable' => $conVentas >= 6 && $r2 >= 0.3,
    'meses_con_ventas' => $conVentas,
  ];
}

// RFM: cada cliente con compras recibe de 1 a 5 puntos en recencia (días desde la última compra),
// frecuencia (pedidos) e importe, según su posición entre todos los clientes; con eso se agrupa.
function segmentosRfm(PDO $pdo): array {
  $clientes = $pdo->query("SELECT c.id, c.nombre, c.empresa, MAX(pe.fecha) AS ultimo, COUNT(DISTINCT pe.id) AS pedidos,
      SUM(l.cantidad * l.precio) AS importe
    FROM clientes c JOIN pedidos pe ON pe.cliente_id = c.id JOIN pedido_lineas l ON l.pedido_id = pe.id
    WHERE pe.estado <> 'cancelado' GROUP BY c.id, c.nombre, c.empresa")->fetchAll();
  $definicion = [
    'campeones' => ['Campeones', 'Compran a menudo, hace poco y mucho: cuídalos.'],
    'leales'    => ['Leales', 'Compran con frecuencia.'],
    'nuevos'    => ['Nuevos o recientes', 'Han comprado hace poco, pero aún pocas veces.'],
    'riesgo'    => ['En riesgo', 'Compraban bastante, pero hace tiempo que no.'],
    'dormidos'  => ['Dormidos', 'Pocas compras y hace mucho de la última.'],
    'otros'     => ['Ocasionales', 'Compras sueltas, sin un patrón claro.'],
  ];
  $grupos = array_fill_keys(array_keys($definicion), []);
  $n = count($clientes);
  if ($n) {
    $hoy = strtotime(date('Y-m-d'));
    foreach ($clientes as &$c) $c['dias'] = (int)(($hoy - strtotime($c['ultimo'])) / 86400);
    unset($c);
    // Puntuación por posición (quintiles): 5 = de los mejores
    $puntuar = function (string $campo, bool $menorEsMejor) use (&$clientes, $n) {
      $orden = $clientes;
      usort($orden, fn($a, $b) => $menorEsMejor ? $b[$campo] <=> $a[$campo] : $a[$campo] <=> $b[$campo]);
      $puntos = [];
      foreach ($orden as $i => $c) $puntos[$c['id']] = (int)ceil(($i + 1) * 5 / $n);
      return $puntos;
    };
    $r = $puntuar('dias', true);
    $f = $puntuar('pedidos', false);
    $m = $puntuar('importe', false);
    foreach ($clientes as $c) {
      [$pr, $pf, $pm] = [$r[$c['id']], $f[$c['id']], $m[$c['id']]];
      $segmento = match (true) {
        $pr >= 4 && $pf >= 4 && $pm >= 4 => 'campeones',
        $pr <= 2 && ($pf >= 3 || $pm >= 4) => 'riesgo',
        $pf >= 4 => 'leales',
        $pr >= 4 && $pf <= 2 => 'nuevos',
        $pr <= 2 && $pf <= 2 => 'dormidos',
        default => 'otros',
      };
      $grupos[$segmento][] = ['id' => (int)$c['id'], 'nombre' => $c['nombre'], 'empresa' => $c['empresa'], 'dias' => $c['dias'],
        'pedidos' => (int)$c['pedidos'], 'importe' => round((float)$c['importe'], 2), 'puntos' => "$pr$pf$pm"];
    }
  }
  return array_map(function ($id) use ($definicion, $grupos) {
    $lista = $grupos[$id];
    usort($lista, fn($a, $b) => $b['importe'] <=> $a['importe']);
    return ['id' => $id, 'nombre' => $definicion[$id][0], 'descripcion' => $definicion[$id][1], 'clientes' => $lista,
      'importe' => round(array_sum(array_column($lista, 'importe')), 2)];
  }, array_keys($definicion));
}

// Avisos para el panel de inicio: lo que pide atención hoy, cada uno con su enlace
function alertas(PDO $pdo, array $esquema, array $usuario): array {
  $ver = fn(string $r) => isset($esquema[$r]) && puede($esquema, $usuario, 'ver', $r);
  $alertas = [];
  try {
    if ($ver('productos') && $ver('pedidos')) {
      $agotan = array_filter(coberturaStock($pdo), fn($p) => $p['dias'] !== null && $p['dias'] < 15 && $p['reponer'] > 0);
      if ($agotan) $alertas[] = ['tipo' => 'aviso', 'icono' => 'productos', 'enlace' => '#/analisis',
        'texto' => count($agotan) . (count($agotan) === 1 ? ' producto se agota' : ' productos se agotan') . ' en menos de 15 días al ritmo actual (' . reset($agotan)['nombre'] . ', ' . reset($agotan)['dias'] . ' días).'];
    }
    if ($usuario['rol'] === 'admin') {
      $fallidas = $pdo->query("SELECT t.nombre, e.mensaje FROM tareas t JOIN tarea_ejecuciones e ON e.id = (SELECT MAX(id) FROM tarea_ejecuciones WHERE tarea_id = t.id)
        WHERE t.activa = 1 AND e.estado = 'error'")->fetchAll();
      foreach ($fallidas as $f) $alertas[] = ['tipo' => 'error', 'icono' => 'tareas', 'enlace' => '#/tareas', 'texto' => "La tarea «{$f['nombre']}» falló en su última ejecución: {$f['mensaje']}"];
      $lentas = (int)$pdo->query("SELECT COUNT(*) FROM consultas_lentas WHERE creado >= '" . gmdate('Y-m-d H:i:s', time() - 86400) . "'")->fetchColumn();
      if ($lentas && umbralLenta() > 0) $alertas[] = ['tipo' => 'aviso', 'icono' => 'rendimiento', 'enlace' => '#/rendimiento', 'texto' => "$lentas consultas lentas en las últimas 24 horas."];
    }
    if ($ver('incidencias')) {
      $graves = (int)$pdo->query("SELECT COUNT(*) FROM incidencias WHERE estado <> 'resuelta' AND gravedad = 'alta'")->fetchColumn();
      $abiertas = (int)$pdo->query("SELECT COUNT(*) FROM incidencias WHERE estado <> 'resuelta'")->fetchColumn();
      if ($abiertas) $alertas[] = ['tipo' => $graves ? 'error' : 'aviso', 'icono' => 'alerta', 'enlace' => '#/incidencias',
        'texto' => "$abiertas " . ($abiertas === 1 ? 'incidencia sin resolver' : 'incidencias sin resolver') . ($graves ? " ($graves de gravedad alta)" : '') . '.'];
    }
    foreach (calidadDatos($pdo, $esquema, $usuario) as $c) {
      if ($c['nif_no_valido']) $alertas[] = ['tipo' => 'aviso', 'icono' => $c['recurso'], 'enlace' => '#/analisis',
        'texto' => "{$c['nif_no_valido']} " . mb_strtolower($c['titulo']) . ' con el NIF/CIF mal.'];
    }
    if ($ver('clientes')) {
      $leads = (int)$pdo->query("SELECT COUNT(*) FROM formulario_envios WHERE creado >= '" . gmdate('Y-m-d H:i:s', time() - 7 * 86400) . "'")->fetchColumn();
      if ($leads) $alertas[] = ['tipo' => 'info', 'icono' => 'formularios', 'enlace' => '#/clientes', 'texto' => "$leads " . ($leads === 1 ? 'alta nueva' : 'altas nuevas') . ' por los formularios web en los últimos 7 días.'];
    }
  } catch (PDOException) {
    // Antes de actualizar, alguna tabla puede no existir todavía
  }
  return $alertas;
}
