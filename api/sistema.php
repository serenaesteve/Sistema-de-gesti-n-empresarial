<?php
// Diagnóstico en directo del sistema operativo, PHP y base de datos (criterio f del RA1).
// Cada comprobación devuelve: grupo, nombre, valor, estado (ok | aviso | error) y una nota.

function entorno(array $config): array {
  return [
    'so'       => nombreSo(),
    'servidor' => $_SERVER['SERVER_SOFTWARE'] ?? php_sapi_name(),
    'php'      => PHP_VERSION,
    'bd'       => $config['motor'] === 'mysql' ? 'MySQL' : 'SQLite',
  ];
}

function nombreSo(): string {
  if (is_readable('/etc/os-release')) {
    $info = @parse_ini_file('/etc/os-release');
    if (!empty($info['PRETTY_NAME'])) return $info['PRETTY_NAME'];
  }
  return php_uname('s') . ' ' . php_uname('r');
}

// "128M" -> 134217728. -1 significa sin límite.
function aBytes(string $valor): int {
  $valor = trim($valor);
  if ($valor === '' || $valor === '-1') return -1;
  $numero = (int)$valor;
  return match (strtoupper(substr($valor, -1))) {
    'G' => $numero * 1024 ** 3,
    'M' => $numero * 1024 ** 2,
    'K' => $numero * 1024,
    default => $numero,
  };
}

function diagnostico(array $config, array $esquema): array {
  $lista = [];
  $anotar = function (string $grupo, string $nombre, string $valor, string $estado, string $nota = '') use (&$lista) {
    $lista[] = compact('grupo', 'nombre', 'valor', 'estado', 'nota');
  };

  // --- Sistema operativo ---------------------------------------------------
  $anotar('Sistema operativo', 'Distribución', nombreSo(),
    PHP_OS_FAMILY === 'Linux' ? 'ok' : 'aviso',
    PHP_OS_FAMILY === 'Linux' ? 'Linux es el sistema recomendado para un ERP web (pila LAMP).' : 'Funciona, pero en producción se recomienda Linux.');
  $anotar('Sistema operativo', 'Kernel', php_uname('r') . ' · ' . php_uname('m'), 'ok');

  $libre = @disk_free_space(__DIR__);
  if ($libre !== false) {
    $gb = round($libre / 1024 ** 3, 1);
    $anotar('Sistema operativo', 'Espacio libre en disco', "$gb GB", $gb >= 1 ? 'ok' : 'error', 'Mínimo recomendado: 1 GB.');
  }

  // --- Servidor web y PHP --------------------------------------------------
  $anotar('Servidor web y PHP', 'Servidor web', $_SERVER['SERVER_SOFTWARE'] ?? php_sapi_name(), 'ok');
  $anotar('Servidor web y PHP', 'Versión de PHP', PHP_VERSION,
    version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'error', 'Se necesita PHP 8.1 o superior.');

  $extensiones = [
    'pdo' => true, 'json' => true, 'mbstring' => true,
    'pdo_sqlite' => $config['motor'] === 'sqlite', 'pdo_mysql' => $config['motor'] === 'mysql',
    'curl' => false, 'xml' => false, 'zip' => false, 'gd' => false, 'intl' => false,
  ];
  foreach ($extensiones as $ext => $imprescindible) {
    $cargada = extension_loaded($ext);
    $anotar('Servidor web y PHP', "Extensión $ext", $cargada ? 'cargada' : 'no instalada',
      $cargada ? 'ok' : ($imprescindible ? 'error' : 'aviso'),
      $cargada ? '' : ($imprescindible ? 'Imprescindible para esta aplicación.' : "Recomendada para ERP completos. Instalar con: sudo apt install php-$ext"));
  }

  $limites = [
    'memory_limit'        => [256, 'Recomendado ≥ 256M para informes y PDF.'],
    'upload_max_filesize' => [20,  'Recomendado ≥ 20M para adjuntar documentos.'],
    'post_max_size'       => [20,  'Recomendado ≥ 20M (debe ser ≥ upload_max_filesize).'],
  ];
  foreach ($limites as $directiva => [$minimoMb, $nota]) {
    $valor = ini_get($directiva);
    $bytes = aBytes((string)$valor);
    $anotar('Servidor web y PHP', $directiva, $bytes === -1 ? 'sin límite' : $valor,
      $bytes === -1 || $bytes >= $minimoMb * 1024 ** 2 ? 'ok' : 'aviso', $nota);
  }

  $tiempo = (int)ini_get('max_execution_time');
  $anotar('Servidor web y PHP', 'max_execution_time', $tiempo === 0 ? 'sin límite' : "$tiempo s",
    $tiempo === 0 || $tiempo >= 60 ? 'ok' : 'aviso', 'Recomendado ≥ 60 s para importaciones e informes.');

  // Desde PHP 8.2, si php.ini no la define, ini_get() devuelve "UTC".
  $zona = ini_get('date.timezone');
  $definida = $zona && $zona !== 'UTC';
  $anotar('Servidor web y PHP', 'date.timezone (php.ini)', $zona ?: 'sin definir', $definida ? 'ok' : 'aviso',
    $definida ? '' : 'php.ini no define la zona horaria. La aplicación usa ' . $config['zona_horaria'] . ' por su cuenta, pero conviene definir date.timezone = Europe/Madrid.');

  $errores = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN);
  $anotar('Servidor web y PHP', 'display_errors', $errores ? 'activado' : 'desactivado', $errores ? 'aviso' : 'ok',
    $errores ? 'En producción debe estar desactivado: muestra rutas y detalles internos.' : '');

  $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
  $anotar('Servidor web y PHP', 'HTTPS', $https ? 'activo' : 'no', $https ? 'ok' : 'aviso',
    $https ? '' : 'Aceptable en local; en producción el ERP debe servirse por HTTPS.');

  // --- Base de datos -------------------------------------------------------
  try {
    $pdo = conectar($config, $esquema);
    $mysql = esMysql($pdo);
    $version = $pdo->query($mysql ? 'SELECT VERSION()' : 'SELECT sqlite_version()')->fetchColumn();
    $anotar('Base de datos', 'Motor', ($mysql ? 'MySQL ' : 'SQLite ') . $version, 'ok',
      $mysql ? '' : 'SQLite es suficiente para un ERP pequeño; para muchos usuarios a la vez conviene MySQL/MariaDB.');
    $anotar('Base de datos', 'Conexión', 'correcta', 'ok');

    if ($mysql) {
      $juego = $pdo->query('SELECT @@character_set_database')->fetchColumn();
      $anotar('Base de datos', 'Juego de caracteres', $juego, $juego === 'utf8mb4' ? 'ok' : 'aviso', 'Debe ser utf8mb4 para tildes, ñ y emojis.');
    } else {
      $ruta = realpath($config['sqlite']) ?: $config['sqlite'];
      $anotar('Base de datos', 'Archivo', basename($ruta) . ' · ' . round(filesize($ruta) / 1024) . ' KB', 'ok');
      $anotar('Base de datos', 'Permiso de escritura', is_writable($ruta) ? 'sí' : 'no', is_writable($ruta) ? 'ok' : 'error',
        'El usuario de Apache (www-data) debe poder escribir en datos/.');
      $fk = (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn();
      $anotar('Base de datos', 'Claves foráneas', $fk ? 'activadas' : 'desactivadas', $fk ? 'ok' : 'error',
        'Evitan pedidos de clientes o productos que no existen.');
    }

    foreach (array_keys(tablas($esquema)) as $tabla) {
      $filas = (int)$pdo->query("SELECT COUNT(*) FROM $tabla")->fetchColumn();
      $anotar('Base de datos', "Tabla $tabla", "$filas filas", 'ok');
    }
  } catch (Throwable $e) {
    $anotar('Base de datos', 'Conexión', 'fallida', 'error', $e->getMessage());
  }

  $resumen = ['ok' => 0, 'aviso' => 0, 'error' => 0];
  foreach ($lista as $c) $resumen[$c['estado']]++;

  return ['resumen' => $resumen, 'comprobaciones' => $lista, 'fecha' => date('c')];
}
