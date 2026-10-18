<?php
// Punto de entrada único de la API.
//
//   Instalación (sin sesión, solo mientras el ERP no está instalado):
//   GET    api.php?recurso=estado                  ¿Está instalado? (siempre disponible)
//   GET    api.php?recurso=requisitos              Requisitos, módulos y perfiles para el asistente
//   POST   api.php?recurso=probar-bd               Prueba la conexión MySQL {mysql: {host, bd, usuario, clave}}
//   POST   api.php?recurso=instalar                Instala {perfil, modulos, motor, mysql, entorno, empresa, admin, demo}
//
//   Actualización (si hay migraciones pendientes, el resto de la API responde 503):
//   GET    api.php?recurso=actualizar              Versión instalada, nueva y migraciones pendientes (admin)
//   POST   api.php?recurso=actualizar              Copia de seguridad + migraciones (admin)
//
//   Sesión:
//   GET    api.php?recurso=sesion                  Usuario conectado y sus permisos (o null)
//   POST   api.php?recurso=entrar · salir · mi-clave
//
//   Aplicación:
//   GET    api.php?recurso=menu | esquema | info   Datos para montar la interfaz
//   GET    api.php?recurso=panel                   Indicadores del panel
//   GET    api.php?recurso=registro                Últimas operaciones realizadas
//   GET    api.php?recurso=sistema                 Diagnóstico de SO, PHP y base de datos (admin)
//   GET    api.php?recurso=modulos                 Módulos y su estado (admin)
//   POST   api.php?recurso=modulos&modulo=compras  Activa o desactiva un módulo {activo} (admin)
//   GET    api.php?recurso=ajustes                 Parámetros de la instalación (admin)
//   POST   api.php?recurso=ajustes                 Guarda los parámetros (admin)
//   POST   api.php?recurso=facturar&id=5 · rectificar&id=7
//   GET    api.php?recurso=copia · POST restaurar  Copias de seguridad (admin)
//   GET    api.php?recurso=claves · POST claves · DELETE claves&id=3   Claves de API (admin)
//   GET    api.php?recurso=informe                 Informe de instalación en Markdown (admin)
//   POST   api.php?recurso=reinstalar              Copia y vuelta al asistente {confirmacion, clave} (admin)
//
//   Otros programas: cualquier recurso de datos con la cabecera X-API-Key (sin sesión ni X-Blush).
//   GET    api.php?recurso=clientes[&q=&orden=&dir=&pagina=&formato=csv]
//   GET    api.php?recurso=clientes&id=3           Un registro
//   POST   api.php?recurso=clientes                Alta (cuerpo JSON)
//   PUT    api.php?recurso=clientes&id=3           Edición (cuerpo JSON)
//   DELETE api.php?recurso=clientes&id=3           Borrado
//
// Respuesta: {"ok": true, "datos": …} o {"ok": false, "error": "…", "errores": {campo: mensaje}}

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$config  = require __DIR__ . '/config.php';
$esquema = require __DIR__ . '/esquema.php';
date_default_timezone_set($config['zona_horaria']);
require __DIR__ . '/auth.php';
require __DIR__ . '/bd.php';
require __DIR__ . '/instalacion.php';
require __DIR__ . '/migraciones.php';
require __DIR__ . '/claves.php';
require __DIR__ . '/negocio.php';
require __DIR__ . '/crud.php';
require __DIR__ . '/panel.php';
require __DIR__ . '/sistema.php';
require __DIR__ . '/copia.php';
require __DIR__ . '/nucleo.php';

$recurso = $_GET['recurso'] ?? '';
$metodo  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id']) ? (int)$_GET['id'] : null;

$claveApi = $_SERVER['HTTP_X_API_KEY'] ?? null;

try {
  // Protección CSRF extra: las escrituras con sesión deben llevar la cabecera que pone nucleo/js/api.js.
  // Un formulario de otra web no puede añadir cabeceras propias. Con clave de API no hay cookies.
  if ($metodo !== 'GET' && $claveApi === null && ($_SERVER['HTTP_X_BLUSH'] ?? '') !== '1') fallar('Petición no permitida', 403);

  // ---------- Instalación ----------

  $config = conInstalacion($config);
  $instalado = $config['instalacion'] !== null;

  if ($recurso === 'estado' && !$instalado) {
    responder(['instalado' => false, 'version' => VERSION_APP]);
  }
  if (in_array($recurso, ['requisitos', 'probar-bd', 'instalar'], true)) {
    if ($instalado) fallar('El ERP ya está instalado.', 409);
    if ($recurso === 'requisitos') {
      responder(requisitos($config) + [
        'modulos'  => listarModulos(catalogoModulos(), [], $esquema),
        'perfiles' => array_values(perfiles()),
      ]);
    }
    if ($metodo !== 'POST') fallar('Método no permitido', 405);
    if ($recurso === 'probar-bd') responder(probarMysql(datosMysql(leerCuerpo())));
    responder(instalar($config, $esquema, leerCuerpo()), 201);
  }
  if (!$instalado) fallar('El ERP no está instalado. Abre instalar.html para instalarlo.', 503);

  // ---------- ERP instalado: base de datos, ajustes y módulos activos ----------

  $pdo = conectar($config, $esquema);
  asegurarTablaMigraciones($pdo);
  $pendientes = migracionesPendientes($pdo);
  $catalogo = catalogoModulos();
  $GLOBALS['catalogo'] = $catalogo;
  $GLOBALS['recurso_modulo'] = mapaRecursos($catalogo);

  if ($recurso === 'estado') {
    responder([
      'instalado' => true, 'version' => VERSION_APP, 'entorno' => $config['instalacion']['entorno'],
      'version_instalada' => $config['instalacion']['version'] ?? null, 'actualizacion' => count($pendientes),
    ]);
  }

  // ---------- Actualización pendiente: solo sesión y «actualizar» (admin) ----------

  if ($pendientes) {
    $GLOBALS['modulos_activos'] = ['nucleo'];
    $config['empresa'] = ajustesPorDefecto($config, $catalogo)['empresa'] + ['iva' => 21];
    if ($claveApi !== null) fallar('El ERP está en mantenimiento: hay una actualización pendiente.', 503);
    iniciarSesion();
    if ($recurso === 'sesion') {
      $usuario = cargarUsuario($pdo);
      responder($usuario ? ['usuario' => $usuario, 'permisos' => []] : ['usuario' => null]);
    }
    if ($recurso === 'entrar' && $metodo === 'POST') responder(entrar($pdo, leerCuerpo()));
    if ($recurso === 'salir' && $metodo === 'POST') { salir(); responder(null); }
    $usuario = exigirSesion($pdo);
    if ($recurso !== 'actualizar') fallar('El ERP está en mantenimiento: hay una actualización pendiente.', 503, ['actualizacion' => count($pendientes)]);
    if ($usuario['rol'] !== 'admin') fallar('Solo un administrador puede actualizar el ERP.', 403);
    if ($metodo === 'POST') responder(actualizar($pdo, $config, $esquema));
    responder([
      'instalada' => $config['instalacion']['version'] ?? '?', 'nueva' => VERSION_APP,
      'pendientes' => $pendientes, 'motor' => $config['motor'],
    ]);
  }

  $ajustes = leerAjustes($pdo, $config, $catalogo);
  $config = aplicarAjustes($config, $esquema, $ajustes);
  $GLOBALS['modulos_activos'] = $ajustes['modulos'];

  // ---------- Otros programas con clave de API ----------

  if ($claveApi !== null) {
    $usuario = usuarioApi($pdo, $claveApi);
    if ($recurso === 'esquema') responder(array_intersect_key($esquema, array_flip($usuario['recursos'])));
    if ($recurso === 'facturar') {
      exigirPermiso($esquema, $usuario, 'crear', 'facturas');
      exigirId($id);
      $pdo->beginTransaction();
      $factura = facturar($pdo, $config, $id);
      $pdo->commit();
      responder($factura, 201);
    }
    if (!isset($esquema[$recurso])) fallar('Con una clave de API solo se puede acceder a los datos (clientes, pedidos…).', 403);
    crud($pdo, $config, $esquema, $usuario, $recurso, $metodo, $id);
  }

  iniciarSesion();

  // Rutas de sesión
  if ($recurso === 'sesion') {
    $usuario = cargarUsuario($pdo);
    responder($usuario ? ['usuario' => $usuario, 'permisos' => permisosDe($esquema, $usuario)] : ['usuario' => null]);
  }
  if ($recurso === 'entrar' && $metodo === 'POST') responder(entrar($pdo, leerCuerpo()));
  if ($recurso === 'salir' && $metodo === 'POST') {
    if (cargarUsuario($pdo)) registrar($pdo, 'salir', 'sesion', usuarioActual()['id'], 'Cierre de sesión de ' . usuarioActual()['nombre']);
    salir();
    responder(null);
  }

  $usuario = exigirSesion($pdo);
  session_write_close(); // a partir de aquí no se escribe en la sesión: no bloquea otras peticiones

  $acciones = ['facturar', 'rectificar', 'restaurar', 'mi-clave', 'modulos', 'ajustes', 'claves', 'reinstalar', 'actualizar'];
  if ($metodo !== 'GET' && !isset($esquema[$recurso]) && !in_array($recurso, $acciones, true)) fallar('Método no permitido', 405);
  if (in_array($recurso, ['facturar', 'rectificar', 'restaurar', 'mi-clave', 'reinstalar'], true) && $metodo !== 'POST') fallar('Método no permitido', 405);

  switch ($recurso) {
    case 'menu':
      $menu = [['id' => 'panel', 'titulo' => 'Panel', 'icono' => 'panel', 'grupo' => null]];
      foreach ($esquema as $clave => $modulo) {
        $menu[] = ['id' => $clave, 'titulo' => $modulo['titulo'], 'icono' => $modulo['icono'], 'grupo' => $modulo['grupo']];
      }
      $menu[] = ['id' => 'registro', 'titulo' => 'Registro', 'icono' => 'registro', 'grupo' => 'Administración'];
      $menu[] = ['id' => 'modulos',  'titulo' => 'Módulos',  'icono' => 'modulos',  'grupo' => 'Administración'];
      $menu[] = ['id' => 'ajustes',  'titulo' => 'Ajustes',  'icono' => 'ajustes',  'grupo' => 'Administración'];
      $menu[] = ['id' => 'api',      'titulo' => 'Acceso API', 'icono' => 'llave',  'grupo' => 'Administración'];
      $menu[] = ['id' => 'sistema',  'titulo' => 'Sistema',  'icono' => 'sistema',  'grupo' => 'Administración'];
      // Agrupado por sección; solo lo que el usuario puede ver y cuyo módulo está activo
      $grupos = [null, 'Ventas', 'Compras', 'Administración'];
      usort($menu, fn($a, $b) => array_search($a['grupo'], $grupos) <=> array_search($b['grupo'], $grupos));
      responder(array_values(array_filter($menu, fn($m) => puede($esquema, $usuario, 'ver', $m['id']))));

    case 'esquema':
      responder($esquema);

    case 'info':
      responder([
        'app' => $config['app'], 'empresa' => $config['empresa'], 'entorno' => entorno($config),
        'modulos' => $ajustes['modulos'], 'perfil' => $config['instalacion']['perfil'] ?? null,
        'entorno_erp' => $config['instalacion']['entorno'], 'entornos' => ENTORNOS,
      ]);

    case 'panel':
      responder(panel($pdo, $esquema) + ['modulos' => $ajustes['modulos']]);

    case 'registro':
      exigirPermiso($esquema, $usuario, 'ver', 'registro');
      $filas = $pdo->query('SELECT * FROM registro ORDER BY id DESC LIMIT 200')->fetchAll();
      responder(array_map(fn($f) => [...$f, 'id' => (int)$f['id'], 'registro_id' => $f['registro_id'] === null ? null : (int)$f['registro_id']], $filas));

    case 'sistema':
      exigirPermiso($esquema, $usuario, 'ver', 'sistema');
      responder(diagnostico($config, $esquema));

    case 'modulos':
      exigirPermiso($esquema, $usuario, 'ver', 'modulos');
      $activos = $ajustes['modulos'];
      if ($metodo === 'POST') {
        $activo = leerCuerpo()['activo'] ?? null;
        if (!is_bool($activo)) fallar('Indica {"activo": true} o {"activo": false}', 400);
        $activos = cambiarModulo($pdo, $catalogo, $activos, (string)($_GET['modulo'] ?? ''), $activo);
      }
      responder(listarModulos($catalogo, $activos, $esquema));

    case 'ajustes':
      exigirPermiso($esquema, $usuario, 'ver', 'ajustes');
      if ($metodo === 'POST') {
        $cuerpo = leerCuerpo();
        $guardados = cambiarAjustes($pdo, $ajustes, $cuerpo);
        if (isset($cuerpo['entorno'])) cambiarEntorno($pdo, $config, (string)$cuerpo['entorno']);
        responder($guardados);
      }
      responder($ajustes + ['entorno' => $config['instalacion']['entorno']]);

    case 'claves':
      exigirPermiso($esquema, $usuario, 'ver', 'api');
      if ($metodo === 'POST') responder(crearClave($pdo, $esquema, leerCuerpo()), 201);
      if ($metodo === 'DELETE') { exigirId($id); revocarClave($pdo, $id); responder(['id' => $id]); }
      responder(listarClaves($pdo));

    case 'informe':
      exigirPermiso($esquema, $usuario, 'ver', 'sistema');
      responder(['markdown' => informeInstalacion($pdo, $config, $esquema)]);

    case 'reinstalar':
      exigirPermiso($esquema, $usuario, 'ver', 'sistema');
      $resultado = reinstalar($pdo, $config, $esquema, $usuario, leerCuerpo());
      salir();
      responder($resultado);

    case 'actualizar':
      exigirPermiso($esquema, $usuario, 'ver', 'sistema');
      fallar('No hay actualizaciones pendientes.', 409);

    case 'facturar':
    case 'rectificar':
      exigirPermiso($esquema, $usuario, $recurso === 'facturar' ? 'crear' : 'editar', 'facturas');
      exigirId($id);
      $pdo->beginTransaction();
      try {
        $factura = $recurso === 'facturar'
          ? facturar($pdo, $config, $id)
          : rectificar($pdo, $config, $id, (string)(leerCuerpo()['motivo'] ?? ''));
        $pdo->commit();
      } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
      }
      responder($factura, 201);

    case 'copia':
      exigirPermiso($esquema, $usuario, 'ver', 'sistema');
      exportarCopia($pdo, $config, $esquema);

    case 'restaurar':
      exigirPermiso($esquema, $usuario, 'ver', 'sistema');
      responder(restaurarCopia($pdo, $esquema, leerCuerpo()));

    case 'mi-clave':
      cambiarMiClave($pdo, $usuario, leerCuerpo());
      responder(null);

    default:
      if (!isset($esquema[$recurso])) fallar("Recurso desconocido: $recurso", 404);
      crud($pdo, $config, $esquema, $usuario, $recurso, $metodo, $id);
  }
} catch (ErrorApi $e) {
  http_response_code($e->getCode());
  echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'errores' => (object)$e->errores], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  error_log('Blush ERP: ' . $e->getMessage());
  // PDOException también es RuntimeException, pero su mensaje puede revelar SQL interno:
  // solo se muestra en el entorno de desarrollo.
  $propio = $e instanceof RuntimeException && !$e instanceof PDOException;
  $desarrollo = ($config['instalacion']['entorno'] ?? null) === 'desarrollo';
  $mensaje = $propio || $desarrollo ? $e->getMessage() : 'Error interno del servidor. Consulta el log de Apache.';
  if ($desarrollo) $mensaje .= ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $mensaje, 'errores' => new stdClass()], JSON_UNESCAPED_UNICODE);
}
