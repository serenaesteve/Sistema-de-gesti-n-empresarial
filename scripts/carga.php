<?php
// Prueba de carga (RA3, criterio f): ¿aguanta el ERP los datos reales de una empresa durante años?
// Instala el ERP en una base de datos TEMPORAL (no toca la real), la llena con miles de clientes,
// productos y pedidos, y mide cuánto tardan los listados, los informes, el panel y el análisis.
//
//   php scripts/carga.php [--clientes=2000] [--pedidos=20000] [--repeticiones=3]

$opciones = getopt('', ['clientes:', 'pedidos:', 'productos:', 'repeticiones:']);
$temporal = sys_get_temp_dir() . '/blush-carga-' . getmypid();
mkdir($temporal);
putenv("ERP_SQLITE=$temporal/erp.sqlite");
putenv("ERP_INSTALACION=$temporal/instalacion.json");
require __DIR__ . '/comun.php';

$nClientes  = max(10, (int)($opciones['clientes'] ?? 2000));
$nPedidos   = max(10, (int)($opciones['pedidos'] ?? 20000));
$nProductos = max(5, (int)($opciones['productos'] ?? 300));
$repeticiones = max(1, (int)($opciones['repeticiones'] ?? 3));

$medir = function (callable $f) use ($repeticiones): float {
  $tiempos = [];
  for ($i = 0; $i < $repeticiones; $i++) { $t = hrtime(true); $f(); $tiempos[] = (hrtime(true) - $t) / 1e6; }
  sort($tiempos);
  return $tiempos[intdiv(count($tiempos), 2)];   // mediana
};

try {
  echo "Blush ERP " . VERSION_APP . " · prueba de carga en $temporal\n\n";
  instalar(conInstalacion($config), $esquema, [
    'perfil' => 'distribuidora', 'modulos' => [], 'motor' => 'sqlite', 'entorno' => 'pruebas', 'demo' => true,
    'empresa' => ['nombre' => 'Carga S.L.', 'nif' => 'B12345674', 'direccion' => '', 'email' => ''],
    'admin' => ['nombre' => 'Admin', 'email' => 'admin@carga.test', 'clave' => 'carga12345'],
  ]);
  [$pdo, $config] = arrancar($config, $esquema);
  $admin = ['id' => 1, 'nombre' => 'Admin', 'email' => 'admin@carga.test', 'rol' => 'admin', 'permisos' => null];
  $GLOBALS['usuario_actual'] = $admin;

  // ---------- Datos ----------
  $t = hrtime(true);
  mt_srand(2026);
  $pdo->beginTransaction();
  $ciudades = ['Valencia', 'Madrid', 'Barcelona', 'Sevilla', 'Bilbao', 'Zaragoza', 'Alicante', 'Málaga'];
  $alta = $pdo->prepare('INSERT INTO clientes (nombre, empresa, email, ciudad, estado) VALUES (?, ?, ?, ?, ?)');
  for ($i = 1; $i <= $nClientes; $i++) $alta->execute(["Cliente $i", "Empresa $i S.L.", "cliente$i@carga.test", $ciudades[$i % 8], ['activo', 'activo', 'potencial', 'inactivo'][$i % 4]]);
  $alta = $pdo->prepare('INSERT INTO productos (nombre, categoria, precio, stock) VALUES (?, ?, ?, ?)');
  for ($i = 1; $i <= $nProductos; $i++) $alta->execute(["Producto $i", 'Categoría ' . ($i % 12), mt_rand(500, 90000) / 100, mt_rand(0, 400)]);
  $clientes = $pdo->query('SELECT id FROM clientes')->fetchAll(PDO::FETCH_COLUMN);
  $productos = $pdo->query('SELECT id, precio FROM productos')->fetchAll(PDO::FETCH_KEY_PAIR);
  $ids = array_keys($productos);
  $pedido = $pdo->prepare('INSERT INTO pedidos (cliente_id, fecha, estado) VALUES (?, ?, ?)');
  $linea = $pdo->prepare('INSERT INTO pedido_lineas (pedido_id, producto_id, cantidad, precio) VALUES (?, ?, ?, ?)');
  $lineas = 0;
  for ($i = 0; $i < $nPedidos; $i++) {
    $pedido->execute([$clientes[array_rand($clientes)], date('Y-m-d', strtotime('-' . mt_rand(0, 3 * 365) . ' days')),
      ['entregado', 'entregado', 'entregado', 'enviado', 'pendiente', 'cancelado'][mt_rand(0, 5)]]);
    $id = (int)$pdo->lastInsertId();
    for ($j = 0, $n = mt_rand(1, 4); $j < $n; $j++, $lineas++) {
      $p = $ids[array_rand($ids)];
      $linea->execute([$id, $p, mt_rand(1, 10), $productos[$p]]);
    }
  }
  $pdo->commit();
  printf("Datos: %s clientes, %s productos, %s pedidos, %s líneas (%.2f s)\n\n",
    number_format($nClientes, 0, ',', '.'), number_format($nProductos, 0, ',', '.'), number_format($nPedidos, 0, ',', '.'),
    number_format($lineas, 0, ',', '.'), (hrtime(true) - $t) / 1e9);

  // ---------- Medidas ----------
  $pruebas = [
    'Listado de clientes (página 1)' => function () use ($pdo, $config, $esquema) { $_GET = ['pagina' => 1]; listar($pdo, $config, $esquema, 'clientes'); },
    'Búsqueda de clientes «Valencia»' => function () use ($pdo, $config, $esquema) { $_GET = ['pagina' => 1, 'q' => 'Valencia']; listar($pdo, $config, $esquema, 'clientes'); },
    'Listado de pedidos (página 1)' => function () use ($pdo, $config, $esquema) { $_GET = ['pagina' => 1]; listar($pdo, $config, $esquema, 'pedidos'); },
    'Pedidos de un cliente' => function () use ($pdo, $config, $esquema) { $_GET = ['pagina' => 1, 'cliente_id' => 7]; listar($pdo, $config, $esquema, 'pedidos'); },
    'Panel de inicio' => function () use ($pdo, $esquema) { $_GET = []; panel($pdo, $esquema); },
    'Análisis' => fn() => analisis($pdo, $esquema, $admin),
  ];
  foreach (informesDisponibles($esquema, $admin) as $i) {
    $pruebas["Informe · {$i['titulo']}"] = fn() => ejecutarInforme($pdo, $esquema, $admin, $i['id'], ['desde' => date('Y-m-d', strtotime('-3 years')), 'agrupacion' => 'mes']);
  }
  $pruebas['Constructor: ventas por ciudad'] = fn() => ejecutarConstructor($pdo, $esquema, $admin, ['recurso' => 'clientes', 'agrupar' => 'ciudad', 'agregacion' => 'contar']);
  $pruebas['Constructor: clientes × meses (tabla cruzada)'] = fn() => ejecutarConstructor($pdo, $esquema, $admin, ['recurso' => 'pedidos', 'agrupar' => 'cliente', 'cruzar' => 'fecha__mes', 'agregacion' => 'contar']);
  $pruebas['Avisos del panel'] = fn() => alertas($pdo, $esquema, $admin);
  $pruebas['Informe comparado con el año pasado'] = fn() => ejecutarInforme($pdo, $esquema, $admin, 'ventas_cliente', ['desde' => date('Y-01-01'), 'comparar' => 'ano_anterior']);

  $resultados = [];
  foreach ($pruebas as $nombre => $prueba) $resultados[$nombre] = $medir($prueba);
  $ancho = max(array_map('mb_strlen', array_keys($resultados)));
  foreach ($resultados as $nombre => $ms) {
    $estado = $ms < 200 ? '✓' : ($ms < 1000 ? '·' : '✗');
    echo "  $estado " . $nombre . str_repeat(' ', $ancho - mb_strlen($nombre)) . sprintf("  %8.1f ms\n", $ms);
  }
  printf("\nBase de datos: %.1f MB · mediana de %d repeticiones · ✓ < 200 ms · ✗ > 1 s\n", filesize("$temporal/erp.sqlite") / 1048576, $repeticiones);
} catch (Throwable $e) {
  fwrite(STDERR, "✗ {$e->getMessage()} (" . basename($e->getFile()) . ":{$e->getLine()})\n");
  $fallo = true;
} finally {
  // La base de datos temporal se borra siempre
  $borrar = function (string $ruta) use (&$borrar) {
    if (is_dir($ruta)) { foreach (array_diff(scandir($ruta), ['.', '..']) as $f) $borrar("$ruta/$f"); rmdir($ruta); }
    elseif (file_exists($ruta)) unlink($ruta);
  };
  $borrar($temporal);
}
exit(empty($fallo) ? 0 : 1);
