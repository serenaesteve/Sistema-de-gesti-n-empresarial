<?php
// Conexión a la base de datos, creación y migración automática de tablas y datos de demostración.

const VERSION_ESQUEMA = 3;

function conectar(array $config, array $esquema): PDO {
  if ($config['motor'] === 'mysql') {
    $m = $config['mysql'];
    $pdo = new PDO("mysql:host={$m['host']};dbname={$m['bd']};charset=utf8mb4", $m['usuario'], $m['clave']);
    // Las fechas TIMESTAMP se leen en UTC, igual que en SQLite (el frontend las pasa a hora local).
    $pdo->exec("SET time_zone = '+00:00'");
  } else {
    $ruta = $config['sqlite'];
    if (!is_writable(dirname($ruta))) {
      throw new RuntimeException('La carpeta datos/ no tiene permiso de escritura para el usuario de Apache (www-data).');
    }
    $pdo = abrirSqlite($ruta);
    // Bases de datos de la v2 (un producto por pedido): se guardan como copia y se crea una nueva.
    if (columnas($pdo, 'pedidos') && in_array('producto_id', columnas($pdo, 'pedidos'), true)) {
      $pdo = null;
      rename($ruta, preg_replace('/\.sqlite$/', '', $ruta) . '-v2-' . date('Ymd-His') . '.sqlite');
      $pdo = abrirSqlite($ruta);
    }
  }

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

  if (esMysql($pdo) && in_array('producto_id', columnas($pdo, 'pedidos'), true)) {
    throw new RuntimeException('La base de datos MySQL es de una versión anterior (v2). Vacíala o crea una nueva con scripts/mysql.sql.');
  }

  crearTablas($pdo, $esquema);
  sembrar($pdo, $config, $esquema);
  return $pdo;
}

function abrirSqlite(string $ruta): PDO {
  $pdo = new PDO('sqlite:' . $ruta);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec('PRAGMA foreign_keys = ON');
  return $pdo;
}

function esMysql(PDO $pdo): bool {
  return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

// Columnas de una tabla (vacío si la tabla no existe)
function columnas(PDO $pdo, string $tabla): array {
  if (esMysql($pdo)) {
    $existe = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $existe->execute([$tabla]);
    return $existe->fetchColumn() ? array_column($pdo->query("SHOW COLUMNS FROM $tabla")->fetchAll(), 'Field') : [];
  }
  return array_column($pdo->query("PRAGMA table_info($tabla)")->fetchAll(), 'name');
}

// Todas las tablas en orden de creación (primero las principales, luego las líneas y el registro).
// Cada tabla: ['campos' => [...], 'padre' => [clave, tabla] | null]
function tablas(array $esquema): array {
  $tablas = [];
  foreach ($esquema as $tabla => $modulo) $tablas[$tabla] = ['campos' => $modulo['campos'], 'padre' => null];
  foreach ($esquema as $tabla => $modulo) {
    if (empty($modulo['lineas'])) continue;
    $lineas = $modulo['lineas'];
    $tablas[$lineas['tabla']] = ['campos' => $lineas['campos'], 'padre' => [$lineas['clave'], $tabla]];
  }
  $tablas['registro'] = ['campos' => [
    'accion'      => ['tipo' => 'texto', 'obligatorio' => true],
    'recurso'     => ['tipo' => 'texto', 'obligatorio' => true],
    'registro_id' => ['tipo' => 'entero'],
    'detalle'     => ['tipo' => 'texto'],
    'usuario'     => ['tipo' => 'texto'],
  ], 'padre' => null, 'fecha' => 'fecha'];
  return $tablas;
}

// Genera el CREATE TABLE de cada tabla a partir del esquema (válido en SQLite y MySQL).
// Si la tabla ya existe pero le faltan columnas, las añade.
function crearTablas(PDO $pdo, array $esquema): void {
  $mysql = esMysql($pdo);
  $id    = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
  $fin   = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
  $ahora = ($mysql ? 'TIMESTAMP' : 'DATETIME') . ' DEFAULT CURRENT_TIMESTAMP';

  $tipos = [
    'texto' => 'VARCHAR(255)', 'email' => 'VARCHAR(255)', 'tel' => 'VARCHAR(40)', 'clave' => 'VARCHAR(255)',
    'opcion' => 'VARCHAR(40)', 'moneda' => 'DECIMAL(10,2)', 'entero' => 'INTEGER', 'fecha' => 'DATE', 'relacion' => 'INTEGER',
  ];

  foreach (tablas($esquema) as $tabla => $info) {
    $definiciones = ["id $id"];
    $restricciones = [];
    $columnas = [];

    if ($info['padre']) {
      [$clave, $padre] = $info['padre'];
      $columnas[$clave] = 'INTEGER';
      $definiciones[] = "$clave INTEGER NOT NULL";
      // Al borrar un pedido se borran sus líneas
      $restricciones[] = "FOREIGN KEY ($clave) REFERENCES $padre(id) ON DELETE CASCADE";
    }
    foreach ($info['campos'] as $campo => $def) {
      $columnas[$campo] = $tipos[$def['tipo']];
      $definiciones[] = "$campo {$tipos[$def['tipo']]}" . (!empty($def['obligatorio']) ? ' NOT NULL' : '');
      if (!empty($def['unico'])) $restricciones[] = "UNIQUE ($campo)";
      if ($def['tipo'] === 'relacion') $restricciones[] = "FOREIGN KEY ($campo) REFERENCES {$def['tabla']}(id)";
    }
    $definiciones[] = ($info['fecha'] ?? 'creado') . " $ahora";
    $pdo->exec("CREATE TABLE IF NOT EXISTS $tabla (" . implode(', ', array_merge($definiciones, $restricciones)) . ")$fin");

    // Migración: columnas nuevas en tablas ya existentes (se añaden sin NOT NULL)
    $existentes = columnas($pdo, $tabla);
    foreach ($columnas as $campo => $tipo) {
      if (!in_array($campo, $existentes, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $campo $tipo");
    }
  }
}

// Guarda cada operación en la tabla registro (criterio g: documentar las operaciones).
function registrar(PDO $pdo, string $accion, string $recurso, ?int $id, string $detalle): void {
  $usuario = usuarioActual()['nombre'] ?? 'sistema';
  $pdo->prepare('INSERT INTO registro (accion, recurso, registro_id, detalle, usuario) VALUES (?, ?, ?, ?, ?)')
      ->execute([$accion, $recurso, $id, mb_substr($detalle, 0, 255), $usuario]);
}

// Datos de demostración: solo se insertan con la base de datos vacía.
function sembrar(PDO $pdo, array $config, array $esquema): void {
  if ((int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0) {
    $consulta = $pdo->prepare('INSERT INTO usuarios (nombre, email, clave, rol) VALUES (?, ?, ?, ?)');
    $consulta->execute(['Serena Esteve', 'serena@blush.test', password_hash('blush2026', PASSWORD_DEFAULT), 'admin']);
    $consulta->execute(['Pablo Gil', 'pablo@blush.test', password_hash('blush2026', PASSWORD_DEFAULT), 'comercial']);
  }

  if ((int)$pdo->query('SELECT COUNT(*) FROM registro')->fetchColumn() > 0) return;

  $pdo->beginTransaction();

  $clientes = [
    ['Lucía Martín',  'Estudio Lumen',      'B46111222', 'lucia@estudiolumen.es',   '612 345 678', 'C/ Colón 4',                   'Valencia',  'activo'],
    ['Marcos Ferrer', 'Ferrer & Hijos',     'B03222333', 'marcos@ferrerhijos.com',  '623 456 789', 'Av. Maisonnave 18',            'Alicante',  'activo'],
    ['Aitana Soler',  'Cerámica Soler',     'B12333444', 'aitana@ceramicasoler.es', '634 567 890', 'C/ Enmedio 7',                 'Castellón', 'potencial'],
    ['Hugo Navarro',  'Navarro Logística',  'B46444555', 'hugo@navarrolog.es',      '645 678 901', 'Polígono Fuente del Jarro, 3', 'Valencia',  'activo'],
    ['Carla Ibáñez',  'Floristería Azahar', 'B46555666', 'carla@azahar.es',         '656 789 012', 'Paseo Germanías 22',           'Gandía',    'potencial'],
    ['Daniel Ortega', 'Ortega Consultores', 'B28666777', 'daniel@ortegacons.com',   '667 890 123', 'C/ Serrano 41',                'Madrid',    'inactivo'],
    ['Noa Vidal',     'Café Turia',         'B46777888', 'noa@cafeturia.es',        '678 901 234', 'C/ Turia 15',                  'Valencia',  'activo'],
  ];
  $consulta = $pdo->prepare('INSERT INTO clientes (nombre, empresa, nif, email, telefono, direccion, ciudad, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
  foreach ($clientes as $c) $consulta->execute($c);

  // Mismos productos que la versión de clase, para poder comparar.
  // El stock ya es el que queda después de las ventas y compras de demostración.
  $productos = [
    ['Portátil ProBook 15',    'Informática',    749.99, 12],
    ['Monitor UltraView 27',   'Informática',    229.90, 25],
    ['Teclado Mecánico K500',  'Periféricos',     79.95, 40],
    ['Ratón Inalámbrico M200', 'Periféricos',     34.50, 65],
    ['Disco SSD 1TB',          'Almacenamiento',  89.99, 30],
    ['Memoria USB 128GB',      'Almacenamiento',  19.90, 120],
    ['Webcam Full HD',         'Periféricos',     54.99, 8],
    ['Auriculares Studio X',   'Audio',           69.50, 35],
    ['Altavoces SoundBox',     'Audio',           49.95, 22],
    ['Hub USB-C 8 en 1',       'Accesorios',      44.90, 5],
  ];
  $consulta = $pdo->prepare('INSERT INTO productos (nombre, categoria, precio, stock) VALUES (?, ?, ?, ?)');
  foreach ($productos as $p) $consulta->execute($p);

  $proveedores = [
    ['TecnoDistribución S.A.', 'A46100200', 'pedidos@tecnodist.es',  '961 234 567', 'Valencia'],
    ['Periféricos Levante',    'B46300400', 'ventas@perilevante.es', '963 456 789', 'Paterna'],
    ['AudioPro Ibérica',       'B08500600', 'comercial@audiopro.es', '934 567 890', 'Barcelona'],
  ];
  $consulta = $pdo->prepare('INSERT INTO proveedores (nombre, nif, email, telefono, ciudad) VALUES (?, ?, ?, ?, ?)');
  foreach ($proveedores as $p) $consulta->execute($p);

  // Seis meses de pedidos. [cliente, hace N días, [[producto, cantidad], …]]
  $pedidos = [
    [1, 170, [[1, 1], [4, 1]]], [2, 162, [[3, 4]]],          [4, 150, [[2, 2], [10, 2]]], [7, 141, [[6, 20]]],
    [1, 133, [[8, 2]]],         [2, 120, [[1, 2], [2, 2]]],  [4, 112, [[4, 10]]],         [7, 101, [[9, 2], [8, 1]]],
    [6, 95,  [[5, 3]]],         [1, 84,  [[2, 1]]],          [2, 76,  [[7, 2], [4, 2]]],  [4, 66,  [[1, 1]]],
    [3, 58,  [[3, 2]]],         [7, 49,  [[8, 3]]],          [1, 41,  [[5, 4], [6, 10]]], [2, 33,  [[2, 3]]],
    [4, 26,  [[10, 6]]],        [1, 20,  [[1, 2]]],          [2, 15,  [[3, 5], [4, 5]]],  [4, 11,  [[2, 3]]],
    [7, 8,   [[8, 4]]],         [1, 6,   [[5, 6]]],          [2, 4,   [[10, 10]]],        [4, 3,   [[4, 12], [3, 2]]],
    [3, 2,   [[7, 2]]],         [7, 1,   [[6, 25]]],         [5, 0,   [[9, 3], [6, 5]]],
  ];
  $estado = fn(int $dias, int $i) => match (true) {
    $i === 24 => 'cancelado',
    $dias >= 10 => 'entregado',
    $dias >= 5 => 'enviado',
    default => 'pendiente',
  };
  $pedido = $pdo->prepare('INSERT INTO pedidos (cliente_id, fecha, estado) VALUES (?, ?, ?)');
  $linea = $pdo->prepare('INSERT INTO pedido_lineas (pedido_id, producto_id, cantidad, precio) VALUES (?, ?, ?, ?)');
  foreach ($pedidos as $i => [$cliente, $dias, $lineas]) {
    $pedido->execute([$cliente, date('Y-m-d', strtotime("-$dias days")), $estado($dias, $i)]);
    $id = (int)$pdo->lastInsertId();
    foreach ($lineas as [$producto, $cantidad]) $linea->execute([$id, $producto, $cantidad, $productos[$producto - 1][2]]);
  }

  // Compras a proveedores [proveedor, hace N días, estado, [[producto, cantidad, coste], …]]
  $compras = [
    [1, 160, 'recibida',  [[1, 10, 560.00], [2, 15, 165.00]]],
    [2, 90,  'recibida',  [[3, 30, 52.00], [4, 50, 21.00], [7, 10, 36.00]]],
    [3, 45,  'recibida',  [[8, 20, 44.00], [9, 15, 31.00]]],
    [1, 2,   'pedida',    [[10, 20, 28.50], [7, 15, 36.00]]],
  ];
  $compra = $pdo->prepare('INSERT INTO compras (proveedor_id, fecha, estado) VALUES (?, ?, ?)');
  $linea = $pdo->prepare('INSERT INTO compra_lineas (compra_id, producto_id, cantidad, coste) VALUES (?, ?, ?, ?)');
  foreach ($compras as [$proveedor, $dias, $estadoCompra, $lineas]) {
    $compra->execute([$proveedor, date('Y-m-d', strtotime("-$dias days")), $estadoCompra]);
    $id = (int)$pdo->lastInsertId();
    foreach ($lineas as $l) $linea->execute([$id, ...$l]);
  }

  // Los pedidos entregados de hace más de un mes ya están facturados
  $entregados = $pdo->query("SELECT id, fecha FROM pedidos WHERE estado = 'entregado' AND fecha < '" . date('Y-m-d', strtotime('-30 days')) . "' ORDER BY fecha, id")->fetchAll();
  $primera = null;
  foreach ($entregados as $p) {
    $factura = facturar($pdo, $config, (int)$p['id'], $p['fecha'], false);
    $primera ??= $factura;
  }
  // Ejemplo de factura rectificativa: devolución de la primera venta
  if ($primera) {
    rectificar($pdo, $config, $primera['id'], 'Devolución de la mercancía por el cliente', date('Y-m-d', strtotime('-160 days')), false);
    $pdo->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ?")->execute([$entregados[0]['id']]);
  }

  registrar($pdo, 'instalar', 'sistema', null, 'Base de datos creada con datos de demostración');
  $pdo->commit();
}
