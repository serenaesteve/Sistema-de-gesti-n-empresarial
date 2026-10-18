<?php
// Instalación, módulos y ajustes (RA2).
//
// · Estado: el ERP está instalado si existe datos/instalacion.json (motor de BD, perfil, fecha…).
//   La ruta se puede cambiar con ERP_INSTALACION (pruebas automáticas, Docker).
// · Módulos: cada uno se describe en api/modulos/<id>.json (recursos, dependencias…).
//   Los activos se guardan en la tabla ajustes; desactivar un módulo no borra sus datos.
// · Perfiles: instalacion/perfiles/<id>.json, conjuntos de módulos para cada tipo de empresa.

const VERSION_APP = '4.1';
const ENTORNOS = [
  'desarrollo'  => 'Desarrollo: errores detallados y franja de aviso. Para programar.',
  'pruebas'     => 'Pruebas: datos de prueba y franja de aviso. Para validar antes de publicar.',
  'explotacion' => 'Explotación: uso real. Errores ocultos y comprobaciones de seguridad más estrictas.',
];
const ORDEN_MODULOS = ['nucleo', 'crm', 'inventario', 'ventas', 'facturacion', 'compras'];

// ---------- Estado de la instalación ----------

function rutaInstalacion(): string {
  return getenv('ERP_INSTALACION') ?: __DIR__ . '/../datos/instalacion.json';
}

function leerInstalacion(): ?array {
  $ruta = rutaInstalacion();
  if (!is_file($ruta)) return null;
  $datos = json_decode((string)file_get_contents($ruta), true);
  return is_array($datos) ? $datos : null;
}

// Aplica a la configuración el motor de BD elegido al instalar.
// Una base de datos de la v3 (anterior al instalador) se adopta como instalación «distribuidora».
function conInstalacion(array $config): array {
  $instalacion = leerInstalacion();
  if (!$instalacion && !getenv('ERP_INSTALACION') && $config['motor'] === 'sqlite' && is_file($config['sqlite'])) {
    $instalacion = ['version' => '3.0', 'fecha' => date('c'), 'perfil' => 'distribuidora', 'motor' => 'sqlite', 'entorno' => 'desarrollo', 'migrada_desde' => '3.0'];
    escribirInstalacion($instalacion);
  }
  if ($instalacion) $instalacion += ['entorno' => 'desarrollo']; // instalaciones de la v4.0, sin entorno
  $config['instalacion'] = $instalacion;
  if ($instalacion) {
    $config['motor'] = $instalacion['motor'];
    if (!empty($instalacion['sqlite'])) $config['sqlite'] = $instalacion['sqlite'];
    if (!empty($instalacion['mysql'])) $config['mysql'] = $instalacion['mysql'];
  }
  return $config;
}

function escribirInstalacion(array $datos): void {
  $ruta = rutaInstalacion();
  if (file_put_contents($ruta, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
    throw new RuntimeException('No se pudo escribir ' . basename($ruta) . ': revisa los permisos de la carpeta datos/.');
  }
  @chmod($ruta, 0644); // Apache (www-data) debe poder leerlo; datos/.htaccess impide descargarlo
}

// ---------- Catálogo de módulos y perfiles ----------

function catalogoModulos(): array {
  $modulos = [];
  foreach (glob(__DIR__ . '/modulos/*.json') as $archivo) {
    $modulo = json_decode((string)file_get_contents($archivo), true);
    if (is_array($modulo) && !empty($modulo['id'])) $modulos[$modulo['id']] = $modulo + ['dependencias' => [], 'vistas' => [], 'obligatorio' => false];
  }
  $posicion = fn($id) => ($p = array_search($id, ORDEN_MODULOS, true)) === false ? 99 : $p;
  uksort($modulos, fn($a, $b) => $posicion($a) <=> $posicion($b));
  return $modulos;
}

function perfiles(): array {
  $perfiles = [];
  foreach (glob(__DIR__ . '/../instalacion/perfiles/*.json') as $archivo) {
    $perfil = json_decode((string)file_get_contents($archivo), true);
    if (is_array($perfil) && !empty($perfil['id'])) $perfiles[$perfil['id']] = $perfil;
  }
  uasort($perfiles, fn($a, $b) => ($a['orden'] ?? 99) <=> ($b['orden'] ?? 99));
  return $perfiles;
}

// Añade las dependencias de cada módulo (y el núcleo, que siempre está activo).
function cerrarDependencias(array $ids, array $catalogo): array {
  $resultado = ['nucleo'];
  $pendientes = $ids;
  while ($pendientes) {
    $id = array_shift($pendientes);
    if (!isset($catalogo[$id])) fallar("Módulo desconocido: $id", 422, ['modulos' => "Módulo desconocido: $id"]);
    if (in_array($id, $resultado, true)) continue;
    $resultado[] = $id;
    array_push($pendientes, ...$catalogo[$id]['dependencias']);
  }
  return array_values(array_filter(ORDEN_MODULOS, fn($m) => in_array($m, $resultado, true)));
}

// recurso (tabla o vista) -> módulo al que pertenece
function mapaRecursos(array $catalogo): array {
  $mapa = [];
  foreach ($catalogo as $id => $modulo) {
    foreach (array_merge($modulo['recursos'], $modulo['vistas']) as $recurso) $mapa[$recurso] = $id;
  }
  return $mapa;
}

// ---------- Ajustes ----------

function ajustesPorDefecto(array $config, array $catalogo): array {
  return [
    'empresa'             => array_diff_key($config['empresa'], ['iva' => 0]),
    'iva'                 => (int)$config['empresa']['iva'],
    'serie_factura'       => 'F',
    'serie_rectificativa' => 'R',
    'stock_minimo'        => 15,
    'por_pagina'          => (int)$config['por_pagina'],
    'modulos'             => array_keys($catalogo), // instalaciones antiguas: todo activo
  ];
}

function leerAjustes(PDO $pdo, array $config, array $catalogo): array {
  $ajustes = ajustesPorDefecto($config, $catalogo);
  foreach ($pdo->query('SELECT clave, valor FROM ajustes')->fetchAll() as $fila) {
    if (array_key_exists($fila['clave'], $ajustes)) $ajustes[$fila['clave']] = json_decode($fila['valor'], true);
  }
  return $ajustes;
}

function guardarAjuste(PDO $pdo, string $clave, mixed $valor): void {
  $pdo->prepare('DELETE FROM ajustes WHERE clave = ?')->execute([$clave]);
  $pdo->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?)')->execute([$clave, json_encode($valor, JSON_UNESCAPED_UNICODE)]);
}

// Lleva los ajustes guardados a la configuración y al esquema de esta petición.
function aplicarAjustes(array $config, array &$esquema, array $ajustes): array {
  $config['empresa'] = $ajustes['empresa'] + ['iva' => $ajustes['iva']];
  $config['empresa']['iva'] = (int)$ajustes['iva'];
  $config['por_pagina'] = (int)$ajustes['por_pagina'];
  $config['series'] = ['factura' => $ajustes['serie_factura'], 'rectificativa' => $ajustes['serie_rectificativa']];
  $config['modulos_activos'] = $ajustes['modulos'];
  $esquema['productos']['campos']['stock']['alerta'] = (int)$ajustes['stock_minimo'];
  return $config;
}

// Valida y guarda los ajustes enviados desde la pantalla Ajustes.
function cambiarAjustes(PDO $pdo, array $actuales, array $entrada): array {
  $errores = [];
  $texto = fn($v) => is_scalar($v) ? trim((string)$v) : '';

  $empresa = [];
  foreach (['nombre', 'nif', 'direccion', 'email'] as $campo) {
    $empresa[$campo] = $texto($entrada['empresa'][$campo] ?? '');
    if (mb_strlen($empresa[$campo]) > 255) $errores["empresa.$campo"] = 'Máximo 255 caracteres';
  }
  if ($empresa['nombre'] === '') $errores['empresa.nombre'] = 'Este campo es obligatorio';
  if ($empresa['email'] !== '' && !filter_var($empresa['email'], FILTER_VALIDATE_EMAIL)) $errores['empresa.email'] = 'Email no válido';

  $iva = filter_var($entrada['iva'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
  if ($iva === false) $errores['iva'] = 'Entre 0 y 100';

  $series = [];
  foreach (['serie_factura', 'serie_rectificativa'] as $campo) {
    $series[$campo] = strtoupper($texto($entrada[$campo] ?? ''));
    if (!preg_match('/^[A-Z]{1,3}$/', $series[$campo])) $errores[$campo] = 'De 1 a 3 letras (F, FV, R…)';
  }
  if (!isset($errores['serie_factura']) && $series['serie_factura'] === $series['serie_rectificativa']) {
    $errores['serie_rectificativa'] = 'Debe ser distinta de la serie de facturas';
  }

  $stock = filter_var($entrada['stock_minimo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
  if ($stock === false) $errores['stock_minimo'] = 'Número entero mayor o igual que 0';

  $porPagina = (int)($entrada['por_pagina'] ?? 0);
  if (!in_array($porPagina, [10, 15, 25, 50], true)) $errores['por_pagina'] = 'Elige 10, 15, 25 o 50';

  if ($errores) fallar('Revisa los campos marcados', 422, $errores);

  $nuevos = ['empresa' => $empresa, 'iva' => $iva, 'stock_minimo' => $stock, 'por_pagina' => $porPagina] + $series;
  $cambiados = array_keys(array_filter($nuevos, fn($valor, $clave) => $valor != $actuales[$clave], ARRAY_FILTER_USE_BOTH));

  $pdo->beginTransaction();
  foreach ($nuevos as $clave => $valor) guardarAjuste($pdo, $clave, $valor);
  registrar($pdo, 'configurar', 'ajustes', null, 'Ajustes modificados: ' . ($cambiados ? implode(', ', $cambiados) : 'sin cambios'));
  $pdo->commit();
  return $nuevos + ['modulos' => $actuales['modulos']];
}

// ---------- Activar y desactivar módulos ----------

function listarModulos(array $catalogo, array $activos, array $esquema): array {
  return array_values(array_map(function ($modulo) use ($catalogo, $activos, $esquema) {
    $requeridoPor = array_values(array_map(fn($m) => $m['nombre'], array_filter($catalogo,
      fn($m) => in_array($m['id'], $activos, true) && in_array($modulo['id'], $m['dependencias'], true))));
    return [
      'id'            => $modulo['id'],
      'nombre'        => $modulo['nombre'],
      'icono'         => $modulo['icono'],
      'descripcion'   => $modulo['descripcion'],
      'version'       => $modulo['version'],
      'obligatorio'   => $modulo['obligatorio'],
      'activo'        => in_array($modulo['id'], $activos, true),
      'dependencias'  => array_map(fn($d) => $catalogo[$d]['nombre'], $modulo['dependencias']),
      'dependencias_ids' => $modulo['dependencias'],
      'requerido_por' => $requeridoPor,
      'tablas'        => array_map(fn($r) => $esquema[$r]['titulo'] ?? $r, $modulo['recursos']),
      'recursos'      => $modulo['recursos'],
    ];
  }, $catalogo));
}

function cambiarModulo(PDO $pdo, array $catalogo, array $activos, string $id, bool $activar): array {
  if (!isset($catalogo[$id])) fallar("Módulo desconocido: $id", 404);
  $modulo = $catalogo[$id];

  if ($activar) {
    $nuevos = cerrarDependencias(array_merge($activos, [$id]), $catalogo);
    $anadidos = array_diff($nuevos, $activos);
    $detalle = 'Módulo activado: ' . implode(', ', array_map(fn($m) => $catalogo[$m]['nombre'], $anadidos ?: [$id]));
  } else {
    if ($modulo['obligatorio']) fallar("El módulo {$modulo['nombre']} es obligatorio.", 409);
    $dependientes = array_filter($catalogo, fn($m) => in_array($m['id'], $activos, true) && in_array($id, $m['dependencias'], true));
    if ($dependientes) {
      fallar("No se puede desactivar {$modulo['nombre']}: lo necesita " . implode(' y ', array_column($dependientes, 'nombre')) . '. Desactívalo antes.', 409);
    }
    $nuevos = array_values(array_diff($activos, [$id]));
    $detalle = "Módulo desactivado: {$modulo['nombre']} (sus datos se conservan)";
  }

  $pdo->beginTransaction();
  guardarAjuste($pdo, 'modulos', $nuevos);
  registrar($pdo, 'configurar', 'modulos', null, $detalle);
  $pdo->commit();
  return $nuevos;
}

// ---------- Requisitos y conexión (antes de instalar) ----------

function requisitos(array $config): array {
  $datos = dirname($config['sqlite']);
  $lista = [
    ['nombre' => 'PHP 8.1 o superior',          'valor' => PHP_VERSION, 'ok' => version_compare(PHP_VERSION, '8.1', '>='), 'obligatorio' => true],
    ['nombre' => 'Extensión PDO',               'valor' => extension_loaded('pdo') ? 'cargada' : 'no instalada', 'ok' => extension_loaded('pdo'), 'obligatorio' => true],
    ['nombre' => 'Driver SQLite (pdo_sqlite)',  'valor' => extension_loaded('pdo_sqlite') ? 'cargado' : 'no instalado', 'ok' => extension_loaded('pdo_sqlite'), 'obligatorio' => false],
    ['nombre' => 'Driver MySQL (pdo_mysql)',    'valor' => extension_loaded('pdo_mysql') ? 'cargado' : 'no instalado', 'ok' => extension_loaded('pdo_mysql'), 'obligatorio' => false],
    ['nombre' => 'Extensión mbstring',          'valor' => extension_loaded('mbstring') ? 'cargada' : 'no instalada', 'ok' => extension_loaded('mbstring'), 'obligatorio' => true],
    ['nombre' => 'Carpeta datos/ con escritura', 'valor' => is_writable($datos) ? 'sí' : 'no', 'ok' => is_writable($datos), 'obligatorio' => true],
  ];
  $hayDriver = extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql');
  return [
    'ok' => $hayDriver && !array_filter($lista, fn($r) => $r['obligatorio'] && !$r['ok']),
    'comprobaciones' => $lista,
    // Docker define estos valores por variables de entorno; la contraseña nunca se envía
    'mysql_por_defecto' => array_diff_key($config['mysql'], ['clave' => '']),
    'entornos' => ENTORNOS,
    'motor_por_defecto' => $config['motor'],
  ];
}

function datosMysql(array $entrada): array {
  $m = is_array($entrada['mysql'] ?? null) ? $entrada['mysql'] : [];
  return [
    'host'    => trim((string)($m['host'] ?? 'localhost')) ?: 'localhost',
    'bd'      => trim((string)($m['bd'] ?? '')),
    'usuario' => trim((string)($m['usuario'] ?? '')),
    'clave'   => (string)($m['clave'] ?? ''),
  ];
}

function probarMysql(array $mysql): array {
  if (!extension_loaded('pdo_mysql')) fallar('Falta el driver pdo_mysql', 422, ['mysql' => 'PHP no tiene el driver pdo_mysql instalado']);
  try {
    $pdo = new PDO("mysql:host={$mysql['host']};dbname={$mysql['bd']};charset=utf8mb4", $mysql['usuario'], $mysql['clave'], [PDO::ATTR_TIMEOUT => 5]);
    return ['version' => $pdo->query('SELECT VERSION()')->fetchColumn()];
  } catch (PDOException $e) {
    // Antes de instalar sí se muestra el error real: es lo que necesita quien instala
    fallar('No se pudo conectar a MySQL', 422, ['mysql' => $e->getMessage()]);
  }
}

// ---------- Instalación ----------

// Instala el ERP: valida los datos, crea las tablas, los ajustes, el administrador
// y (opcional) los datos de demostración, y por último escribe instalacion.json.
function instalar(array $config, array $esquema, array $entrada): array {
  if (leerInstalacion()) fallar('El ERP ya está instalado.', 409);

  $catalogo = catalogoModulos();
  $perfiles = perfiles();
  $errores = [];
  $texto = fn($v) => is_scalar($v) ? trim((string)$v) : '';

  // Perfil y módulos
  $perfil = $texto($entrada['perfil'] ?? '');
  if ($perfil === 'personalizado') {
    $elegidos = is_array($entrada['modulos'] ?? null) ? $entrada['modulos'] : [];
  } elseif (isset($perfiles[$perfil])) {
    $elegidos = $perfiles[$perfil]['modulos'];
  } else {
    $errores['perfil'] = 'Elige un perfil de instalación';
    $elegidos = [];
  }
  $modulos = cerrarDependencias(array_map('strval', $elegidos), $catalogo);

  // Base de datos
  $motor = $entrada['motor'] ?? 'sqlite';
  if (!in_array($motor, ['sqlite', 'mysql'], true)) $errores['motor'] = 'Elige SQLite o MySQL';
  if ($motor === 'sqlite' && !extension_loaded('pdo_sqlite')) $errores['motor'] = 'PHP no tiene el driver pdo_sqlite';
  $mysql = datosMysql($entrada);
  if ($motor === 'mysql') {
    foreach (['host', 'bd', 'usuario'] as $campo) if ($mysql[$campo] === '') $errores["mysql.$campo"] = 'Este campo es obligatorio';
  }

  $entorno = $entrada['entorno'] ?? 'explotacion';
  if (!isset(ENTORNOS[$entorno])) $errores['entorno'] = 'Elige desarrollo, pruebas o explotación';

  // Empresa y administrador
  $empresa = ['nombre' => $texto($entrada['empresa']['nombre'] ?? ''), 'nif' => $texto($entrada['empresa']['nif'] ?? ''),
              'direccion' => $texto($entrada['empresa']['direccion'] ?? ''), 'email' => $texto($entrada['empresa']['email'] ?? '')];
  if ($empresa['nombre'] === '') $errores['empresa.nombre'] = 'Este campo es obligatorio';
  if ($empresa['email'] !== '' && !filter_var($empresa['email'], FILTER_VALIDATE_EMAIL)) $errores['empresa.email'] = 'Email no válido';

  $admin = ['nombre' => $texto($entrada['admin']['nombre'] ?? ''), 'email' => $texto($entrada['admin']['email'] ?? ''), 'clave' => (string)($entrada['admin']['clave'] ?? '')];
  if ($admin['nombre'] === '') $errores['admin.nombre'] = 'Este campo es obligatorio';
  if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) $errores['admin.email'] = 'Email no válido';
  if (mb_strlen($admin['clave']) < 8) $errores['admin.clave'] = 'Mínimo 8 caracteres';

  if ($errores) fallar('Revisa los campos marcados', 422, $errores);

  if ($motor === 'mysql') probarMysql($mysql);

  // Configuración con la que se va a instalar
  $config['motor'] = $motor;
  if ($motor === 'mysql') $config['mysql'] = $mysql;
  $instalacion = ['version' => VERSION_APP, 'fecha' => date('c'), 'perfil' => $perfil, 'motor' => $motor, 'entorno' => $entorno]
    + ($motor === 'mysql' ? ['mysql' => $mysql] : ['sqlite' => $config['sqlite']]);

  $sqliteNueva = $motor === 'sqlite' && !is_file($config['sqlite']);
  if ($motor === 'sqlite' && !$sqliteNueva) fallar('Ya existe una base de datos SQLite en ' . basename($config['sqlite']) . '. Bórrala o restaura una copia.', 409);

  try {
    $pdo = conectar($config, $esquema);
    if (columnas($pdo, 'usuarios')) {
      fallar('La base de datos MySQL ya contiene datos de otra instalación. Usa una base de datos vacía.', 409);
    }
    // Tablas en su última versión; después se anotan las migraciones (las idempotentes no cambian nada)
    crearTablas($pdo, $esquema);
    aplicarMigraciones($pdo, $config, $esquema, false);

    $config['empresa'] = $empresa + ['iva' => $config['empresa']['iva']];
    $ajustes = ['empresa' => $empresa, 'modulos' => $modulos] + ajustesPorDefecto($config, $catalogo);
    $config = aplicarAjustes($config, $esquema, $ajustes);

    $pdo->beginTransaction();
    foreach ($ajustes as $clave => $valor) guardarAjuste($pdo, $clave, $valor);
    $pdo->prepare('INSERT INTO usuarios (nombre, email, clave, rol) VALUES (?, ?, ?, ?)')
        ->execute([$admin['nombre'], $admin['email'], password_hash($admin['clave'], PASSWORD_DEFAULT), 'admin']);
    if (!empty($entrada['demo'])) sembrarDemo($pdo, $config, $modulos);
    $nombres = implode(', ', array_map(fn($m) => $catalogo[$m]['nombre'], $modulos));
    registrar($pdo, 'instalar', 'sistema', null, "Instalación «" . ($perfiles[$perfil]['nombre'] ?? 'Personalizada') . "» con $motor · módulos: $nombres" . (!empty($entrada['demo']) ? ' · con datos de demostración' : ''));
    $pdo->commit();

    escribirInstalacion($instalacion);
    if ($motor === 'sqlite') @chmod($config['sqlite'], 0666); // por si se instala desde la terminal
    $config['instalacion'] = $instalacion;
    $informe = informeInstalacion($pdo, $config, $esquema);
  } catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $pdo = null;
    // Solo se borra lo que ha creado esta instalación: nunca una base de datos que ya existía
    if ($sqliteNueva && is_file($config['sqlite'])) @unlink($config['sqlite']);
    throw $e;
  }

  return ['perfil' => $perfil, 'motor' => $motor, 'entorno' => $entorno, 'modulos' => $modulos, 'informe' => $informe];
}

// ---------- Entorno (desarrollo, pruebas, explotación) ----------

function cambiarEntorno(PDO $pdo, array $config, string $entorno): void {
  if (!isset(ENTORNOS[$entorno])) fallar('Revisa los campos marcados', 422, ['entorno' => 'Elige desarrollo, pruebas o explotación']);
  $anterior = $config['instalacion']['entorno'] ?? '?';
  if ($anterior === $entorno) return;
  escribirInstalacion(['entorno' => $entorno] + $config['instalacion']);
  registrar($pdo, 'configurar', 'sistema', null, "Entorno: $anterior → $entorno");
}

// ---------- Informe de instalación (documentación, criterio f) ----------

function informeInstalacion(PDO $pdo, array $config, array $esquema): string {
  $i = $config['instalacion'];
  $catalogo = catalogoModulos();
  $activos = leerAjustes($pdo, $config, $catalogo)['modulos'];
  $perfil = perfiles()[$i['perfil']]['nombre'] ?? 'Personalizada';
  $celda = fn($t) => str_replace(['|', "\n"], ['\\|', ' '], (string)$t);

  $md = ["# Informe de instalación · Blush ERP", "",
    "Generado el " . date('d/m/Y H:i') . '.', "",
    "## Instalación", "",
    "| Dato | Valor |", "|---|---|",
    "| Versión | " . ($i['version'] ?? '?') . " |",
    "| Fecha de instalación | " . date('d/m/Y H:i', strtotime($i['fecha'])) . " |",
    "| Perfil | $perfil |",
    "| Entorno | " . ($i['entorno'] ?? '?') . " |",
    "| Base de datos | " . ($i['motor'] === 'mysql' ? "MySQL ({$i['mysql']['usuario']}@{$i['mysql']['host']}/{$i['mysql']['bd']})" : 'SQLite (' . basename($config['sqlite']) . ')') . " |",
    "| Empresa | " . $celda($config['empresa']['nombre'] ?? '') . " |", "",
    "## Módulos", "", "| Módulo | Estado | Necesita |", "|---|---|---|"];
  foreach ($catalogo as $id => $m) {
    $md[] = "| {$m['nombre']} | " . (in_array($id, $activos, true) ? 'Activo' : 'Inactivo') . ' | '
      . ($m['dependencias'] ? implode(', ', array_map(fn($d) => $catalogo[$d]['nombre'], $m['dependencias'])) : '—') . ' |';
  }
  $md = array_merge($md, ["", "## Migraciones aplicadas", "", "| Migración | Versión | Descripción |", "|---|---|---|"]);
  foreach (migracionesAplicadas($pdo) as $m) $md[] = "| {$m['id']} | {$m['version']} | {$m['descripcion']} |";

  $md = array_merge($md, ["", "## Requisitos del servidor", "", "| Comprobación | Valor | Resultado |", "|---|---|---|"]);
  foreach (requisitos($config)['comprobaciones'] as $r) $md[] = "| {$r['nombre']} | {$r['valor']} | " . ($r['ok'] ? 'Correcto' : ($r['obligatorio'] ? 'No cumple' : 'Opcional')) . ' |';

  $usuarios = $pdo->query('SELECT rol, COUNT(*) AS n FROM usuarios GROUP BY rol')->fetchAll();
  $md = array_merge($md, ["", "## Usuarios", ""], array_map(fn($u) => "- {$u['rol']}: {$u['n']}", $usuarios), ["",
    "## Siguientes pasos", "",
    "1. Revisar Administración → Sistema y corregir los avisos.",
    "2. Completar los datos de la empresa en Administración → Ajustes.",
    "3. Crear los usuarios y asignar sus permisos por módulo.",
    "4. Descargar una primera copia de seguridad.", ""]);
  return implode("\n", $md);
}

// ---------- Reinstalar ----------

// Vuelve al asistente de instalación. Antes guarda una copia: SQLite se mueve a datos/copias/;
// en MySQL se exporta a JSON y se borran las tablas. Exige la contraseña y el nombre de la empresa.
function reinstalar(PDO $pdo, array $config, array $esquema, array $usuario, array $entrada): array {
  $clave = $pdo->prepare('SELECT clave FROM usuarios WHERE id = ?');
  $clave->execute([$usuario['id']]);
  $errores = [];
  $hash = (string)$clave->fetchColumn();
  $clave->closeCursor(); // VACUUM INTO (la copia) no se puede hacer con consultas abiertas
  if (!password_verify((string)($entrada['clave'] ?? ''), $hash)) $errores['clave'] = 'La contraseña no es correcta';
  if (trim((string)($entrada['confirmacion'] ?? '')) !== $config['empresa']['nombre']) $errores['confirmacion'] = 'Escribe exactamente el nombre de la empresa';
  if ($errores) fallar('Revisa los campos marcados', 422, $errores);

  $copia = copiaEnArchivo($pdo, $config, $esquema, 'antes-de-reinstalar');
  if (esMysql($pdo)) {
    // Primero las tablas que dependen de otras
    $pdo->exec('DELETE FROM facturas WHERE rectifica_id IS NOT NULL');
    foreach (array_merge(array_reverse(array_keys(tablas($esquema))), ['migraciones']) as $tabla) $pdo->exec("DROP TABLE IF EXISTS $tabla");
  } else {
    $pdo = null; // se cierra la conexión antes de mover el archivo
    if (!@unlink($config['sqlite'])) throw new RuntimeException('No se pudo retirar la base de datos SQLite.');
  }
  @unlink(rutaInstalacion());
  error_log("Blush ERP: reinstalación pedida por {$usuario['email']}; copia en $copia");
  return ['copia' => basename($copia)];
}
