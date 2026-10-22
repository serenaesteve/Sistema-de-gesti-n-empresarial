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
//   Organización y consulta de la información (RA3):
//   GET    api.php?recurso=informes                Informes disponibles
//   GET    api.php?recurso=informes&informe=ventas_periodo&desde=&hasta=&agrupacion=mes[&cliente=&producto=&categoria=&estado=]
//          [&comparar=anterior|ano_anterior][&formato=csv|xlsx|json]
//   GET    api.php?recurso=analisis                Tendencia, Pareto, clientes en riesgo, cobertura, márgenes…
//   GET    api.php?recurso=consultas               Consultas guardadas y campos del constructor
//   GET    api.php?recurso=consultas&definicion={…} | &sql=SELECT… (admin) | &guardada=3   [&formato=csv|json]
//   POST   api.php?recurso=consultas · DELETE consultas&id=3   Guardar o borrar una consulta
//   GET    api.php?recurso=intercambio[&plantilla=clientes]     Qué se puede importar · plantilla CSV
//   POST   api.php?recurso=intercambio&accion=analizar|importar&destino=clientes {contenido, formato, nombre, modo}
//   POST   api.php?recurso=intercambio&accion=deshacer&id=4 · accion=fusionar {recurso, conservar, eliminar}
//   GET    api.php?recurso=intercambio&duplicados=clientes                Grupos de registros repetidos
//   GET    api.php?recurso=formularios · POST · PUT &id= · DELETE &id=   Formularios web (admin)
//   GET    api.php?recurso=formulario-publico&f=slug · POST (sin sesión)  Formulario público
//   GET    api.php?recurso=tareas[&descarga=7] · POST · PUT &id= · DELETE &id= · POST &accion=ejecutar&id=
//   GET    api.php?recurso=rendimiento&horas=24 · POST &accion=muestra|medir   Rendimiento (admin)
//   GET    api.php?recurso=rendimiento&explicar=5                           Plan de una consulta lenta (admin)
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
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
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
require __DIR__ . '/informes.php';
require __DIR__ . '/consultas.php';
require __DIR__ . '/intercambio.php';
require __DIR__ . '/formularios.php';
require __DIR__ . '/tareas.php';
require __DIR__ . '/rendimiento.php';
require __DIR__ . '/analisis.php';
require __DIR__ . '/nucleo.php';

$recurso = $_GET['recurso'] ?? '';
$metodo  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id']) ? (int)$_GET['id'] : null;

$claveApi = $_SERVER['HTTP_X_API_KEY'] ?? null;
$inicioPeticion = hrtime(true);

try {
  // Protección CSRF extra: las escrituras con sesión deben llevar la cabecera que pone nucleo/js/api.js.
  // Un formulario de otra web no puede añadir cabeceras propias. Con clave de API no hay cookies.
  // El formulario público es la excepción: no usa sesión ni cookies, así que no hay nada que falsificar.
  if ($metodo !== 'GET' && $claveApi === null && $recurso !== 'formulario-publico' && ($_SERVER['HTTP_X_BLUSH'] ?? '') !== '1') fallar('Petición no permitida', 403);

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
  $GLOBALS['iva_general'] = (int)$config['empresa']['iva'];
  $GLOBALS['config_erp'] = $config;

  // Tiempo de cada petición, para la pantalla de Rendimiento (RA3, criterio f)
  register_shutdown_function(fn() => anotarPeticion($pdo, $recurso, $metodo, $inicioPeticion));

  // ---------- Formularios web públicos (sin sesión) ----------

  if ($recurso === 'formulario-publico') {
    $formulario = formularioPublico($pdo, $esquema, (string)($_GET['f'] ?? ''));
    if ($metodo === 'POST') responder(enviarFormulario($pdo, $config, $esquema, $formulario, leerCuerpo()), 201);
    responder(definicionPublica($config, $esquema, $formulario));
  }

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

  $acciones = ['facturar', 'rectificar', 'restaurar', 'mi-clave', 'modulos', 'ajustes', 'claves', 'reinstalar', 'actualizar',
    'consultas', 'intercambio', 'formularios', 'tareas', 'rendimiento'];
  if ($metodo !== 'GET' && !isset($esquema[$recurso]) && !in_array($recurso, $acciones, true)) fallar('Método no permitido', 405);
  if (in_array($recurso, ['facturar', 'rectificar', 'restaurar', 'mi-clave', 'reinstalar'], true) && $metodo !== 'POST') fallar('Método no permitido', 405);

  switch ($recurso) {
    case 'menu':
      $menu = [['id' => 'panel', 'titulo' => 'Panel', 'icono' => 'panel', 'grupo' => null]];
      foreach ($esquema as $clave => $modulo) {
        $menu[] = ['id' => $clave, 'titulo' => $modulo['titulo'], 'icono' => $modulo['icono'], 'grupo' => $modulo['grupo']];
      }
      $menu[] = ['id' => 'informes',    'titulo' => 'Informes',    'icono' => 'informes',    'grupo' => 'Análisis'];
      $menu[] = ['id' => 'analisis',    'titulo' => 'Análisis',    'icono' => 'analisis',    'grupo' => 'Análisis'];
      $menu[] = ['id' => 'consultas',   'titulo' => 'Consultas',   'icono' => 'consultas',   'grupo' => 'Análisis'];
      $menu[] = ['id' => 'rendimiento', 'titulo' => 'Rendimiento', 'icono' => 'rendimiento', 'grupo' => 'Análisis'];
      $menu[] = ['id' => 'intercambio', 'titulo' => 'Importar y exportar', 'icono' => 'intercambio', 'grupo' => 'Datos'];
      $menu[] = ['id' => 'formularios', 'titulo' => 'Formularios web', 'icono' => 'formularios', 'grupo' => 'Datos'];
      $menu[] = ['id' => 'tareas',      'titulo' => 'Tareas programadas', 'icono' => 'tareas', 'grupo' => 'Datos'];
      $menu[] = ['id' => 'registro', 'titulo' => 'Registro', 'icono' => 'registro', 'grupo' => 'Administración'];
      $menu[] = ['id' => 'modulos',  'titulo' => 'Módulos',  'icono' => 'modulos',  'grupo' => 'Administración'];
      $menu[] = ['id' => 'ajustes',  'titulo' => 'Ajustes',  'icono' => 'ajustes',  'grupo' => 'Administración'];
      $menu[] = ['id' => 'api',      'titulo' => 'Acceso API', 'icono' => 'llave',  'grupo' => 'Administración'];
      $menu[] = ['id' => 'sistema',  'titulo' => 'Sistema',  'icono' => 'sistema',  'grupo' => 'Administración'];
      // Agrupado por sección; solo lo que el usuario puede ver y cuyo módulo está activo
      $grupos = [null, 'Ventas', 'Compras', 'Análisis', 'Datos', 'Administración'];
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
      responder(panel($pdo, $esquema) + ['modulos' => $ajustes['modulos'], 'alertas' => alertas($pdo, $esquema, $usuario)]);

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

    // ---------- Organización y consulta de la información (RA3) ----------

    case 'informes':
      exigirPermiso($esquema, $usuario, 'ver', 'informes');
      if (!isset($_GET['informe'])) responder(['informes' => informesDisponibles($esquema, $usuario), 'agrupaciones' => AGRUPACIONES,
        'comparaciones' => COMPARACIONES, 'opciones' => opcionesFiltros($pdo, $esquema, $usuario)]);
      $resultado = ejecutarInforme($pdo, $esquema, $usuario, (string)$_GET['informe'], $_GET);
      if (in_array($_GET['formato'] ?? '', ['csv', 'json', 'xlsx'], true)) {
        registrar($pdo, 'exportar', 'informes', null, "Informe «{$resultado['titulo']}» exportado en " . strtoupper($_GET['formato']));
        exportarResultado($resultado, $_GET['formato'], $resultado['id']);
      }
      responder($resultado);

    case 'analisis':
      exigirPermiso($esquema, $usuario, 'ver', 'analisis');
      responder(analisis($pdo, $esquema, $usuario));

    case 'consultas':
      exigirPermiso($esquema, $usuario, 'ver', 'consultas');
      if ($metodo === 'POST') responder(guardarConsulta($pdo, $usuario, leerCuerpo()), 201);
      if ($metodo === 'DELETE') { exigirId($id); borrarConsulta($pdo, $id); responder(['id' => $id]); }
      if ($metodo !== 'GET') fallar('Método no permitido', 405);
      $definicion = null;
      $sql = isset($_GET['sql']) ? (string)$_GET['sql'] : null;
      $nombre = 'consulta';
      if (isset($_GET['guardada'])) {
        $guardada = array_values(array_filter(listarConsultas($pdo, $usuario), fn($c) => $c['id'] === (int)$_GET['guardada']))[0] ?? fallar('Consulta no encontrada', 404);
        $nombre = $guardada['nombre'];
        if ($guardada['tipo'] === 'sql') $sql = (string)($guardada['definicion']['sql'] ?? '');
        else $definicion = $guardada['definicion'];
      } elseif (isset($_GET['definicion'])) {
        $definicion = json_decode((string)$_GET['definicion'], true);
        if (!is_array($definicion)) fallar('Definición de consulta no válida', 400);
      }
      if ($sql !== null) {
        if ($usuario['rol'] !== 'admin') fallar('La consola SQL es solo para administración.', 403);
        $resultado = ejecutarSql($config, $esquema, $sql, $pdo, !empty($_GET['explicar']));
      } elseif ($definicion !== null) {
        $resultado = ejecutarConstructor($pdo, $esquema, $usuario, $definicion, !empty($_GET['explicar']));
      } else {
        responder(['guardadas' => listarConsultas($pdo, $usuario), 'recursos' => recursosConsultables($esquema, $usuario),
          'operadores' => array_keys(OPERADORES), 'agregaciones' => array_keys(AGREGACIONES), 'sql' => $usuario['rol'] === 'admin', 'limite' => LIMITE_FILAS]);
      }
      if (in_array($_GET['formato'] ?? '', ['csv', 'json', 'xlsx'], true)) {
        registrar($pdo, 'exportar', 'consultas', null, "Consulta «" . mb_substr($nombre, 0, 80) . "» exportada en " . strtoupper($_GET['formato']) . ' (' . count($resultado['filas']) . ' filas)');
        exportarResultado($resultado, $_GET['formato'], $nombre);
      }
      responder($resultado);

    case 'intercambio':
      exigirPermiso($esquema, $usuario, 'ver', 'intercambio');
      if (isset($_GET['plantilla'])) plantillaImportacion($pdo, $esquema, $usuario, (string)$_GET['plantilla']);
      if (isset($_GET['duplicados'])) {
        exigirPermiso($esquema, $usuario, 'ver', (string)$_GET['duplicados']);
        responder(gruposDuplicados($pdo, $esquema, (string)$_GET['duplicados']));
      }
      if ($metodo === 'POST') {
        $destino = (string)($_GET['destino'] ?? '');
        $accion = (string)($_GET['accion'] ?? '');
        if ($accion === 'importar') responder(importar($pdo, $esquema, $usuario, $destino, leerCuerpo()), 201);
        if ($accion === 'deshacer') { exigirId($id); responder(deshacerImportacion($pdo, $esquema, $usuario, $id)); }
        if ($accion === 'fusionar') responder(fusionar($pdo, $esquema, $usuario, leerCuerpo()));
        responder(analizarImportacion($pdo, $esquema, $usuario, $destino, leerCuerpo()));
      }
      if ($metodo !== 'GET') fallar('Método no permitido', 405);
      responder([
        'importables' => recursosImportables($esquema, $usuario),
        'exportables' => array_values(array_map(fn($r) => ['id' => $r, 'titulo' => $esquema[$r]['titulo']],
          array_filter(array_keys($esquema), fn($r) => puede($esquema, $usuario, 'ver', $r)))),
        'maximo' => MAX_FILAS_IMPORTACION, 'modos' => MODOS_IMPORTACION,
        'historial' => historialImportaciones($pdo, $esquema),
      ]);

    case 'formularios':
      exigirPermiso($esquema, $usuario, 'ver', 'formularios');
      if ($metodo === 'POST') responder(guardarFormulario($pdo, $esquema, leerCuerpo(), null), 201);
      if ($metodo === 'PUT') { exigirId($id); responder(guardarFormulario($pdo, $esquema, leerCuerpo(), $id)); }
      if ($metodo === 'DELETE') { exigirId($id); borrarFormulario($pdo, $id); responder(['id' => $id]); }
      responder(listarFormularios($pdo, $esquema));

    case 'tareas':
      exigirPermiso($esquema, $usuario, 'ver', 'tareas');
      if (isset($_GET['descarga'])) descargarExtraccion($pdo, (int)$_GET['descarga']);
      if ($metodo === 'POST' && ($_GET['accion'] ?? '') === 'ejecutar') { exigirId($id); responder(ejecutarTareaPorId($pdo, $esquema, $id)); }
      if ($metodo === 'POST') responder(guardarTarea($pdo, $esquema, $usuario, leerCuerpo(), null), 201);
      if ($metodo === 'PUT') { exigirId($id); responder(guardarTarea($pdo, $esquema, $usuario, leerCuerpo(), $id)); }
      if ($metodo === 'DELETE') { exigirId($id); borrarTarea($pdo, $id); responder(['id' => $id]); }
      responder(listarTareas($pdo, $esquema, $usuario));

    case 'rendimiento':
      exigirPermiso($esquema, $usuario, 'ver', 'rendimiento');
      if ($metodo === 'POST' && ($_GET['accion'] ?? '') === 'medir') responder(medirRendimiento($pdo, $config, $esquema, $usuario));
      if ($metodo === 'POST') responder(tomarMuestra($pdo, $config));
      if (isset($_GET['explicar'])) responder(explicarLenta($pdo, $config, $esquema, (int)$_GET['explicar']));
      responder(datosRendimiento($pdo, $config, $esquema, (int)($_GET['horas'] ?? 24)));

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
