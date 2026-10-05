<?php
// Datos resumidos para el panel de inicio.
// Los importes salen de las líneas de cada pedido (precio guardado en el momento de la venta).
// ?periodo=mes|90d|ano|todo limita la facturación y las ventas por producto.

const PERIODOS = ['mes' => 'first day of this month', '90d' => '-89 days', 'ano' => 'first day of january this year'];

function panel(PDO $pdo, array $esquema): array {
  $valor = function (string $sql, array $parametros = []) use ($pdo) {
    $consulta = $pdo->prepare($sql);
    $consulta->execute($parametros);
    return $consulta->fetchColumn();
  };

  $periodo = array_key_exists($_GET['periodo'] ?? '', PERIODOS) ? $_GET['periodo'] : 'todo';
  $desde = $periodo === 'todo' ? '0000-01-01' : date('Y-m-d', strtotime(PERIODOS[$periodo]));
  $alerta = (int)$esquema['productos']['campos']['stock']['alerta'];

  $ventas = "FROM pedido_lineas l JOIN pedidos pe ON pe.id = l.pedido_id WHERE pe.estado <> 'cancelado' AND pe.fecha >= ?";

  $consulta = $pdo->prepare("SELECT pr.nombre, SUM(l.cantidad * l.precio) AS total
                             FROM pedido_lineas l JOIN pedidos pe ON pe.id = l.pedido_id JOIN productos pr ON pr.id = l.producto_id
                             WHERE pe.estado <> 'cancelado' AND pe.fecha >= ?
                             GROUP BY pr.id, pr.nombre ORDER BY total DESC LIMIT 5");
  $consulta->execute([$desde]);
  $ventasProducto = array_map(fn($f) => ['nombre' => $f['nombre'], 'total' => round((float)$f['total'], 2)], $consulta->fetchAll());

  $ultimos = $pdo->query("SELECT pe.id, pe.fecha, pe.estado, c.nombre AS cliente,
                            (SELECT COUNT(*) FROM pedido_lineas l WHERE l.pedido_id = pe.id) AS productos,
                            (SELECT COALESCE(SUM(l.cantidad * l.precio), 0) FROM pedido_lineas l WHERE l.pedido_id = pe.id) AS total
                          FROM pedidos pe JOIN clientes c ON c.id = pe.cliente_id ORDER BY pe.fecha DESC, pe.id DESC LIMIT 5")->fetchAll();

  // Stock bajo, con las unidades ya pedidas a proveedores y aún no recibidas
  $stockBajo = $pdo->query("SELECT p.id, p.nombre, p.stock,
                              (SELECT COALESCE(SUM(cl.cantidad), 0) FROM compra_lineas cl JOIN compras co ON co.id = cl.compra_id
                               WHERE cl.producto_id = p.id AND co.estado = 'pedida') AS en_camino
                            FROM productos p WHERE p.stock < $alerta ORDER BY p.stock ASC")->fetchAll();

  return [
    'periodo' => $periodo,
    'indicadores' => [
      'clientes_activos'   => (int)$valor("SELECT COUNT(*) FROM clientes WHERE estado = 'activo'"),
      'clientes_potencial' => (int)$valor("SELECT COUNT(*) FROM clientes WHERE estado = 'potencial'"),
      'pedidos_pendientes' => (int)$valor("SELECT COUNT(*) FROM pedidos WHERE estado = 'pendiente'"),
      'pedidos_periodo'    => (int)$valor("SELECT COUNT(*) FROM pedidos WHERE estado <> 'cancelado' AND fecha >= ?", [$desde]),
      'facturacion'        => round((float)$valor("SELECT COALESCE(SUM(l.cantidad * l.precio), 0) $ventas", [$desde]), 2),
      'sin_facturar'       => (int)$valor("SELECT COUNT(*) FROM pedidos pe WHERE pe.estado = 'entregado' AND NOT EXISTS (
                                             SELECT 1 FROM facturas f WHERE f.pedido_id = pe.id AND f.tipo = 'ordinaria'
                                             AND NOT EXISTS (SELECT 1 FROM facturas r WHERE r.rectifica_id = f.id))"),
      'compras_pendientes' => (int)$valor("SELECT COUNT(*) FROM compras WHERE estado = 'pedida'"),
    ],
    'ventas_mes'      => ventasPorMes($pdo, 6),
    'ventas_producto' => $ventasProducto,
    'ultimos_pedidos' => array_map(fn($f) => [...$f, 'id' => (int)$f['id'], 'productos' => (int)$f['productos'], 'total' => round((float)$f['total'], 2)], $ultimos),
    'stock_bajo'      => array_map(fn($f) => [...$f, 'id' => (int)$f['id'], 'stock' => (int)$f['stock'], 'en_camino' => (int)$f['en_camino']], $stockBajo),
    'alerta_stock'    => $alerta,
  ];
}

// Ventas (pedidos no cancelados) de los últimos N meses, incluidos los meses sin ventas.
// SUBSTR(fecha, 1, 7) funciona igual en SQLite y en MySQL: '2026-10-05' -> '2026-10'.
function ventasPorMes(PDO $pdo, int $meses): array {
  $primero = new DateTime('first day of this month');
  $primero->modify('-' . ($meses - 1) . ' months');

  $consulta = $pdo->prepare("SELECT SUBSTR(pe.fecha, 1, 7) AS mes, SUM(l.cantidad * l.precio) AS total, COUNT(DISTINCT pe.id) AS pedidos
                             FROM pedido_lineas l JOIN pedidos pe ON pe.id = l.pedido_id
                             WHERE pe.estado <> 'cancelado' AND pe.fecha >= ? GROUP BY SUBSTR(pe.fecha, 1, 7)");
  $consulta->execute([$primero->format('Y-m-d')]);
  $datos = array_column($consulta->fetchAll(), null, 'mes');

  $serie = [];
  for ($i = 0; $i < $meses; $i++) {
    $mes = $primero->format('Y-m');
    $serie[] = [
      'mes'     => $mes,
      'total'   => round((float)($datos[$mes]['total'] ?? 0), 2),
      'pedidos' => (int)($datos[$mes]['pedidos'] ?? 0),
    ];
    $primero->modify('+1 month');
  }
  return $serie;
}
