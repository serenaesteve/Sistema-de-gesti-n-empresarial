<?php
// Pruebas automáticas de la API (sin dependencias: PHP + extensión curl).
// Se ejecutan con tests/ejecutar.sh, que arranca un servidor propio con una base de datos temporal.
// Uso directo: php tests/api.php http://127.0.0.1:8765

require __DIR__ . '/marco.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8765', '/') . '/api/api.php';
$superadas = 0;
$fallidas = [];

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

echo "\nOrganización y consulta de la información (RA3)\n";

prueba('NIF, NIE y CIF se validan con su letra o dígito de control', function () use ($admin) {
  $json = codigo(422, $admin->post('clientes', ['nombre' => 'NIF malo', 'nif' => '12345678A']));
  igual(true, isset($json['errores']['nif']));
  $bien = $admin->datos($admin->post('clientes', ['nombre' => 'NIF bueno', 'nif' => '12345678-z']));
  igual('12345678Z', $bien['nif'], 'se guarda normalizado');
  igual('X1234567L', $admin->datos($admin->post('clientes', ['nombre' => 'NIE', 'nif' => 'x1234567l']))['nif']);
  codigo(422, $admin->post('proveedores', ['nombre' => 'CIF malo', 'nif' => 'B12345675']));
});
prueba('Informes: cada perfil ve los suyos y el comercial no ve las pantallas de administración de datos', function () use ($admin, $comercial) {
  igual(10, count($admin->datos($admin->get('informes'))['informes']));
  igual(true, count($comercial->datos($comercial->get('informes'))['informes']) > 0);
  foreach (['rendimiento', 'tareas', 'formularios', 'intercambio'] as $r) codigo(403, $comercial->get($r));
  codigo(404, $admin->get('informes', ['informe' => 'no_existe']));
  codigo(422, $admin->get('informes', ['informe' => 'ventas_periodo', 'desde' => '2026-05-01', 'hasta' => '2026-01-01']));
});
prueba('Ventas por periodo: un periodo por mes (también los vacíos) y totales que cuadran', function () use ($admin) {
  $desde = date('Y-m-01', strtotime('first day of -11 months'));
  $r = $admin->datos($admin->get('informes', ['informe' => 'ventas_periodo', 'desde' => $desde, 'hasta' => date('Y-m-d'), 'agrupacion' => 'mes']));
  igual(12, count($r['filas']), 'doce meses');
  igual(round(array_sum(array_column($r['filas'], 'importe')), 2), $r['totales']['importe']);
  $panel = array_sum(array_column($admin->datos($admin->get('panel'))['ventas_mes'], 'total'));
  igual(round($panel, 2), round(array_sum(array_column(array_slice($r['filas'], -6), 'importe')), 2), 'coincide con el panel');
  $semanas = $admin->datos($admin->get('informes', ['informe' => 'ventas_periodo', 'desde' => date('Y-m-d', strtotime('-27 days')), 'hasta' => date('Y-m-d'), 'agrupacion' => 'semana']));
  igual(true, count($semanas['filas']) >= 4 && count($semanas['filas']) <= 5, 'cuatro o cinco semanas');
});
prueba('Los informes se exportan a CSV (Excel) y JSON', function () use ($admin) {
  [$codigo, $csv] = $admin->get('informes', ['informe' => 'ventas_cliente', 'desde' => '2020-01-01', 'formato' => 'csv']);
  igual(200, $codigo);
  igual(true, str_starts_with($csv, "\xEF\xBB\xBFCliente;Empresa;Pedidos;Importe"), 'cabecera con BOM y «;»');
  igual(true, str_contains($csv, "\nTOTAL;"), 'fila de totales');
  [, $json] = $admin->get('informes', ['informe' => 'libro_facturas', 'desde' => '2020-01-01', 'formato' => 'json']);
  $facturas = $admin->datos($admin->get('facturas'));
  igual(round(array_sum(array_column($facturas, 'total')), 2), $json['totales']['total'], 'el libro suma todas las facturas');
  igual('exportar', $admin->datos($admin->get('registro'))[0]['accion']);
});
prueba('Constructor de consultas: agrupa, filtra, enseña el SQL y no admite campos inventados', function () use ($admin) {
  $clientes = count($admin->datos($admin->get('clientes')));
  $r = $admin->datos($admin->get('consultas', ['definicion' => json_encode(['recurso' => 'clientes', 'agrupar' => 'ciudad', 'agregacion' => 'contar'])]));
  igual($clientes, array_sum(array_column($r['filas'], 'registros')));
  igual(true, str_contains($r['sql'], 'GROUP BY t.ciudad'));
  $r = $admin->datos($admin->get('consultas', ['definicion' => json_encode(['recurso' => 'pedidos', 'columnas' => ['id', 'cliente', 'total'],
    'filtros' => [['campo' => 'estado', 'operador' => 'igual', 'valor' => 'entregado']], 'orden' => 'total', 'dir' => 'desc'])]));
  igual(true, count($r['filas']) > 0 && $r['filas'][0]['total'] >= end($r['filas'])['total'], 'ordenado por total');
  igual(true, str_contains($r['sql'], "'entregado'"), 'el SQL muestra el valor del filtro');
  codigo(422, $admin->get('consultas', ['definicion' => json_encode(['recurso' => 'clientes', 'columnas' => ['clave; DROP TABLE clientes']])]));
  codigo(422, $admin->get('consultas', ['definicion' => json_encode(['recurso' => 'usuarios'])]));
});
prueba('Consola SQL: solo lectura, una sentencia, sin tablas protegidas y solo para administración', function () use ($admin, $comercial) {
  $r = $admin->datos($admin->get('consultas', ['sql' => 'SELECT estado, COUNT(*) AS n FROM pedidos GROUP BY estado']));
  igual(true, count($r['filas']) > 0);
  foreach (['DELETE FROM clientes', 'SELECT 1; DELETE FROM clientes', 'SELECT * FROM usuarios', 'SELECT clave FROM claves_api',
            'SELECT * FROM sqlite_master', 'WITH x AS (SELECT 1) UPDATE clientes SET nombre = 1', 'SELECT 1 -- comentario', 'SELECT * FROM tabla_que_no_existe'] as $sql) {
    codigo(422, $admin->get('consultas', ['sql' => $sql]));
  }
  codigo(403, $comercial->get('consultas', ['sql' => 'SELECT 1']));
  igual(true, count($admin->datos($admin->get('clientes'))) > 0, 'nada se ha borrado');
});
prueba('Consultas guardadas: se guardan, se repiten, se exportan y se borran', function () use ($admin, $comercial) {
  $id = $admin->datos($admin->post('consultas', ['nombre' => 'Clientes por estado', 'tipo' => 'constructor', 'definicion' => ['recurso' => 'clientes', 'agrupar' => 'estado']]))['id'];
  $admin->datos($admin->post('consultas', ['nombre' => 'Pedidos SQL', 'tipo' => 'sql', 'definicion' => ['sql' => 'SELECT id FROM pedidos']]));
  igual(['Clientes por estado'], array_column($comercial->datos($comercial->get('consultas'))['guardadas'], 'nombre'), 'el comercial no ve las de SQL');
  igual(true, count($admin->datos($admin->get('consultas', ['guardada' => $id]))['filas']) > 0);
  [, $csv] = $admin->get('consultas', ['guardada' => $id, 'formato' => 'csv']);
  igual(true, str_contains($csv, 'Estado;Registros'));
  $admin->datos($admin->delete('consultas', $id));
  codigo(404, $admin->get('consultas', ['guardada' => $id]));
});
prueba('Importar CSV: vista previa con errores y repetidos, y solo se importan las filas buenas', function () use ($admin) {
  [, $plantilla] = $admin->get('intercambio', ['plantilla' => 'clientes']);
  igual(['Nombre *', 'Empresa', 'NIF / CIF'], array_slice(str_getcsv(explode("\n", substr($plantilla, 3))[0], ';', '"', ''), 0, 3), 'plantilla con las etiquetas');
  $existente = $admin->datos($admin->get('clientes'))[0];
  $csv = "Nombre;Email;NIF;Teléfono;Columna rara\nAna Importada;ana@importada.es;00000000T;600111222;x\nSin email bueno;esto-no-es-email;;;\n\"{$existente['nombre']} bis\";;{$existente['nif']};;\n";
  $previa = $admin->datos($admin->post('intercambio', ['contenido' => $csv, 'nombre' => 'clientes.csv'], ['accion' => 'analizar', 'destino' => 'clientes']));
  igual([2, 1, 1], [$previa['validas'], $previa['con_errores'], $previa['duplicadas']]);
  igual(['Columna rara'], $previa['ignoradas']);
  $antes = count($admin->datos($admin->get('clientes')));
  $r = $admin->datos($admin->post('intercambio', ['contenido' => $csv, 'nombre' => 'clientes.csv', 'omitir_duplicados' => true], ['accion' => 'importar', 'destino' => 'clientes']));
  igual([1, 2], [$r['importadas'], $r['omitidas']]);
  igual($antes + 1, count($admin->datos($admin->get('clientes'))));
  igual('importar', $admin->datos($admin->get('registro'))[0]['accion']);
  codigo(403, $admin->post('intercambio', ['contenido' => $csv], ['accion' => 'analizar', 'destino' => 'facturas']));
});
prueba('Importar JSON (lo que exporta el propio ERP) y CSV con «,» y decimales con coma', function () use ($admin) {
  [, $json] = $admin->get('productos', ['formato' => 'json']);
  igual(count($admin->datos($admin->get('productos'))), $json['total']);
  $csv = "nombre,categoria,precio,stock\nProducto importado,Pruebas,\"12,50\",7\n";
  $r = $admin->datos($admin->post('intercambio', ['contenido' => $csv], ['accion' => 'importar', 'destino' => 'productos']));
  igual(1, $r['importadas']);
  $nuevo = array_values(array_filter($admin->datos($admin->get('productos', ['q' => 'Producto importado'])), fn($p) => $p['nombre'] === 'Producto importado'))[0];
  igual(12.5, $nuevo['precio']);
  $lista = json_encode([['nombre' => 'Proveedor JSON', 'ciudad' => 'Valencia']]);
  igual(1, $admin->datos($admin->post('intercambio', ['contenido' => $lista, 'formato' => 'json'], ['accion' => 'importar', 'destino' => 'proveedores']))['importadas']);
});

$publica = new Sesion($base);
$formulario = null;
prueba('Formularios web: se publican y cualquiera puede rellenarlos sin sesión', function () use ($admin, $publica, &$formulario) {
  codigo(422, $admin->post('formularios', ['titulo' => 'Sin nombre', 'recurso' => 'clientes', 'campos' => ['email']]));
  $formulario = $admin->datos($admin->post('formularios', ['titulo' => 'Pide presupuesto', 'recurso' => 'clientes', 'campos' => ['nombre', 'email', 'telefono', 'notas']]));
  igual('pide-presupuesto', $formulario['slug']);
  $def = $publica->datos($publica->get('formulario-publico', ['f' => 'pide-presupuesto']));
  igual(['nombre', 'email', 'telefono', 'notas'], array_column($def['campos'], 'clave'));
  $publica->datos($publica->pedir('POST', 'formulario-publico', ['f' => 'pide-presupuesto'], ['datos' => ['nombre' => 'Lead de la web', 'email' => 'lead@web.es', 'estado' => 'activo', 'empresa' => 'No pedida']], false));
  $cliente = array_values(array_filter($admin->datos($admin->get('clientes', ['q' => 'Lead de la web'])), fn($c) => $c['nombre'] === 'Lead de la web'))[0];
  igual(['potencial', null], [$cliente['estado'], $cliente['empresa']], 'entra como potencial y sin campos no pedidos');
  igual('Formulario web · Pide presupuesto', $admin->datos($admin->get('registro'))[0]['usuario']);
});
prueba('Formularios web: validan, frenan robots y abusos, y se pueden pausar', function () use ($admin, $publica, &$formulario) {
  $enviar = fn(array $cuerpo) => $publica->pedir('POST', 'formulario-publico', ['f' => 'pide-presupuesto'], $cuerpo, false);
  igual(true, isset(codigo(422, $enviar(['datos' => ['nombre' => 'X', 'email' => 'no-es-email']]))['errores']['email']));
  $antes = count($admin->datos($admin->get('clientes')));
  codigo(201, $enviar(['datos' => ['nombre' => 'Robot'], 'web' => 'http://spam.example']));
  igual($antes, count($admin->datos($admin->get('clientes'))), 'el robot no crea nada');
  for ($i = 2; $i <= 5; $i++) codigo(201, $enviar(['datos' => ['nombre' => "Lead $i"]]));
  codigo(429, $enviar(['datos' => ['nombre' => 'Lead 6']]));
  igual(5, $admin->datos($admin->get('formularios'))['formularios'][0]['envios']);
  $admin->datos($admin->put('formularios', $formulario['id'], ['activo' => false]));
  codigo(404, $publica->get('formulario-publico', ['f' => 'pide-presupuesto']));
  codigo(404, $publica->get('formulario-publico', ['f' => 'no-existe']));
});
prueba('Tareas programadas: se programan, se ejecutan, guardan el archivo y anotan la ejecución', function () use ($admin) {
  codigo(422, $admin->post('tareas', ['nombre' => 'Mala', 'informe' => 'no_existe']));
  $id = $admin->datos($admin->post('tareas', ['nombre' => 'Libro para la gestoría', 'informe' => 'libro_facturas', 'periodo' => 'ano_actual', 'frecuencia' => 'mensual', 'formato' => 'csv']))['id'];
  $tarea = array_values(array_filter($admin->datos($admin->get('tareas'))['tareas'], fn($t) => $t['id'] === $id))[0];
  igual(true, str_ends_with($tarea['proxima'], '07:00:00') && str_contains($tarea['proxima'], '-01 '), 'el día 1 a las 7:00');
  $r = $admin->datos($admin->post('tareas', null, ['accion' => 'ejecutar', 'id' => $id]));
  igual('ok', $r['estado']);
  $ejecucion = $admin->datos($admin->get('tareas'))['ejecuciones'][0];
  igual([true, 'manual'], [$ejecucion['disponible'], $ejecucion['origen']]);
  [$codigo, $csv] = $admin->get('tareas', ['descarga' => $ejecucion['id']]);
  igual(true, $codigo === 200 && str_contains($csv, 'Número;Fecha;Cliente'), 'se descarga el CSV');
  igual('extraer', $admin->datos($admin->get('registro'))[0]['accion']);
});
prueba('Rendimiento: muestras del servidor, tiempos por recurso y prueba de velocidad', function () use ($admin) {
  $d = $admin->datos($admin->get('rendimiento', ['horas' => 1]));
  igual(true, $d['actual'] !== null && $d['actual']['bd_kb'] > 0, 'hay una muestra');
  igual(true, $d['resumen']['peticiones'] > 20, 'se anotan las peticiones');
  igual(true, in_array('clientes', array_column($d['recursos'], 'recurso'), true));
  $m = $admin->datos($admin->post('rendimiento', null, ['accion' => 'medir']));
  igual(true, count($m['pruebas']) >= 10 && $m['total'] > 0);
  igual('medir', $admin->datos($admin->get('registro'))[0]['accion']);
});
prueba('Análisis: tendencia, concentración de clientes, cobertura de stock y recomendaciones', function () use ($admin, $comercial) {
  $a = $admin->datos($admin->get('analisis'));
  foreach (['tendencia', 'pareto', 'riesgo', 'cobertura', 'margenes', 'sin_facturar', 'dias_semana', 'calidad', 'recomendaciones'] as $clave) igual(true, isset($a[$clave]), $clave);
  igual(true, $a['pareto']['clientes_80'] >= 1 && $a['pareto']['clientes_80'] <= $a['pareto']['clientes']);
  igual(7, count($a['dias_semana']));
  igual(0, array_sum(array_column($a['calidad'], 'nif_no_valido')), 'los NIF de la demo son válidos');
  igual(true, count($a['recomendaciones']) > 0);
  igual(true, isset($comercial->datos($comercial->get('analisis'))['tendencia']), 'el comercial también lo ve');
});

echo "\nDatos reales y más consultas (v5.1)\n";

prueba('Código postal con su provincia, IBAN y teléfono se comprueban', function () use ($admin) {
  $c = $admin->datos($admin->post('clientes', ['nombre' => 'Datos españoles', 'codigo_postal' => '03001', 'iban' => 'es91 2100 0418 4502 0005 1332', 'telefono' => '+34 612 34 56 78']));
  igual(['Alicante', 'ES9121000418450200051332'], [$c['provincia'], $c['iban']]);
  $json = codigo(422, $admin->post('clientes', ['nombre' => 'X', 'codigo_postal' => '99001', 'iban' => 'ES9121000418450200051333', 'telefono' => '12345']));
  igual(['codigo_postal', 'iban', 'telefono'], array_values(array_intersect(['codigo_postal', 'iban', 'telefono'], array_keys($json['errores']))));
  igual('+44 20 7946 0958', $admin->datos($admin->post('proveedores', ['nombre' => 'Proveedor UK', 'telefono' => '+44 20 7946 0958']))['telefono']);
});
prueba('Importar pedidos con sus líneas: se agrupan por referencia y no tocan el stock', function () use ($admin, $stock) {
  $cliente = $admin->datos($admin->get('clientes', ['id' => 1]));
  $productos = $admin->datos($admin->get('productos'));
  [$p1, $p2] = [$productos[0], $productos[1]];
  $antes = [$stock($p1['id']), $stock($p2['id'])];
  $pedidos = count($admin->datos($admin->get('pedidos')));
  $csv = "Referencia;Cliente;Fecha;Estado;Producto;Cantidad;Precio\n"
    . "H-1;{$cliente['nif']};15/01/2025;entregado;{$p1['nombre']};2;\n"
    . "H-1;;;;{$p2['nombre']};1;10,50\n"
    . "H-2;{$cliente['nombre']};2025-02-01;entregado;Producto que no existe;1;\n";
  $previa = $admin->datos($admin->post('intercambio', ['contenido' => $csv, 'nombre' => 'historico.csv'], ['accion' => 'analizar', 'destino' => 'pedidos']));
  igual([2, 1, 1], [$previa['validas'], $previa['con_errores'], $previa['registros']]);
  $r = $admin->datos($admin->post('intercambio', ['contenido' => $csv, 'nombre' => 'historico.csv'], ['accion' => 'importar', 'destino' => 'pedidos']));
  igual([1, 2], [$r['importadas'], $r['lineas']]);
  igual($pedidos + 1, count($admin->datos($admin->get('pedidos'))));
  igual($antes, [$stock($p1['id']), $stock($p2['id'])], 'el stock no cambia');
  $nuevo = $admin->datos($admin->get('pedidos', ['cliente_id' => 1, 'desde' => '2025-01-15', 'hasta' => '2025-01-15']))[0];
  igual([$p1['precio'], 10.5], array_column($nuevo['lineas'], 'precio'), 'precio del catálogo si no viene');
});
$importacion = null;
prueba('Importar en modo «actualizar» y deshacer la importación', function () use ($admin, &$importacion) {
  $existente = $admin->datos($admin->get('clientes', ['id' => 2]));
  $csv = "NIF;Nombre;Teléfono\n{$existente['nif']};{$existente['nombre']};699 111 222\n;Cliente nuevo para deshacer;600 222 333\n";
  $r = $admin->datos($admin->post('intercambio', ['contenido' => $csv, 'nombre' => 'actualizar.csv', 'modo' => 'actualizar'], ['accion' => 'importar', 'destino' => 'clientes']));
  igual([1, 1], [$r['importadas'], $r['actualizadas']]);
  igual('699 111 222', $admin->datos($admin->get('clientes', ['id' => 2]))['telefono']);
  igual($existente['email'], $admin->datos($admin->get('clientes', ['id' => 2]))['email'], 'lo que no trae el archivo no cambia');
  $importacion = $r['importacion'];
  igual(true, $admin->datos($admin->get('intercambio'))['historial'][0]['id'] === $importacion);
  igual(['borrados' => 1, 'recuperados' => 1], $admin->datos($admin->post('intercambio', null, ['accion' => 'deshacer', 'id' => $importacion])));
  igual($existente['telefono'], $admin->datos($admin->get('clientes', ['id' => 2]))['telefono'], 'teléfono recuperado');
  igual([], array_filter($admin->datos($admin->get('clientes', ['q' => 'Cliente nuevo para deshacer']))));
  codigo(409, $admin->post('intercambio', null, ['accion' => 'deshacer', 'id' => $importacion]));
});
prueba('No se deshace una importación cuyos datos ya se usan', function () use ($admin, $comercial) {
  $csv = "Nombre;Email\nCliente con pedido;con-pedido@importado.es\n";
  $r = $admin->datos($admin->post('intercambio', ['contenido' => $csv], ['accion' => 'importar', 'destino' => 'clientes']));
  $cliente = $admin->datos($admin->get('clientes', ['q' => 'con-pedido@importado.es']))[0];
  $admin->datos($admin->post('pedidos', ['cliente_id' => $cliente['id'], 'estado' => 'pendiente', 'lineas' => [['producto_id' => 3, 'cantidad' => 1]]]));
  codigo(409, $admin->post('intercambio', null, ['accion' => 'deshacer', 'id' => $r['importacion']]));
  igual($cliente['id'], $admin->datos($admin->get('clientes', ['id' => $cliente['id']]))['id'], 'no se ha borrado nada');
  codigo(403, $comercial->post('intercambio', null, ['accion' => 'deshacer', 'id' => $r['importacion']]));
});
prueba('Duplicados: se encuentran y al fusionar los pedidos pasan al que se queda', function () use ($admin, $comercial) {
  $a = $admin->datos($admin->post('clientes', ['nombre' => 'Óscar Ruiz', 'email' => 'oscar@duplicado.es', 'telefono' => '611 000 111']));
  $b = $admin->datos($admin->post('clientes', ['nombre' => 'Oscar Ruiz Pérez', 'email' => 'OSCAR@duplicado.es', 'ciudad' => 'Sagunto', 'notas' => 'Llamar por la tarde']));
  $pedido = $admin->datos($admin->post('pedidos', ['cliente_id' => $b['id'], 'estado' => 'pendiente', 'lineas' => [['producto_id' => 3, 'cantidad' => 1]]]));
  $grupos = $admin->datos($admin->get('intercambio', ['duplicados' => 'clientes']));
  $grupo = array_values(array_filter($grupos, fn($g) => in_array($a['id'], array_column($g, 'id'), true)))[0];
  igual([$a['id'], $b['id']], array_column($grupo, 'id'));
  codigo(403, $comercial->post('intercambio', ['recurso' => 'clientes', 'conservar' => $a['id'], 'eliminar' => $b['id']], ['accion' => 'fusionar']));
  $r = $admin->datos($admin->post('intercambio', ['recurso' => 'clientes', 'conservar' => $a['id'], 'eliminar' => $b['id']], ['accion' => 'fusionar']));
  igual(1, $r['movidos']);
  igual($a['id'], $admin->datos($admin->get('pedidos', ['id' => $pedido['id']]))['cliente_id']);
  $fusionado = $admin->datos($admin->get('clientes', ['id' => $a['id']]));
  igual(['Sagunto', 'Llamar por la tarde'], [$fusionado['ciudad'], $fusionado['notas']], 'se completan los datos que faltaban');
  codigo(404, $admin->get('clientes', ['id' => $b['id']]));
  igual('fusionar', $admin->datos($admin->get('registro'))[0]['accion']);
});
prueba('Informes con filtros y comparados con el periodo anterior o el año pasado', function () use ($admin) {
  $base = ['desde' => '2020-01-01', 'hasta' => date('Y-m-d')];
  $todos = $admin->datos($admin->get('informes', ['informe' => 'ventas_cliente'] + $base));
  $soloUno = $admin->datos($admin->get('informes', ['informe' => 'ventas_periodo', 'cliente' => 1, 'agrupacion' => 'ano'] + $base));
  $lucia = array_values(array_filter($todos['filas'], fn($f) => $f['cliente'] === 'Lucía Martín'))[0];
  igual($lucia['importe'], $soloUno['totales']['importe'], 'el filtro por cliente cuadra');
  $categoria = $admin->datos($admin->get('informes', ['informe' => 'ventas_producto', 'categoria' => 'Audio'] + $base));
  igual(['Audio'], array_values(array_unique(array_column($categoria['filas'], 'categoria'))));
  $mes = date('Y-m-01');
  $c = $admin->datos($admin->get('informes', ['informe' => 'ventas_cliente', 'desde' => $mes, 'hasta' => date('Y-m-t'), 'comparar' => 'anterior']));
  igual([date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))], [$c['comparacion']['desde'], $c['comparacion']['hasta']]);
  igual(true, in_array('importe_anterior', array_column($c['columnas'], 'clave'), true) && in_array('variacion', array_column($c['columnas'], 'clave'), true));
  $anterior = $admin->datos($admin->get('informes', ['informe' => 'ventas_cliente', 'desde' => $c['comparacion']['desde'], 'hasta' => $c['comparacion']['hasta']]));
  igual($anterior['totales']['importe'] ?? 0, $c['comparacion']['anterior']);
  $a = $admin->datos($admin->get('informes', ['informe' => 'ventas_periodo', 'desde' => $mes, 'hasta' => date('Y-m-t'), 'agrupacion' => 'semana', 'comparar' => 'ano_anterior']));
  igual(date('Y-m-01', strtotime('-1 year', strtotime($mes))), $a['comparacion']['desde']);
});
prueba('Informes fiscales: IVA del trimestre y modelo 347', function () use ($admin) {
  $base = ['desde' => '2020-01-01', 'hasta' => date('Y-m-d')];
  $iva = $admin->datos($admin->get('informes', ['informe' => 'iva_trimestral'] + $base));
  $libro = $admin->datos($admin->get('informes', ['informe' => 'libro_facturas'] + $base));
  $repercutido = array_values(array_filter($iva['filas'], fn($f) => $f['concepto'] === 'IVA repercutido (total)'))[0];
  igual($libro['totales']['cuota'], round($repercutido['cuota'], 2));
  igual(true, str_starts_with(end($iva['filas'])['concepto'], 'Resultado'));
  $m347 = $admin->datos($admin->get('informes', ['informe' => 'modelo_347'] + $base));
  foreach ($m347['filas'] as $f) igual(true, abs($f['total']) > 3005.06 && round($f['t1'] + $f['t2'] + $f['t3'] + $f['t4'], 2) === $f['total'], $f['nombre']);
});
prueba('Exportar a Excel (.xlsx) informes, consultas y listados', function () use ($admin) {
  $leer = function (string $contenido): string {
    $ruta = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($ruta, $contenido);
    $zip = new ZipArchive();
    igual(true, $zip->open($ruta) === true, 'es un .xlsx válido');
    $hoja = (string)$zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($ruta);
    return $hoja;
  };
  [, $xlsx] = $admin->get('informes', ['informe' => 'ventas_cliente', 'desde' => '2020-01-01', 'formato' => 'xlsx']);
  $hoja = $leer($xlsx);
  igual(true, str_contains($hoja, '<t xml:space="preserve">Cliente</t>') && str_contains($hoja, 'TOTAL') && str_contains($hoja, '<autoFilter'));
  [, $xlsx] = $admin->get('clientes', ['formato' => 'xlsx']);
  igual(true, str_contains($leer($xlsx), 'Lucía Martín'));
  [, $xlsx] = $admin->get('consultas', ['sql' => 'SELECT id, nombre FROM productos', 'formato' => 'xlsx']);
  igual(true, str_contains($leer($xlsx), 'Disco SSD 1TB'));
});
prueba('Tabla cruzada en el constructor: clientes × meses', function () use ($admin) {
  $r = $admin->datos($admin->get('consultas', ['definicion' => json_encode(['recurso' => 'pedidos', 'agrupar' => 'cliente', 'cruzar' => 'fecha__mes', 'agregacion' => 'suma', 'sobre' => 'total'])]));
  igual('Total', end($r['columnas'])['etiqueta']);
  igual(true, (bool)preg_match('/^\d{4}-\d{2}$/', $r['columnas'][1]['etiqueta']), 'una columna por mes');
  igual(round(array_sum(array_column($admin->datos($admin->get('pedidos')), 'total')), 2), $r['totales']['total']);
  codigo(422, $admin->get('consultas', ['definicion' => json_encode(['recurso' => 'pedidos', 'agrupar' => 'cliente', 'cruzar' => 'cliente'])]));
});
prueba('Tareas con consultas guardadas en Excel; si fallan abren una incidencia (solo una)', function () use ($admin) {
  $buena = $admin->datos($admin->post('consultas', ['nombre' => 'Productos para tarea', 'tipo' => 'sql', 'definicion' => ['sql' => 'SELECT nombre, stock FROM productos']]))['id'];
  $mala = $admin->datos($admin->post('consultas', ['nombre' => 'Consulta rota', 'tipo' => 'sql', 'definicion' => ['sql' => 'SELECT * FROM tabla_que_no_existe']]))['id'];
  $t1 = $admin->datos($admin->post('tareas', ['nombre' => 'Stock en Excel', 'informe' => "consulta:$buena", 'formato' => 'xlsx', 'frecuencia' => 'semanal']))['id'];
  $r = $admin->datos($admin->post('tareas', null, ['accion' => 'ejecutar', 'id' => $t1]));
  igual(['ok', true], [$r['estado'], str_ends_with($r['archivo'], '.xlsx')]);
  $t2 = $admin->datos($admin->post('tareas', ['nombre' => 'Tarea que falla', 'informe' => "consulta:$mala", 'frecuencia' => 'diaria']))['id'];
  igual('error', $admin->datos($admin->post('tareas', null, ['accion' => 'ejecutar', 'id' => $t2]))['estado']);
  $admin->datos($admin->post('tareas', null, ['accion' => 'ejecutar', 'id' => $t2]));
  $incidencias = array_filter($admin->datos($admin->get('incidencias')), fn($i) => $i['titulo'] === 'Falla la tarea programada «Tarea que falla»');
  igual(1, count($incidencias), 'una sola incidencia abierta');
  igual(['tarea', 'abierta'], [reset($incidencias)['tipo'], reset($incidencias)['estado']]);
  $alertas = array_column($admin->datos($admin->get('panel'))['alertas'], 'texto');
  igual(true, (bool)array_filter($alertas, fn($t) => str_contains($t, '«Tarea que falla»')), 'el panel avisa');
  $admin->datos($admin->put('tareas', $t2, ['activa' => false]));
});
prueba('Incidencias: cualquiera las apunta, solo administración las borra', function () use ($admin, $comercial) {
  $i = $comercial->datos($comercial->post('incidencias', ['titulo' => 'La importación de proveedores dio errores', 'tipo' => 'datos', 'gravedad' => 'alta']));
  igual(['abierta', date('Y-m-d')], [$i['estado'], $i['fecha']]);
  codigo(403, $comercial->delete('incidencias', $i['id']));
  $admin->datos($admin->put('incidencias', $i['id'], ['titulo' => $i['titulo'], 'fecha' => $i['fecha'], 'tipo' => 'datos', 'gravedad' => 'alta', 'estado' => 'resuelta', 'solucion' => 'Faltaba el NIF en dos filas']));
  igual('resuelta', $admin->datos($admin->get('incidencias', ['id' => $i['id']]))['estado']);
});
prueba('Consultas lentas: se anotan con su SQL y se puede ver cómo las ejecuta la base de datos', function () use ($admin) {
  $admin->datos($admin->get('informes', ['informe' => 'ventas_producto']));
  $d = $admin->datos($admin->get('rendimiento', ['horas' => 1]));
  igual(true, count($d['lentas']) > 0 && in_array('informe', array_column($d['lentas'], 'origen'), true));
  $plan = $admin->datos($admin->get('rendimiento', ['explicar' => $d['lentas'][0]['id']]));
  igual(true, $plan['plan'] && count($plan['filas']) > 0);
  $sql = $admin->datos($admin->get('consultas', ['sql' => 'SELECT * FROM pedidos WHERE cliente_id = 1', 'explicar' => 1]));
  igual(true, $sql['usa_indice'], 'usa el índice de pedidos por cliente');
  $todo = $admin->datos($admin->get('consultas', ['sql' => 'SELECT * FROM clientes WHERE ciudad = \'Valencia\'', 'explicar' => 1]));
  igual(['clientes'], $todo['recorre'], 'sin índice recorre la tabla');
  $c = $admin->datos($admin->get('consultas', ['definicion' => json_encode(['recurso' => 'clientes', 'agrupar' => 'ciudad']), 'explicar' => 1]));
  igual(true, $c['plan']);
});
prueba('Análisis: previsión de ventas, clientes por segmentos (RFM) y avisos del panel', function () use ($admin, $comercial) {
  $a = $admin->datos($admin->get('analisis'));
  igual([12, 3], [count($a['prevision']['historico']), count($a['prevision']['prevision'])]);
  igual(date('Y-m'), $a['prevision']['prevision'][0]['mes']);
  igual(['campeones', 'leales', 'nuevos', 'riesgo', 'dormidos', 'otros'], array_column($a['rfm'], 'id'));
  $conCompras = count($admin->datos($admin->get('consultas', ['sql' => "SELECT DISTINCT cliente_id FROM pedidos WHERE estado <> 'cancelado'"]))['filas']);
  igual($conCompras, array_sum(array_map(fn($g) => count($g['clientes']), $a['rfm'])), 'cada cliente con compras en un segmento');
  $panel = $comercial->datos($comercial->get('panel'));
  igual(true, is_array($panel['alertas']));
  igual(false, (bool)array_filter(array_column($panel['alertas'], 'enlace'), fn($e) => in_array($e, ['#/tareas', '#/rendimiento'], true)), 'el comercial no ve avisos de administración');
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

resumen();
