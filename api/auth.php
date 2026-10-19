<?php
// Inicio de sesión, usuario actual y permisos por rol.
//
// Roles:
//   admin      puede hacerlo todo
//   comercial  lo que indique su campo «permisos» (por módulo) o, si está vacío,
//              'permisos' => ['comercial' => [...]] de cada módulo del esquema
//   api        otro programa que entra con una clave (X-API-Key): solo los recursos de la clave,
//              lectura y, si la clave lo permite, alta y edición; nunca borrar ni administración
//
// Vistas especiales: el panel, el registro, los informes, el análisis y las consultas (sin consola SQL)
// los ven todos, cada uno solo con los datos que puede ver; el resto, solo admin.
// Además, un recurso solo está disponible si su módulo está activo (ver api/instalacion.php).

const VISTAS_COMERCIAL = ['panel', 'registro', 'informes', 'analisis', 'consultas'];
const VISTAS = ['panel', 'registro', 'sistema', 'ajustes', 'modulos', 'api',
  'informes', 'analisis', 'consultas', 'rendimiento', 'intercambio', 'formularios', 'tareas'];

$GLOBALS['usuario_actual'] = null;

function iniciarSesion(): void {
  session_name('blush_sesion');
  session_set_cookie_params([
    'httponly' => true,       // JavaScript no puede leer la cookie
    'samesite' => 'Strict',   // no se envía desde otras webs (protección CSRF)
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  ]);
  session_start();
}

function usuarioActual(): ?array {
  return $GLOBALS['usuario_actual'];
}

// Carga el usuario de la sesión desde la base de datos en cada petición:
// si lo borran o le cambian el rol, el cambio se aplica al momento.
function cargarUsuario(PDO $pdo): ?array {
  if (empty($_SESSION['usuario_id'])) return null;
  // SELECT *: antes de actualizar a la v4.1 la columna «permisos» aún no existe
  $consulta = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
  $consulta->execute([$_SESSION['usuario_id']]);
  $fila = $consulta->fetch() ?: null;
  $usuario = $fila ? [
    'id' => (int)$fila['id'], 'nombre' => $fila['nombre'], 'email' => $fila['email'], 'rol' => $fila['rol'],
    'permisos' => isset($fila['permisos']) ? json_decode((string)$fila['permisos'], true) : null,
  ] : null;
  if (!$usuario) unset($_SESSION['usuario_id']);
  return $GLOBALS['usuario_actual'] = $usuario;
}

function exigirSesion(PDO $pdo): array {
  return cargarUsuario($pdo) ?? fallar('Tu sesión ha caducado. Vuelve a iniciar sesión.', 401);
}

function moduloActivo(string $recurso): bool {
  $modulo = $GLOBALS['recurso_modulo'][$recurso] ?? null;
  return $modulo === null || in_array($modulo, $GLOBALS['modulos_activos'] ?? [], true);
}

function puede(array $esquema, array $usuario, string $accion, string $recurso): bool {
  if (!moduloActivo($recurso)) return false;
  if ($usuario['rol'] === 'admin') return isset($esquema[$recurso]) || in_array($recurso, VISTAS, true);
  if ($usuario['rol'] === 'api') {
    return isset($esquema[$recurso]) && in_array($recurso, $usuario['recursos'], true)
      && ($accion === 'ver' || ($usuario['escritura'] && in_array($accion, ['crear', 'editar'], true)));
  }
  if (isset($esquema[$recurso])) {
    // Permisos propios del usuario, por módulo (los recursos del núcleo son solo de administración)
    if (is_array($usuario['permisos'] ?? null)) {
      $modulo = $GLOBALS['recurso_modulo'][$recurso] ?? null;
      return $modulo !== 'nucleo' && in_array($accion, $usuario['permisos'][$modulo] ?? [], true);
    }
    return in_array($accion, $esquema[$recurso]['permisos'][$usuario['rol']] ?? [], true);
  }
  return $accion === 'ver' && in_array($recurso, VISTAS_COMERCIAL, true);
}

function exigirPermiso(array $esquema, array $usuario, string $accion, string $recurso): void {
  if (!moduloActivo($recurso)) {
    $modulo = $GLOBALS['catalogo'][$GLOBALS['recurso_modulo'][$recurso]]['nombre'] ?? $recurso;
    fallar("El módulo $modulo no está activo. Actívalo en Administración → Módulos.", 403);
  }
  if (!puede($esquema, $usuario, $accion, $recurso)) {
    fallar('Tu rol (' . $usuario['rol'] . ') no tiene permiso para esta acción.', 403);
  }
}

// Mapa recurso => acciones permitidas, para que el frontend oculte lo que no se puede hacer.
function permisosDe(array $esquema, array $usuario): array {
  $permisos = [];
  foreach (array_merge(array_keys($esquema), VISTAS) as $recurso) {
    $permisos[$recurso] = array_values(array_filter(['ver', 'crear', 'editar', 'borrar'],
      fn($accion) => puede($esquema, $usuario, $accion, $recurso)));
  }
  return $permisos;
}

function entrar(PDO $pdo, array $cuerpo): array {
  $email = trim((string)($cuerpo['email'] ?? ''));
  $clave = (string)($cuerpo['clave'] ?? '');

  $consulta = $pdo->prepare('SELECT id, nombre, clave FROM usuarios WHERE email = ?');
  $consulta->execute([$email]);
  $usuario = $consulta->fetch();

  if (!$usuario || !password_verify($clave, $usuario['clave'])) {
    usleep(600000); // frena los intentos por fuerza bruta
    fallar('Email o contraseña incorrectos', 401, ['clave' => 'Email o contraseña incorrectos']);
  }

  session_regenerate_id(true); // evita la fijación de sesión
  $_SESSION['usuario_id'] = (int)$usuario['id'];
  cargarUsuario($pdo);
  registrar($pdo, 'entrar', 'sesion', (int)$usuario['id'], "Inicio de sesión de {$usuario['nombre']}");
  return usuarioActual();
}

function salir(): void {
  $_SESSION = [];
  session_destroy();
  setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
}
