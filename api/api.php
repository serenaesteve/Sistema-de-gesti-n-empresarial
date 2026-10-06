<?php
// Punto de entrada único de la API.
//
//   GET    api.php?recurso=sesion                  Usuario conectado y sus permisos (o null)
//   POST   api.php?recurso=entrar                  Iniciar sesión {email, clave}
//   POST   api.php?recurso=salir                   Cerrar sesión
//   GET    api.php?recurso=menu | esquema | info   Datos para montar la interfaz
//   GET    api.php?recurso=panel                   Indicadores del panel
//   GET    api.php?recurso=registro                Últimas operaciones realizadas
//   GET    api.php?recurso=sistema                 Diagnóstico de SO, PHP y base de datos (admin)
//   POST   api.php?recurso=facturar&id=5           Crea la factura del pedido 5
//   POST   api.php?recurso=rectificar&id=7         Factura rectificativa de la factura 7 {motivo} (admin)
//   GET    api.php?recurso=copia                   Descarga una copia de seguridad en JSON (admin)
//   POST   api.php?recurso=restaurar               Restaura una copia de seguridad (admin)
//   POST   api.php?recurso=mi-clave                Cambia la propia contraseña {actual, nueva}
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
require __DIR__ . '/negocio.php';
require __DIR__ . '/crud.php';
require __DIR__ . '/panel.php';
require __DIR__ . '/sistema.php';
require __DIR__ . '/copia.php';

// Los errores previstos se lanzan como excepción: así una transacción abierta se deshace
// antes de responder (ver crud()).
final class ErrorApi extends Exception {
  public function __construct(string $mensaje, int $codigo, public readonly array $errores = []) {
    parent::__construct($mensaje, $codigo);
  }
}

function responder(mixed $datos, int $codigo = 200): never {
  http_response_code($codigo);
  echo json_encode(['ok' => true, 'datos' => $datos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function fallar(string $mensaje, int $codigo, array $errores = []): never {
  throw new ErrorApi($mensaje, $codigo, $errores);
}

$recurso = $_GET['recurso'] ?? '';
$metodo  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id']) ? (int)$_GET['id'] : null;

try {
  // Protección CSRF extra: las escrituras deben llevar la cabecera que pone nucleo/js/api.js.
  // Un formulario de otra web no puede añadir cabeceras propias.
  if ($metodo !== 'GET' && ($_SERVER['HTTP_X_BLUSH'] ?? '') !== '1') fallar('Petición no permitida', 403);

  iniciarSesion();
  $pdo = conectar($config, $esquema);

  // Rutas públicas (sin sesión)
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

  $acciones = ['facturar', 'rectificar', 'restaurar', 'mi-clave'];
  if ($metodo !== 'GET' && !isset($esquema[$recurso]) && !in_array($recurso, $acciones, true)) fallar('Método no permitido', 405);
  if (in_array($recurso, $acciones, true) && $metodo !== 'POST') fallar('Método no permitido', 405);

  switch ($recurso) {
    case 'menu':
      $menu = [['id' => 'panel', 'titulo' => 'Panel', 'icono' => 'panel', 'grupo' => null]];
      foreach ($esquema as $clave => $modulo) {
        $menu[] = ['id' => $clave, 'titulo' => $modulo['titulo'], 'icono' => $modulo['icono'], 'grupo' => $modulo['grupo']];
      }
      $menu[] = ['id' => 'registro', 'titulo' => 'Registro', 'icono' => 'registro', 'grupo' => 'Administración'];
      $menu[] = ['id' => 'sistema',  'titulo' => 'Sistema',  'icono' => 'sistema',  'grupo' => 'Administración'];
      // Agrupado por sección para el menú lateral
      usort($menu, fn($a, $b) => array_search($a['grupo'], [null, 'Ventas', 'Compras', 'Administración']) <=> array_search($b['grupo'], [null, 'Ventas', 'Compras', 'Administración']));
      responder(array_values(array_filter($menu, fn($m) => puede($esquema, $usuario, 'ver', $m['id']))));

    case 'esquema':
      responder($esquema);

    case 'info':
      responder(['app' => $config['app'], 'empresa' => $config['empresa'], 'entorno' => entorno($config)]);

    case 'panel':
      responder(panel($pdo, $esquema));

    case 'registro':
      exigirPermiso($esquema, $usuario, 'ver', 'registro');
      $filas = $pdo->query('SELECT * FROM registro ORDER BY id DESC LIMIT 200')->fetchAll();
      responder(array_map(fn($f) => [...$f, 'id' => (int)$f['id'], 'registro_id' => $f['registro_id'] === null ? null : (int)$f['registro_id']], $filas));

    case 'sistema':
      exigirPermiso($esquema, $usuario, 'ver', 'sistema');
      responder(diagnostico($config, $esquema));

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
  // PDOException también es RuntimeException, pero su mensaje puede revelar SQL interno.
  $propio = $e instanceof RuntimeException && !$e instanceof PDOException;
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $propio ? $e->getMessage() : 'Error interno del servidor. Consulta el log de Apache.', 'errores' => new stdClass()], JSON_UNESCAPED_UNICODE);
}
