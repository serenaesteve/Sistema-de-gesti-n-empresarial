<?php
// Reglas de negocio que van más allá del CRUD genérico.
// crud.php llama a estas funciones dentro de una transacción: si algo falla, no se guarda nada.

// Antes de insertar o actualizar. $anterior es null en un alta e incluye sus 'lineas' en una edición.
// Devuelve [$datos, $lineas], que pueden haber cambiado (por ejemplo, el precio de cada línea).
function antesDeGuardar(PDO $pdo, string $tabla, array $datos, array $lineas, ?array $anterior): array {
  return match ($tabla) {
    'pedidos'  => guardarPedido($pdo, $datos, $lineas, $anterior),
    'compras'  => guardarCompra($pdo, $datos, $lineas, $anterior),
    'usuarios' => [guardarUsuario($datos, $anterior), $lineas],
    default    => [$datos, $lineas],
  };
}

function antesDeBorrar(PDO $pdo, string $tabla, array $fila): void {
  if ($tabla === 'pedidos') {
    exigirSinFactura($fila);
    if ($fila['estado'] !== 'cancelado') sumarStock($pdo, $fila['lineas']);
  }
  if ($tabla === 'compras' && $fila['estado'] === 'recibida') {
    restarStock($pdo, $fila['lineas'], 'No se puede borrar: ya se han vendido unidades de esta compra.');
  }
  if ($tabla === 'usuarios' && $fila['id'] === usuarioActual()['id']) {
    fallar('No puedes borrar tu propio usuario.', 409);
  }
}

// ---------- Pedidos: precio guardado y stock reservado ----------

function guardarPedido(PDO $pdo, array $datos, array $lineas, ?array $anterior): array {
  if ($anterior) exigirSinFactura($anterior);

  // El precio se fija cuando el producto entra en el pedido; si ya estaba, se conserva.
  $catalogo = $pdo->query('SELECT id, precio FROM productos')->fetchAll(PDO::FETCH_KEY_PAIR);
  $previos = array_column($anterior['lineas'] ?? [], 'precio', 'producto_id');
  foreach ($lineas as &$linea) {
    $linea['precio'] = $previos[$linea['producto_id']] ?? round((float)$catalogo[$linea['producto_id']], 2);
  }
  unset($linea);

  // Primero se devuelve lo que reservaba la versión anterior y luego se reserva lo nuevo.
  if ($anterior && $anterior['estado'] !== 'cancelado') sumarStock($pdo, $anterior['lineas']);
  if ($datos['estado'] !== 'cancelado') restarStock($pdo, $lineas);

  return [$datos, $lineas];
}

function exigirSinFactura(array $pedido): void {
  if (!empty($pedido['factura'])) {
    fallar("El pedido #{$pedido['id']} ya está facturado ({$pedido['factura']}) y no se puede modificar.", 409);
  }
}

// ---------- Compras: el stock sube al recibir la mercancía ----------

function guardarCompra(PDO $pdo, array $datos, array $lineas, ?array $anterior): array {
  if ($anterior && $anterior['estado'] === 'recibida') {
    restarStock($pdo, $anterior['lineas'], 'No se puede modificar: ya se han vendido unidades de esta compra.');
  }
  if ($datos['estado'] === 'recibida') sumarStock($pdo, $lineas);
  return [$datos, $lineas];
}

// ---------- Stock ----------

// Agrupa las cantidades por producto: dos líneas del mismo producto cuentan juntas.
function cantidadesPorProducto(array $lineas): array {
  $total = [];
  foreach ($lineas as $linea) $total[$linea['producto_id']] = ($total[$linea['producto_id']] ?? 0) + $linea['cantidad'];
  return $total;
}

function sumarStock(PDO $pdo, array $lineas): void {
  $suma = $pdo->prepare('UPDATE productos SET stock = stock + ? WHERE id = ?');
  foreach (cantidadesPorProducto($lineas) as $producto => $cantidad) $suma->execute([$cantidad, $producto]);
}

// Resta stock solo si hay suficiente. Sin $mensaje, el error se señala en la línea del formulario.
function restarStock(PDO $pdo, array $lineas, ?string $mensaje = null): void {
  $resta = $pdo->prepare('UPDATE productos SET stock = stock - ? WHERE id = ? AND stock >= ?');
  $consulta = $pdo->prepare('SELECT nombre, stock FROM productos WHERE id = ?');
  $errores = [];

  foreach (cantidadesPorProducto($lineas) as $producto => $cantidad) {
    $resta->execute([$cantidad, $producto, $cantidad]);
    if ($resta->rowCount() > 0) continue;
    if ($mensaje) fallar($mensaje, 409);
    $consulta->execute([$producto]);
    $p = $consulta->fetch();
    $indice = array_search($producto, array_column($lineas, 'producto_id'));
    $errores["lineas.$indice.cantidad"] = "Stock insuficiente: quedan {$p['stock']} uds. de {$p['nombre']}";
  }
  if ($errores) fallar('No hay stock suficiente', 422, $errores);
}

// ---------- Usuarios ----------

function guardarUsuario(array $datos, ?array $anterior): array {
  $yo = usuarioActual();
  if ($anterior && $yo && $anterior['id'] === $yo['id'] && $datos['rol'] !== 'admin') {
    fallar('Revisa los campos marcados', 422, ['rol' => 'No puedes quitarte el rol de administración a ti misma/o.']);
  }
  return $datos;
}

// ---------- Facturación ----------

// Siguiente número de una serie: F2026-0001, F2026-0002… (R2026-… para rectificativas)
function siguienteNumero(PDO $pdo, string $serie): string {
  $ultimo = $pdo->prepare('SELECT numero FROM facturas WHERE numero LIKE ? ORDER BY numero DESC LIMIT 1');
  $ultimo->execute([$serie . '%']);
  $n = (int)substr((string)$ultimo->fetchColumn(), strlen($serie)) + 1;
  return $serie . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

// Resumen para la columna «Concepto»: «Portátil ProBook 15 y 2 más»
function resumenConceptos(array $conceptos): string {
  return $conceptos[0] . (count($conceptos) > 1 ? ' y ' . (count($conceptos) - 1) . ' más' : '');
}

function insertarFactura(PDO $pdo, array $factura, array $lineas): int {
  $columnas = array_keys($factura);
  $pdo->prepare('INSERT INTO facturas (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')')
      ->execute(array_values($factura));
  $id = (int)$pdo->lastInsertId();
  $linea = $pdo->prepare('INSERT INTO factura_lineas (factura_id, concepto, cantidad, precio, importe) VALUES (?, ?, ?, ?, ?)');
  foreach ($lineas as $l) $linea->execute([$id, $l['concepto'], $l['cantidad'], $l['precio'], $l['importe']]);
  return $id;
}

// Crea la factura de un pedido entregado. Copia los datos del cliente y de cada línea
// para que la factura no cambie aunque después se modifiquen.
function facturar(PDO $pdo, array $config, int $pedidoId, ?string $fecha = null, bool $anotar = true): array {
  $consulta = $pdo->prepare(
    "SELECT pe.id, pe.estado, c.nombre AS cliente_nombre, c.empresa, c.nif, c.direccion, c.ciudad,
            (SELECT f.numero FROM facturas f WHERE f.pedido_id = pe.id AND f.tipo = 'ordinaria'
               AND NOT EXISTS (SELECT 1 FROM facturas r WHERE r.rectifica_id = f.id)) AS factura
     FROM pedidos pe JOIN clientes c ON c.id = pe.cliente_id WHERE pe.id = ?");
  $consulta->execute([$pedidoId]);
  $pedido = $consulta->fetch();

  if (!$pedido) fallar("Pedido #$pedidoId no encontrado", 404);
  if ($pedido['factura']) fallar("El pedido #$pedidoId ya tiene la factura {$pedido['factura']}.", 409);
  if ($pedido['estado'] !== 'entregado') fallar('Solo se pueden facturar pedidos entregados.', 409);

  $consulta = $pdo->prepare('SELECT pr.nombre AS concepto, l.cantidad, l.precio FROM pedido_lineas l
                             JOIN productos pr ON pr.id = l.producto_id WHERE l.pedido_id = ? ORDER BY l.id');
  $consulta->execute([$pedidoId]);
  $lineas = array_map(fn($l) => [...$l, 'importe' => round($l['cantidad'] * $l['precio'], 2)], $consulta->fetchAll());

  $fecha ??= date('Y-m-d');
  $iva = (int)$config['empresa']['iva'];
  $base = round(array_sum(array_column($lineas, 'importe')), 2);
  $cuota = round($base * $iva / 100, 2);
  $numero = siguienteNumero($pdo, 'F' . substr($fecha, 0, 4) . '-');

  $id = insertarFactura($pdo, [
    'numero' => $numero, 'tipo' => 'ordinaria', 'fecha' => $fecha, 'pedido_id' => $pedidoId,
    'cliente' => $pedido['empresa'] ?: $pedido['cliente_nombre'], 'nif' => $pedido['nif'],
    'direccion' => trim(implode(', ', array_filter([$pedido['direccion'], $pedido['ciudad']]))) ?: null,
    'concepto' => resumenConceptos(array_column($lineas, 'concepto')),
    'base' => $base, 'iva' => $iva, 'cuota' => $cuota, 'total' => round($base + $cuota, 2),
  ], $lineas);

  if ($anotar) registrar($pdo, 'facturar', 'facturas', $id, "Factura $numero del pedido #$pedidoId");
  return ['id' => $id, 'numero' => $numero];
}

// Factura rectificativa: anula una factura con los mismos importes en negativo.
// Una factura emitida no se borra ni se modifica nunca; se corrige así.
function rectificar(PDO $pdo, array $config, int $facturaId, string $motivo, ?string $fecha = null, bool $anotar = true): array {
  $consulta = $pdo->prepare('SELECT f.*, (SELECT r.numero FROM facturas r WHERE r.rectifica_id = f.id) AS rectificada FROM facturas f WHERE f.id = ?');
  $consulta->execute([$facturaId]);
  $original = $consulta->fetch();

  if (!$original) fallar("Factura #$facturaId no encontrada", 404);
  if ($original['tipo'] !== 'ordinaria') fallar('Una factura rectificativa no se puede rectificar.', 409);
  if ($original['rectificada']) fallar("La factura {$original['numero']} ya está rectificada por {$original['rectificada']}.", 409);
  $motivo = trim($motivo);
  if ($motivo === '') fallar('Indica el motivo de la rectificación', 422, ['motivo' => 'Indica el motivo de la rectificación']);

  $consulta = $pdo->prepare('SELECT concepto, cantidad, precio, importe FROM factura_lineas WHERE factura_id = ? ORDER BY id');
  $consulta->execute([$facturaId]);
  $lineas = array_map(fn($l) => [...$l, 'cantidad' => -(int)$l['cantidad'], 'importe' => -(float)$l['importe']], $consulta->fetchAll());

  $fecha ??= date('Y-m-d');
  $numero = siguienteNumero($pdo, 'R' . substr($fecha, 0, 4) . '-');

  $id = insertarFactura($pdo, [
    'numero' => $numero, 'tipo' => 'rectificativa', 'fecha' => $fecha,
    'pedido_id' => $original['pedido_id'], 'rectifica_id' => $facturaId, 'motivo' => mb_substr($motivo, 0, 255),
    'cliente' => $original['cliente'], 'nif' => $original['nif'], 'direccion' => $original['direccion'],
    'concepto' => "Rectificación de {$original['numero']}",
    'base' => -(float)$original['base'], 'iva' => (int)$original['iva'],
    'cuota' => -(float)$original['cuota'], 'total' => -(float)$original['total'],
  ], $lineas);

  if ($anotar) registrar($pdo, 'rectificar', 'facturas', $id, "Factura $numero rectifica a {$original['numero']}: $motivo");
  return ['id' => $id, 'numero' => $numero];
}
