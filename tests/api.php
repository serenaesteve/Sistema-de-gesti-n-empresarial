<?php
// Pruebas automáticas de la API (sin dependencias: PHP + extensión curl).
// Se ejecutan con tests/ejecutar.sh, que arranca un servidor propio con una base de datos temporal.
// Uso directo: php tests/api.php http://127.0.0.1:8765

declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8765', '/') . '/api/api.php';
$superadas = 0;
$fallidas = [];

// ---------- Mini marco de pruebas ----------

final class Sesion {
  private string $cookies;
  public function __construct(private string $base) { $this->cookies = tempnam(sys_get_temp_dir(), 'blush'); }

  // Devuelve [código HTTP, cuerpo decodificado (o texto si no es JSON)]
  public function pedir(string $metodo, string $recurso, array $parametros = [], ?array $cuerpo = null, bool $cabecera = true): array {
    $url = $this->base . '?' . http_build_query(['recurso' => $recurso] + $parametros);
    $c = curl_init($url);
    curl_setopt_array($c, [
      CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true,
      CURLOPT_COOKIEJAR => $this->cookies, CURLOPT_COOKIEFILE => $this->cookies,
      CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $cabecera && $metodo !== 'GET' ? ['X-Blush: 1'] : []),
    ]);
    if ($cuerpo !== null) curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($cuerpo));
    $texto = (string)curl_exec($c);
    $codigo = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $json = json_decode($texto, true);
    return [$codigo, is_array($json) ? $json : $texto];
  }

  public function get(string $r, array $p = []): array { return $this->pedir('GET', $r, $p); }
  public function post(string $r, ?array $c = null, array $p = []): array { return $this->pedir('POST', $r, $p, $c ?? []); }
  public function put(string $r, int $id, array $c): array { return $this->pedir('PUT', $r, ['id' => $id], $c); }
  public function delete(string $r, int $id): array { return $this->pedir('DELETE', $r, ['id' => $id]); }

  public function entrar(string $email, string $clave = 'blush2026'): array {
    return $this->post('entrar', ['email' => $email, 'clave' => $clave]);
  }
  // Datos de una respuesta correcta; si no lo es, la prueba falla con el error de la API
  public function datos(array $respuesta): mixed {
    [$codigo, $json] = $respuesta;
    if (!is_array($json) || empty($json['ok'])) throw new Exception("Respuesta $codigo inesperada: " . json_encode($json, JSON_UNESCAPED_UNICODE));
    return $json['datos'];
  }
}

function prueba(string $nombre, callable $cuerpo): void {
  global $superadas, $fallidas;
  try {
    $cuerpo();
    $superadas++;
    echo "  \033[32m✓\033[0m $nombre\n";
  } catch (Throwable $e) {
    $fallidas[] = $nombre;
    echo "  \033[31m✗ $nombre\033[0m\n      {$e->getMessage()}\n";
  }
}

function igual(mixed $esperado, mixed $real, string $que = ''): void {
  if ($esperado !== $real) throw new Exception(($que ? "$que: " : '') . 'se esperaba ' . var_export($esperado, true) . ' y se obtuvo ' . var_export($real, true));
}

function codigo(int $esperado, array $respuesta): array {
  igual($esperado, $respuesta[0], 'código HTTP (' . json_encode($respuesta[1], JSON_UNESCAPED_UNICODE) . ')');
  return $respuesta[1];
}

$admin = new Sesion($base);
$comercial = new Sesion($base);
$anonima = new Sesion($base);
$stock = fn(int $id) => $admin->datos($admin->get('productos', ['id' => $id]))['stock'];
$hoy = date('Y-m-d');

echo "\nSesión y permisos\n";

prueba('Sin sesión la API responde 401', function () use ($anonima) {
  codigo(401, $anonima->get('clientes'));
});
prueba('Una contraseña incorrecta no permite entrar', function () use ($anonima) {
  codigo(401, $anonima->entrar('serena@blush.test', 'incorrecta'));
});
prueba('Las cuentas de demostración pueden entrar', function () use ($admin, $comercial) {
  igual('admin', $admin->datos($admin->entrar('serena@blush.test'))['rol']);
  igual('comercial', $comercial->datos($comercial->entrar('pablo@blush.test'))['rol']);
});
prueba('Una escritura sin la cabecera X-Blush se rechaza (CSRF)', function () use ($admin) {
  codigo(403, $admin->pedir('POST', 'clientes', [], ['nombre' => 'X'], false));
});
prueba('El menú del comercial no incluye Usuarios ni Sistema', function () use ($comercial) {
  $ids = array_column($comercial->datos($comercial->get('menu')), 'id');
  igual(false, in_array('usuarios', $ids, true) || in_array('sistema', $ids, true));
});
prueba('El comercial no puede borrar, ver el sistema ni tocar productos', function () use ($comercial) {
  codigo(403, $comercial->delete('clientes', 6));
  codigo(403, $comercial->get('sistema'));
  codigo(403, $comercial->post('productos', ['nombre' => 'X', 'precio' => 1]));
});

echo "\nValidación\n";

prueba('Los errores de validación se devuelven por campo (422)', function () use ($admin) {
  $json = codigo(422, $admin->post('clientes', ['nombre' => '', 'email' => 'no-es-email']));
  igual(['email', 'nombre'], (function ($e) { $k = array_keys($e); sort($k); return $k; })($json['errores']));
});
prueba('Un pedido sin líneas no se puede guardar', function () use ($admin) {
  $json = codigo(422, $admin->post('pedidos', ['cliente_id' => 1, 'lineas' => []]));
  igual(true, isset($json['errores']['lineas']));
});

echo "\nPedidos y stock\n";

prueba('Sin stock suficiente se señala la línea y no se guarda nada', function () use ($admin, $stock) {
  $antes = [$stock(4), $stock(10)];
  $json = codigo(422, $admin->post('pedidos', ['cliente_id' => 1, 'lineas' => [
    ['producto_id' => 4, 'cantidad' => 1], ['producto_id' => 10, 'cantidad' => 999],
  ]]));
  igual(true, isset($json['errores']['lineas.1.cantidad']), 'error en la segunda línea');
  igual($antes, [$stock(4), $stock(10)], 'el stock no cambia (transacción deshecha)');
});

$pedido = null;
prueba('Un pedido con varias líneas descuenta stock y calcula el total', function () use ($admin, $stock, &$pedido) {
  $antes = [$stock(3), $stock(4)];
  $pedido = $admin->datos($admin->post('pedidos', ['cliente_id' => 2, 'lineas' => [
    ['producto_id' => 3, 'cantidad' => 2], ['producto_id' => 4, 'cantidad' => 3],
  ]]));
  igual([$antes[0] - 2, $antes[1] - 3], [$stock(3), $stock(4)]);
  igual(round(2 * 79.95 + 3 * 34.50, 2), $pedido['total']);
  igual(2, count($pedido['lineas']));
});
prueba('Cambiar el precio del catálogo no cambia los pedidos existentes', function () use ($admin, &$pedido) {
  $producto = $admin->datos($admin->get('productos', ['id' => 3]));
  $admin->datos($admin->put('productos', 3, [...$producto, 'precio' => 99.99]));
  igual(79.95, $admin->datos($admin->get('pedidos', ['id' => $pedido['id']]))['lineas'][0]['precio']);
  $admin->datos($admin->put('productos', 3, $producto));
});
prueba('Cancelar un pedido devuelve el stock', function () use ($admin, $stock, &$pedido) {
  $antes = $stock(3);
  $admin->datos($admin->put('pedidos', $pedido['id'], [...$pedido, 'estado' => 'cancelado']));
  igual($antes + 2, $stock(3));
});

echo "\nCompras\n";

prueba('Una compra recibida sube el stock; volver a «pedida» lo resta', function () use ($admin, $stock, $hoy) {
  $antes = $stock(10);
  $compra = $admin->datos($admin->post('compras', ['proveedor_id' => 1, 'fecha' => $hoy, 'estado' => 'recibida',
    'lineas' => [['producto_id' => 10, 'cantidad' => 7, 'coste' => 28.5]]]));
  igual($antes + 7, $stock(10));
  $admin->datos($admin->put('compras', $compra['id'], [...$compra, 'estado' => 'pedida']));
  igual($antes, $stock(10));
});

echo "\nFacturación\n";

$factura = null;
prueba('Solo se facturan pedidos entregados', function () use ($admin) {
  $pendiente = $admin->datos($admin->get('pedidos', ['orden' => 'id', 'dir' => 'desc']))[0];
  codigo(409, $admin->post('facturar', null, ['id' => $pendiente['id']]));
});
prueba('Facturar un pedido entregado crea una factura con sus líneas e IVA', function () use ($admin, &$factura) {
  $pedido = array_values(array_filter($admin->datos($admin->get('pedidos')), fn($p) => $p['estado'] === 'entregado' && !$p['factura']))[0];
  $nueva = $admin->datos($admin->post('facturar', null, ['id' => $pedido['id']]));
  $factura = $admin->datos($admin->get('facturas', ['id' => $nueva['id']]));
  igual(count($pedido['lineas']), count($factura['lineas']));
  igual($pedido['total'], $factura['base']);
  igual(round($factura['base'] * 1.21, 2), $factura['total']);
  igual(1, preg_match('/^F\d{4}-\d{4}$/', $factura['numero']));
});
prueba('Un pedido no se factura dos veces y, facturado, no se puede editar', function () use ($admin, &$factura) {
  codigo(409, $admin->post('facturar', null, ['id' => $factura['pedido_id']]));
  $pedido = $admin->datos($admin->get('pedidos', ['id' => $factura['pedido_id']]));
  codigo(409, $admin->put('pedidos', $pedido['id'], $pedido));
});
prueba('El comercial no puede emitir rectificativas', function () use ($comercial, &$factura) {
  codigo(403, $comercial->post('rectificar', ['motivo' => 'X'], ['id' => $factura['id']]));
});
prueba('La rectificativa exige motivo y anula con importes negativos', function () use ($admin, &$factura) {
  codigo(422, $admin->post('rectificar', ['motivo' => ' '], ['id' => $factura['id']]));
  $r = $admin->datos($admin->post('rectificar', ['motivo' => 'Error en el precio'], ['id' => $factura['id']]));
  $rectificativa = $admin->datos($admin->get('facturas', ['id' => $r['id']]));
  igual('rectificativa', $rectificativa['tipo']);
  igual(-$factura['total'], $rectificativa['total']);
  igual($factura['numero'], $rectificativa['rectifica']);
  codigo(409, $admin->post('rectificar', ['motivo' => 'Otra vez'], ['id' => $factura['id']]));
});
prueba('Tras rectificar, el pedido se puede volver a facturar', function () use ($admin, &$factura) {
  igual(null, $admin->datos($admin->get('pedidos', ['id' => $factura['pedido_id']]))['factura']);
  $admin->datos($admin->post('facturar', null, ['id' => $factura['pedido_id']]));
});

echo "\nListados\n";

prueba('Paginación, ordenación y filtro por fechas', function () use ($admin) {
  $pagina = $admin->datos($admin->get('pedidos', ['pagina' => 2, 'orden' => 'total', 'dir' => 'asc']));
  igual(2, $pagina['pagina']);
  $totales = array_column($pagina['filas'], 'total');
  $ordenados = $totales; sort($ordenados);
  igual($ordenados, $totales, 'orden por total');
  $desde = date('Y-m-d', strtotime('-7 days'));
  foreach ($admin->datos($admin->get('pedidos', ['desde' => $desde])) as $p) {
    if ($p['fecha'] < $desde) throw new Exception("Pedido {$p['id']} fuera del filtro");
  }
});
prueba('El CSV lleva BOM, separador «;» y decimales con coma', function () use ($admin) {
  [$codigo, $texto] = $admin->get('productos', ['formato' => 'csv']);
  igual(200, $codigo);
  igual("\xEF\xBB\xBF", substr($texto, 0, 3));
  igual(1, preg_match('/;749,99;/', $texto));
});

echo "\nUsuarios\n";

prueba('Email repetido, auto-degradarse y borrarse a sí misma están prohibidos', function () use ($admin) {
  codigo(422, $admin->post('usuarios', ['nombre' => 'X', 'email' => 'pablo@blush.test', 'clave' => '12345678']));
  codigo(422, $admin->put('usuarios', 1, ['nombre' => 'Serena Esteve', 'email' => 'serena@blush.test', 'rol' => 'comercial']));
  codigo(409, $admin->delete('usuarios', 1));
});
prueba('Cada usuario puede cambiar su propia contraseña', function () use ($comercial, $base) {
  codigo(422, $comercial->post('mi-clave', ['actual' => 'mala', 'nueva' => 'nueva-clave-1']));
  $comercial->datos($comercial->post('mi-clave', ['actual' => 'blush2026', 'nueva' => 'nueva-clave-1']));
  $otra = new Sesion($base);
  $otra->datos($otra->entrar('pablo@blush.test', 'nueva-clave-1'));
  $comercial->datos($comercial->post('mi-clave', ['actual' => 'nueva-clave-1', 'nueva' => 'blush2026']));
});

echo "\nCopias de seguridad\n";

prueba('Restaurar una copia deja los datos como estaban', function () use ($admin) {
  [, $copia] = $admin->get('copia');
  igual(true, is_array($copia) && isset($copia['tablas']['pedido_lineas']), 'la copia incluye las líneas');
  $temporal = $admin->datos($admin->post('clientes', ['nombre' => 'Cliente temporal']));
  $admin->datos($admin->pedir('POST', 'restaurar', [], $copia));
  codigo(404, $admin->get('clientes', ['id' => $temporal['id']]));
});
prueba('Una copia no válida se rechaza sin tocar nada', function () use ($admin) {
  codigo(422, $admin->pedir('POST', 'restaurar', [], ['esquema' => 3, 'tablas' => ['clientes' => [['id' => 1, 'columna_rara' => 'x']]]]));
  igual(true, count($admin->datos($admin->get('clientes'))) > 0);
});

echo "\nAuditoría y sistema\n";

prueba('El registro guarda las operaciones con su usuario', function () use ($admin) {
  $registro = $admin->datos($admin->get('registro'));
  igual('Serena Esteve', $registro[0]['usuario']);
  igual(true, in_array('rectificar', array_column($registro, 'accion'), true));
});
prueba('El diagnóstico del sistema no tiene errores', function () use ($admin) {
  igual(0, $admin->datos($admin->get('sistema'))['resumen']['error']);
});

$total = $superadas + count($fallidas);
echo "\n" . ($fallidas ? "\033[31m" : "\033[32m") . "$superadas de $total pruebas superadas\033[0m\n";
exit($fallidas ? 1 : 0);
