<?php
// Inicio de sesión, usuario actual y permisos por rol.
//
// Roles:
//   admin      puede hacerlo todo
//   comercial  lo que indique 'permisos' => ['comercial' => [...]] en cada módulo del esquema
//
// Vistas especiales: el panel y el registro los ven todos; el diagnóstico del sistema, solo admin.

const VISTAS_COMERCIAL = ['panel', 'registro'];

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
  $consulta = $pdo->prepare('SELECT id, nombre, email, rol FROM usuarios WHERE id = ?');
  $consulta->execute([$_SESSION['usuario_id']]);
  $usuario = $consulta->fetch() ?: null;
  if ($usuario) $usuario['id'] = (int)$usuario['id'];
  else unset($_SESSION['usuario_id']);
  return $GLOBALS['usuario_actual'] = $usuario;
}

function exigirSesion(PDO $pdo): array {
  return cargarUsuario($pdo) ?? fallar('Tu sesión ha caducado. Vuelve a iniciar sesión.', 401);
}

function puede(array $esquema, array $usuario, string $accion, string $recurso): bool {
  if ($usuario['rol'] === 'admin') return true;
  if (isset($esquema[$recurso])) return in_array($accion, $esquema[$recurso]['permisos'][$usuario['rol']] ?? [], true);
  return $accion === 'ver' && in_array($recurso, VISTAS_COMERCIAL, true);
}

function exigirPermiso(array $esquema, array $usuario, string $accion, string $recurso): void {
  if (!puede($esquema, $usuario, $accion, $recurso)) {
    fallar('Tu rol (' . $usuario['rol'] . ') no tiene permiso para esta acción.', 403);
  }
}

// Mapa recurso => acciones permitidas, para que el frontend oculte lo que no se puede hacer.
function permisosDe(array $esquema, array $usuario): array {
  $permisos = [];
  foreach (array_merge(array_keys($esquema), ['panel', 'registro', 'sistema']) as $recurso) {
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
