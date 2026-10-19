<?php
// Pruebas de instalación y configuración (RA2).
// Uso: php tests/instalacion.php http://127.0.0.1:8766  (servidor con un ERP SIN instalar)
// Las lanza tests/ejecutar.sh, que prepara ese servidor con rutas temporales.

require __DIR__ . '/marco.php';

$raiz = rtrim($argv[1] ?? 'http://127.0.0.1:8766', '/');
$base = "$raiz/api/api.php";
$rutaInstalacion = $argv[2] ?? '';
$sqlite = $rutaInstalacion ? dirname($rutaInstalacion) . '/erp.sqlite' : '';
$web = new Sesion($base);
$admin = new Sesion($base);

$instalacion = [
  'perfil' => 'consultoria', 'motor' => 'sqlite', 'demo' => false,
  'empresa' => ['nombre' => 'Consultora Prueba S.L.', 'nif' => 'B11111119'],
  'admin'   => ['nombre' => 'Laura Admin', 'email' => 'laura@consultora.test', 'clave' => 'laura12345'],
];

echo "\nAntes de instalar\n";

prueba('El ERP se presenta como no instalado y la aplicación responde 503', function () use ($web) {
  igual(false, $web->datos($web->get('estado'))['instalado']);
  codigo(503, $web->get('clientes'));
  codigo(503, $web->get('sesion'));
});
prueba('El asistente recibe requisitos, módulos y perfiles (sin contraseñas)', function () use ($web) {
  $info = $web->datos($web->get('requisitos'));
  igual(true, $info['ok']);
  igual(['consultoria', 'tienda', 'distribuidora'], array_column($info['perfiles'], 'id'));
  igual(['nucleo', 'crm', 'inventario', 'ventas', 'facturacion', 'compras'], array_column($info['modulos'], 'id'));
  igual(false, array_key_exists('clave', $info['mysql_por_defecto']), 'la contraseña de MySQL no se envía');
});
prueba('Los datos incorrectos se rechazan campo a campo', function () use ($web) {
  $json = codigo(422, $web->post('instalar', ['perfil' => 'nada', 'admin' => ['email' => 'mal', 'clave' => '123']]));
  foreach (['perfil', 'empresa.nombre', 'admin.nombre', 'admin.email', 'admin.clave'] as $campo) {
    if (!isset($json['errores'][$campo])) throw new Exception("Falta el error de $campo");
  }
});
prueba('Con credenciales MySQL incorrectas, la prueba de conexión explica el motivo', function () use ($web) {
  $json = codigo(422, $web->post('probar-bd', ['mysql' => ['host' => '127.0.0.1', 'bd' => 'no_existe', 'usuario' => 'nadie', 'clave' => 'x']]));
  igual(true, isset($json['errores']['mysql']));
});

echo "\nInstalación (supuesto «Consultoría»: solo CRM)\n";

prueba('El asistente instala el perfil con sus módulos', function () use ($web, $instalacion) {
  $resultado = $web->datos($web->post('instalar', $instalacion));
  igual(['nucleo', 'crm'], $resultado['modulos']);
  igual(true, str_starts_with($resultado['informe'], '# Informe de instalación'), 'el asistente devuelve el informe');
  igual(true, $web->datos($web->get('estado'))['instalado']);
});
prueba('No se puede instalar dos veces', function () use ($web, $instalacion) {
  codigo(409, $web->post('instalar', $instalacion));
  codigo(409, $web->get('requisitos'));
});
prueba('instalacion.json guarda el motor y el perfil, pero no la contraseña del administrador', function () use ($rutaInstalacion) {
  if (!$rutaInstalacion) return;
  $texto = (string)file_get_contents($rutaInstalacion);
  $json = json_decode($texto, true);
  igual(['consultoria', 'sqlite'], [$json['perfil'], $json['motor']]);
  igual(false, str_contains($texto, 'laura12345'));
});
prueba('El administrador creado en la instalación puede entrar', function () use ($admin, $instalacion) {
  igual('admin', $admin->datos($admin->entrar($instalacion['admin']['email'], $instalacion['admin']['clave']))['rol']);
});
prueba('Solo aparecen los módulos instalados; los demás responden 403', function () use ($admin) {
  igual(['panel', 'clientes', 'informes', 'analisis', 'consultas', 'rendimiento', 'intercambio', 'formularios', 'tareas', 'usuarios', 'incidencias', 'registro', 'modulos', 'ajustes', 'api', 'sistema'],
    array_column($admin->datos($admin->get('menu')), 'id'));
  $json = codigo(403, $admin->get('pedidos'));
  igual(true, str_contains($json['error'], 'no está activo'));
});
prueba('Sin datos de demostración, el ERP empieza vacío', function () use ($admin) {
  igual(0, count($admin->datos($admin->get('clientes'))));
});

echo "\nConfiguración de módulos\n";

prueba('Activar Ventas activa también sus dependencias (CRM e Inventario)', function () use ($admin) {
  $modulos = $admin->datos($admin->post('modulos', ['activo' => true], ['modulo' => 'ventas']));
  $activos = array_column(array_filter($modulos, fn($m) => $m['activo']), 'id');
  igual(['nucleo', 'crm', 'inventario', 'ventas'], array_values($activos));
  $menu = array_column($admin->datos($admin->get('menu')), 'id');
  igual(true, in_array('pedidos', $menu, true) && in_array('productos', $menu, true));
});
prueba('No se puede desactivar un módulo que otro necesita, ni el núcleo', function () use ($admin) {
  $json = codigo(409, $admin->post('modulos', ['activo' => false], ['modulo' => 'inventario']));
  igual(true, str_contains($json['error'], 'Ventas'));
  codigo(409, $admin->post('modulos', ['activo' => false], ['modulo' => 'nucleo']));
});
prueba('Desactivar un módulo conserva sus datos', function () use ($admin) {
  $admin->datos($admin->post('productos', ['nombre' => 'Producto de prueba', 'precio' => 10, 'stock' => 3]));
  $admin->datos($admin->post('modulos', ['activo' => false], ['modulo' => 'ventas']));
  $admin->datos($admin->post('modulos', ['activo' => false], ['modulo' => 'inventario']));
  codigo(403, $admin->get('productos'));
  $admin->datos($admin->post('modulos', ['activo' => true], ['modulo' => 'inventario']));
  igual(['Producto de prueba'], array_column($admin->datos($admin->get('productos')), 'nombre'));
});

echo "\nAjustes\n";

prueba('Los ajustes incorrectos se rechazan campo a campo', function () use ($admin) {
  $json = codigo(422, $admin->post('ajustes', ['empresa' => ['nombre' => ''], 'iva' => 150, 'serie_factura' => 'F', 'serie_rectificativa' => 'F', 'stock_minimo' => -1, 'por_pagina' => 7]));
  foreach (['empresa.nombre', 'iva', 'serie_rectificativa', 'stock_minimo', 'por_pagina'] as $campo) {
    if (!isset($json['errores'][$campo])) throw new Exception("Falta el error de $campo");
  }
});
prueba('Los ajustes se aplican en toda la aplicación (empresa, stock mínimo, paginación)', function () use ($admin) {
  $admin->datos($admin->post('ajustes', ['empresa' => ['nombre' => 'Consultora Nueva S.L.', 'email' => 'hola@nueva.test'],
    'iva' => 10, 'serie_factura' => 'FV', 'serie_rectificativa' => 'RV', 'stock_minimo' => 4, 'por_pagina' => 25]));
  igual('Consultora Nueva S.L.', $admin->datos($admin->get('info'))['empresa']['nombre']);
  igual(4, $admin->datos($admin->get('esquema'))['productos']['campos']['stock']['alerta']);
  igual(25, $admin->datos($admin->get('productos', ['pagina' => 1]))['por']);
});
prueba('El registro anota la instalación y los cambios de configuración', function () use ($admin) {
  $acciones = array_column($admin->datos($admin->get('registro')), 'accion');
  igual(true, in_array('instalar', $acciones, true) && in_array('configurar', $acciones, true));
});

echo "\nActualización (simulando una base de datos de la v4.0 que pasa a la v5.1)\n";

$comercial = new Sesion($base);
prueba('Se prepara una base de datos «antigua»: sin las migraciones de la v4.1 ni de la v5', function () use ($admin, $sqlite, $rutaInstalacion) {
  $admin->datos($admin->post('usuarios', ['nombre' => 'Pilar Comercial', 'email' => 'pilar@consultora.test', 'clave' => 'pilar12345', 'rol' => 'comercial']));
  $pdo = new PDO("sqlite:$sqlite");
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  foreach (['idx_pedidos_fecha', 'idx_pedido_lineas_pedido', 'idx_compra_lineas_compra', 'idx_facturas_fecha', 'idx_clientes_estado', 'idx_registro_fecha'] as $i) $pdo->exec("DROP INDEX IF EXISTS $i");
  $pdo->exec('ALTER TABLE clientes DROP COLUMN notas');
  $pdo->exec('ALTER TABLE usuarios DROP COLUMN permisos');
  $pdo->exec('DROP TABLE claves_api');
  foreach (['consultas', 'formulario_envios', 'formularios', 'tarea_ejecuciones', 'tareas', 'metricas', 'peticiones', 'importaciones', 'incidencias', 'consultas_lentas'] as $t) $pdo->exec("DROP TABLE $t");
  foreach (['clientes', 'proveedores'] as $t) foreach (['codigo_postal', 'provincia', 'iban'] as $c) $pdo->exec("ALTER TABLE $t DROP COLUMN $c");
  foreach (['idx_pedido_lineas_producto', 'idx_compra_lineas_producto', 'idx_pedidos_cliente'] as $i) $pdo->exec("DROP INDEX IF EXISTS $i");
  $pdo->exec("DELETE FROM migraciones WHERE id > '002'");
  $pdo->exec("DELETE FROM migraciones WHERE id LIKE '002%'");
  $json = json_decode(file_get_contents($rutaInstalacion), true);
  file_put_contents($rutaInstalacion, json_encode(['version' => '4.0'] + $json));
});
prueba('Con migraciones pendientes, el ERP queda en mantenimiento (503)', function () use ($admin) {
  $estado = $admin->datos($admin->get('estado'));
  igual([13, '4.0'], [$estado['actualizacion'], $estado['version_instalada']]);
  codigo(503, $admin->get('clientes'));
});
prueba('Solo un administrador puede actualizar', function () use ($comercial) {
  $comercial->datos($comercial->entrar('pilar@consultora.test', 'pilar12345'));
  codigo(403, $comercial->get('actualizar'));
});
prueba('La pantalla de actualización lista las migraciones pendientes', function () use ($admin) {
  $info = $admin->datos($admin->get('actualizar'));
  igual(['002-indices', '003-clientes-notas', '004-claves-api', '005-permisos-usuarios', '006-consultas-guardadas', '007-formularios-web',
    '008-extracciones-programadas', '009-metricas-rendimiento', '010-indices-analisis', '011-importaciones', '012-datos-espanoles',
    '013-incidencias', '014-consultas-lentas'], array_column($info['pendientes'], 'id'));
  igual(['4.0', '5.1'], [$info['instalada'], $info['nueva']]);
});
prueba('Actualizar hace una copia de seguridad y aplica las migraciones', function () use ($admin, $rutaInstalacion) {
  $resultado = $admin->datos($admin->post('actualizar'));
  igual(13, count($resultado['aplicadas']));
  igual(true, is_file(dirname($rutaInstalacion) . '/copias/' . $resultado['copia']), 'la copia existe');
  igual(0, $admin->datos($admin->get('estado'))['actualizacion']);
  igual('5.1', json_decode(file_get_contents($rutaInstalacion), true)['version']);
  igual([], $admin->datos($admin->get('consultas'))['guardadas'], 'las tablas de la v5.0 existen');
  igual('Valencia', $admin->datos($admin->post('clientes', ['nombre' => 'Con CP', 'codigo_postal' => '46001']))['provincia'], 'las columnas de la v5.1 existen');
  $cliente = $admin->datos($admin->post('clientes', ['nombre' => 'Cliente con notas', 'notas' => "Prefiere email.\nLlamar los lunes."]));
  igual("Prefiere email.\nLlamar los lunes.", $cliente['notas']);
  codigo(409, $admin->post('actualizar'));
});

echo "\nServicios de acceso: claves de API\n";

$claves = [];
prueba('El administrador crea claves; la clave solo se muestra una vez', function () use ($admin, $sqlite, &$claves) {
  $claves['lectura'] = $admin->datos($admin->post('claves', ['nombre' => 'Tienda online', 'recursos' => ['clientes']]));
  $claves['escritura'] = $admin->datos($admin->post('claves', ['nombre' => 'Formulario web', 'recursos' => ['clientes'], 'escritura' => true]));
  igual(1, preg_match('/^blush_[0-9a-f]{40}$/', $claves['lectura']['clave']));
  igual(false, array_key_exists('clave', $admin->datos($admin->get('claves'))[0]), 'el listado no muestra la clave');
  $guardado = (new PDO("sqlite:$sqlite"))->query('SELECT hash FROM claves_api')->fetchAll(PDO::FETCH_COLUMN);
  igual(false, in_array($claves['lectura']['clave'], $guardado, true), 'en la BD solo está la huella');
  codigo(422, $admin->post('claves', ['nombre' => '', 'recursos' => ['usuarios']]));
});
prueba('Otro programa lee con su clave, sin sesión', function () use ($base, &$claves) {
  $api = new Sesion($base, ['X-API-Key: ' . $claves['lectura']['clave']]);
  igual(true, count($api->datos($api->get('clientes'))) > 0);
  igual(['clientes'], array_keys($api->datos($api->get('esquema'))));
});
prueba('La clave limita recursos y acciones', function () use ($base, &$claves) {
  $lectura = new Sesion($base, ['X-API-Key: ' . $claves['lectura']['clave']]);
  codigo(403, $lectura->post('clientes', ['nombre' => 'X']));
  codigo(403, $lectura->get('usuarios'));
  codigo(403, $lectura->get('sistema'));
  $escritura = new Sesion($base, ['X-API-Key: ' . $claves['escritura']['clave']]);
  $nuevo = $escritura->datos($escritura->post('clientes', ['nombre' => 'Alta desde la web']));
  codigo(403, $escritura->delete('clientes', $nuevo['id']));
});
prueba('El registro anota quién usó la API; una clave revocada deja de funcionar', function () use ($admin, $base, &$claves) {
  igual(true, in_array('API · Formulario web', array_column($admin->datos($admin->get('registro')), 'usuario'), true));
  $admin->datos($admin->delete('claves', $claves['escritura']['id']));
  codigo(401, (new Sesion($base, ['X-API-Key: ' . $claves['escritura']['clave']]))->get('clientes'));
  codigo(401, (new Sesion($base, ['X-API-Key: blush_inventada']))->get('clientes'));
});

echo "\nPermisos por módulo y usuario\n";

prueba('Un comercial con permisos propios solo puede lo que tiene marcado', function () use ($admin, $comercial) {
  $pilar = array_values(array_filter($admin->datos($admin->get('usuarios')), fn($u) => $u['email'] === 'pilar@consultora.test'))[0];
  $admin->datos($admin->put('usuarios', $pilar['id'], ['nombre' => $pilar['nombre'], 'email' => $pilar['email'], 'rol' => 'comercial', 'permisos' => '{"crm":["ver"]}']));
  $comercial->datos($comercial->entrar('pilar@consultora.test', 'pilar12345'));
  igual(true, count($comercial->datos($comercial->get('clientes'))) > 0);
  codigo(403, $comercial->post('clientes', ['nombre' => 'X']));
  codigo(403, $comercial->get('productos'));
});
prueba('Marcar «crear» incluye «ver»; los permisos no válidos se rechazan', function () use ($admin) {
  $pilar = array_values(array_filter($admin->datos($admin->get('usuarios')), fn($u) => $u['email'] === 'pilar@consultora.test'))[0];
  $guardado = $admin->datos($admin->put('usuarios', $pilar['id'], ['nombre' => $pilar['nombre'], 'email' => $pilar['email'], 'rol' => 'comercial', 'permisos' => '{"crm":["crear"]}']));
  igual(['crm' => ['ver', 'crear']], $guardado['permisos']);
  codigo(422, $admin->put('usuarios', $pilar['id'], ['nombre' => $pilar['nombre'], 'email' => $pilar['email'], 'rol' => 'comercial', 'permisos' => '{"nucleo":["ver"]}']));
});

echo "\nEntornos\n";

prueba('El entorno se cambia en Ajustes y queda en instalacion.json', function () use ($admin, $rutaInstalacion) {
  igual('explotacion', $admin->datos($admin->get('estado'))['entorno']);
  $ajustes = $admin->datos($admin->get('ajustes'));
  codigo(422, $admin->post('ajustes', ['entorno' => 'otro'] + $ajustes));
  $admin->datos($admin->post('ajustes', ['entorno' => 'desarrollo'] + $ajustes));
  igual('desarrollo', json_decode(file_get_contents($rutaInstalacion), true)['entorno']);
});
prueba('En explotación, la falta de HTTPS pasa de aviso a error', function () use ($admin) {
  $https = fn() => array_values(array_filter($admin->datos($admin->get('sistema'))['comprobaciones'], fn($c) => $c['nombre'] === 'HTTPS'))[0]['estado'];
  igual('aviso', $https());
  $admin->datos($admin->post('ajustes', ['entorno' => 'explotacion'] + $admin->datos($admin->get('ajustes'))));
  igual('error', $https());
});

echo "\nInforme y reinstalación\n";

prueba('El informe de instalación resume perfil, módulos y migraciones', function () use ($admin) {
  $md = $admin->datos($admin->get('informe'))['markdown'];
  foreach (['# Informe de instalación', 'Consultoría / agencia', '005-permisos-usuarios', '| CRM | Activo |'] as $texto) {
    if (!str_contains($md, $texto)) throw new Exception("Falta «{$texto}» en el informe");
  }
});
prueba('Reinstalar exige contraseña y nombre de la empresa, guarda copia y vuelve al asistente', function () use ($admin, $web, $rutaInstalacion) {
  codigo(422, $admin->post('reinstalar', ['confirmacion' => 'otra', 'clave' => 'mala']));
  $empresa = $admin->datos($admin->get('info'))['empresa']['nombre'];
  $resultado = $admin->datos($admin->post('reinstalar', ['confirmacion' => $empresa, 'clave' => 'laura12345']));
  igual(true, is_file(dirname($rutaInstalacion) . '/copias/' . $resultado['copia']));
  igual(false, $web->datos($web->get('estado'))['instalado']);
  igual(true, $web->datos($web->get('requisitos'))['ok']);
});

echo "\nSupuestos: instalación desatendida de cada perfil\n";

// Instala un perfil con scripts/instalar.php en rutas temporales, arranca un servidor y devuelve su menú
function instalarSupuesto(string $perfil, int $puerto): array {
  $carpeta = sys_get_temp_dir() . '/blush-supuesto-' . $perfil . '-' . getmypid();
  @mkdir($carpeta);
  $sqlite = "$carpeta/erp.sqlite";
  $json = "$carpeta/instalacion.json";
  $instalar = sprintf('php %s --perfil=%s --empresa=%s --admin-nombre=Admin --admin-email=admin@%s.test --admin-clave=clave12345 --demo --entorno=pruebas --sqlite=%s --instalacion=%s 2>&1',
    escapeshellarg(dirname(__DIR__) . '/scripts/instalar.php'), $perfil, escapeshellarg("Empresa $perfil"), $perfil, escapeshellarg($sqlite), escapeshellarg($json));
  exec($instalar, $salida, $codigo);
  if ($codigo !== 0) throw new Exception('El instalador falló: ' . implode(' ', $salida));

  $servidor = proc_open(['php', '-S', "127.0.0.1:$puerto", '-t', dirname(__DIR__)], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tuberias, null,
    ['ERP_SQLITE' => $sqlite, 'ERP_INSTALACION' => $json, 'PATH' => getenv('PATH')]);
  try {
    $sesion = new Sesion("http://127.0.0.1:$puerto/api/api.php");
    for ($i = 0; $i < 50 && @file_get_contents("http://127.0.0.1:$puerto/api/api.php?recurso=estado") === false; $i++) usleep(100000);
    $sesion->datos($sesion->entrar("admin@$perfil.test", 'clave12345'));
    $menu = array_column($sesion->datos($sesion->get('menu')), 'id');
    $clientes = count($sesion->datos($sesion->get('clientes')));
    return [$menu, $clientes];
  } finally {
    proc_terminate($servidor);
    proc_close($servidor);
    array_map('unlink', glob("$carpeta/*"));
    @rmdir($carpeta);
  }
}

$supuestos = [
  'consultoria'   => [[], ['pedidos', 'productos', 'compras']],
  'tienda'        => [['pedidos', 'facturas', 'productos'], ['proveedores', 'compras']],
  'distribuidora' => [['pedidos', 'facturas', 'productos', 'proveedores', 'compras'], []],
];
$puerto = 8790;
foreach ($supuestos as $perfil => [$con, $sin]) {
  prueba("Perfil «{$perfil}»: módulos esperados y datos de demostración", function () use ($perfil, $con, $sin, &$puerto) {
    [$menu, $clientes] = instalarSupuesto($perfil, $puerto++);
    foreach ($con as $r) if (!in_array($r, $menu, true)) throw new Exception("Falta $r en el menú");
    foreach ($sin as $r) if (in_array($r, $menu, true)) throw new Exception("$r no debería estar en el menú");
    igual(7, $clientes, 'clientes de demostración');
  });
}

resumen();
