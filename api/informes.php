<?php
// Informes (RA3): consultas de resumen sobre los datos del ERP, por periodo y con totales.
// Cada informe declara su módulo, sus parámetros, sus filtros, sus columnas y la consulta que lo genera.
// Se pueden ver en pantalla, comparar con el periodo anterior o con el año pasado, imprimir (PDF),
// exportar (CSV, Excel o JSON) y programar (ver tareas.php).

const AGRUPACIONES = ['dia' => 'Día', 'semana' => 'Semana', 'mes' => 'Mes', 'ano' => 'Año'];
const COMPARACIONES = ['anterior' => 'Periodo anterior', 'ano_anterior' => 'Mismo periodo del año pasado'];
const LIMITE_347 = 3005.06;   // € al año por cliente o proveedor (modelo 347)

// Expresión SQL que agrupa una fecha por día, semana, mes o año (distinta en SQLite y MySQL)
function expresionPeriodo(PDO $pdo, string $columna, string $agrupacion): string {
  $mysql = esMysql($pdo);
  return match ($agrupacion) {
    'dia'    => $columna,
    'semana' => $mysql ? "DATE_FORMAT($columna, '%x-S%v')" : "strftime('%Y-S%W', $columna)",
    'ano'    => "SUBSTR($columna, 1, 4)",
    default  => "SUBSTR($columna, 1, 7)",
  };
}

// La misma clave de periodo que genera expresionPeriodo(), calculada en PHP (para rellenar huecos)
function clavePeriodo(PDO $pdo, int $momento, string $agrupacion): string {
  if ($agrupacion === 'semana') {
    if (esMysql($pdo)) return date('o-\\SW', $momento);   // semana ISO (%x-S%v)
    // %W de SQLite: semanas que empiezan en lunes; la 00 es la de antes del primer lunes del año
    $semana = intdiv((int)date('z', $momento) + 7 - ((int)date('w', $momento) + 6) % 7, 7);
    return date('Y', $momento) . '-S' . str_pad((string)$semana, 2, '0', STR_PAD_LEFT);
  }
  return date(['dia' => 'Y-m-d', 'ano' => 'Y'][$agrupacion] ?? 'Y-m', $momento);
}

// Condiciones de los filtros elegidos: [' AND …', valores]. $columnas: filtro => columna SQL.
function filtrosSql(array $p, array $columnas): array {
  $sql = '';
  $valores = [];
  foreach ($columnas as $filtro => $columna) {
    $valor = $p['filtros'][$filtro] ?? null;
    if ($valor === null || $valor === '') continue;
    $sql .= $filtro === 'categoria' ? " AND $columna IN (SELECT id FROM productos WHERE categoria = ?)" : " AND $columna = ?";
    $valores[] = $valor;
  }
  return [$sql, $valores];
}

// Catálogo de informes. 'consulta' recibe los parámetros ya validados y devuelve [sql, valores];
// 'generar' (informes fiscales) devuelve directamente las filas.
function catalogoInformes(): array {
  $importe = 'l.cantidad * l.precio';
  $noCancelado = "pe.estado <> 'cancelado'";
  $filtrosVentas = ['cliente' => 'pe.cliente_id', 'producto' => 'l.producto_id', 'categoria' => 'l.producto_id', 'estado' => 'pe.estado'];
  $filtrosCompras = ['proveedor' => 'co.proveedor_id', 'producto' => 'cl.producto_id', 'categoria' => 'cl.producto_id'];

  return [
    'ventas_periodo' => [
      'titulo' => 'Ventas por periodo', 'grupo' => 'Ventas', 'modulo' => 'ventas', 'recurso' => 'pedidos',
      'descripcion' => 'Pedidos, unidades e importe (sin IVA) agrupados por día, semana, mes o año.',
      'parametros' => ['periodo', 'agrupacion'], 'filtros' => array_keys($filtrosVentas), 'comparar' => 'importe',
      'columnas' => [
        ['periodo', 'Periodo', 'texto'], ['pedidos', 'Pedidos', 'entero', true], ['unidades', 'Unidades', 'entero', true],
        ['importe', 'Importe', 'moneda', true], ['ticket', 'Ticket medio', 'moneda'],
      ],
      'grafico' => ['etiqueta' => 'periodo', 'valor' => 'importe', 'tipo' => 'columnas'],
      'rellenar' => 'periodo',   // los periodos sin ventas también salen (con 0)
      'consulta' => function (PDO $pdo, array $p) use ($importe, $noCancelado, $filtrosVentas) {
        [$filtro, $valores] = filtrosSql($p, $filtrosVentas);
        $grupo = expresionPeriodo($pdo, 'pe.fecha', $p['agrupacion']);
        return ["SELECT $grupo AS periodo, COUNT(DISTINCT pe.id) AS pedidos, SUM(l.cantidad) AS unidades, SUM($importe) AS importe,
            SUM($importe) / COUNT(DISTINCT pe.id) AS ticket
          FROM pedidos pe JOIN pedido_lineas l ON l.pedido_id = pe.id
          WHERE $noCancelado AND pe.fecha BETWEEN ? AND ?$filtro
          GROUP BY $grupo ORDER BY periodo", [$p['desde'], $p['hasta'], ...$valores]];
      },
    ],

    'ventas_cliente' => [
      'titulo' => 'Ventas por cliente', 'grupo' => 'Ventas', 'modulo' => 'ventas', 'recurso' => 'pedidos',
      'descripcion' => 'Qué clientes compran más en el periodo y qué parte del total suponen.',
      'parametros' => ['periodo'], 'filtros' => ['producto', 'categoria', 'estado'], 'comparar' => 'importe',
      'columnas' => [
        ['cliente', 'Cliente', 'texto'], ['empresa', 'Empresa', 'texto'], ['pedidos', 'Pedidos', 'entero', true],
        ['importe', 'Importe', 'moneda', true], ['porcentaje', '% del total', 'porcentaje'], ['ultimo', 'Último pedido', 'fecha'],
      ],
      'grafico' => ['etiqueta' => 'cliente', 'valor' => 'importe', 'tipo' => 'barras', 'maximo' => 10],
      'porcentaje' => 'importe',
      'consulta' => function (PDO $pdo, array $p) use ($importe, $noCancelado, $filtrosVentas) {
        [$filtro, $valores] = filtrosSql($p, $filtrosVentas);
        return ["SELECT c.nombre AS cliente, c.empresa, COUNT(DISTINCT pe.id) AS pedidos, SUM($importe) AS importe, MAX(pe.fecha) AS ultimo
          FROM pedidos pe JOIN pedido_lineas l ON l.pedido_id = pe.id JOIN clientes c ON c.id = pe.cliente_id
          WHERE $noCancelado AND pe.fecha BETWEEN ? AND ?$filtro
          GROUP BY c.id, c.nombre, c.empresa ORDER BY importe DESC", [$p['desde'], $p['hasta'], ...$valores]];
      },
    ],

    'ventas_producto' => [
      'titulo' => 'Ventas por producto', 'grupo' => 'Ventas', 'modulo' => 'ventas', 'recurso' => 'pedidos',
      'descripcion' => 'Unidades vendidas, importe y precio medio de cada producto.',
      'parametros' => ['periodo'], 'filtros' => ['cliente', 'categoria', 'estado'], 'comparar' => 'importe',
      'columnas' => [
        ['producto', 'Producto', 'texto'], ['categoria', 'Categoría', 'texto'], ['unidades', 'Unidades', 'entero', true],
        ['importe', 'Importe', 'moneda', true], ['precio_medio', 'Precio medio', 'moneda'], ['porcentaje', '% del total', 'porcentaje'],
      ],
      'grafico' => ['etiqueta' => 'producto', 'valor' => 'importe', 'tipo' => 'barras', 'maximo' => 10],
      'porcentaje' => 'importe',
      'consulta' => function (PDO $pdo, array $p) use ($importe, $noCancelado, $filtrosVentas) {
        [$filtro, $valores] = filtrosSql($p, $filtrosVentas);
        return ["SELECT pr.nombre AS producto, pr.categoria, SUM(l.cantidad) AS unidades, SUM($importe) AS importe, SUM($importe) / SUM(l.cantidad) AS precio_medio
          FROM pedidos pe JOIN pedido_lineas l ON l.pedido_id = pe.id JOIN productos pr ON pr.id = l.producto_id
          WHERE $noCancelado AND pe.fecha BETWEEN ? AND ?$filtro
          GROUP BY pr.id, pr.nombre, pr.categoria ORDER BY importe DESC", [$p['desde'], $p['hasta'], ...$valores]];
      },
    ],

    'libro_facturas' => [
      'titulo' => 'Libro de facturas emitidas', 'grupo' => 'Fiscal', 'modulo' => 'facturacion', 'recurso' => 'facturas',
      'descripcion' => 'Todas las facturas y rectificativas del periodo con base, IVA y total: lo que pide la gestoría.',
      'parametros' => ['periodo'], 'filtros' => ['cliente'], 'periodo_defecto' => 'trimestre',
      'columnas' => [
        ['numero', 'Número', 'texto'], ['fecha', 'Fecha', 'fecha'], ['cliente', 'Cliente', 'texto'], ['nif', 'NIF', 'texto'],
        ['base', 'Base', 'moneda', true], ['iva', 'IVA %', 'entero'], ['cuota', 'Cuota IVA', 'moneda', true], ['total', 'Total', 'moneda', true],
      ],
      'consulta' => function (PDO $pdo, array $p) {
        // La factura guarda una copia del cliente; se filtra por el pedido del que sale
        [$filtro, $valores] = filtrosSql($p, ['cliente' => '(SELECT pe.cliente_id FROM pedidos pe WHERE pe.id = f.pedido_id)']);
        return ["SELECT numero, fecha, cliente, nif, base, iva, cuota, total FROM facturas f
          WHERE fecha BETWEEN ? AND ?$filtro ORDER BY numero", [$p['desde'], $p['hasta'], ...$valores]];
      },
    ],

    'iva_trimestral' => [
      'titulo' => 'IVA del trimestre', 'grupo' => 'Fiscal', 'modulo' => 'facturacion', 'recurso' => 'facturas',
      'descripcion' => 'IVA repercutido en las facturas emitidas y soportado en las compras recibidas, por tipo de IVA: el resumen para el modelo 303. Las compras no guardan su IVA: se estima con el tipo general de Ajustes.',
      'parametros' => ['periodo'], 'periodo_defecto' => 'trimestre',
      'columnas' => [
        ['concepto', 'Concepto', 'texto'], ['iva', 'IVA %', 'entero'], ['documentos', 'Documentos', 'entero'],
        ['base', 'Base imponible', 'moneda'], ['cuota', 'Cuota', 'moneda'],
      ],
      'generar' => function (PDO $pdo, array $p) {
        $filas = [];
        $consulta = $pdo->prepare("SELECT tipo, iva, COUNT(*) AS documentos, SUM(base) AS base, SUM(cuota) AS cuota FROM facturas
          WHERE fecha BETWEEN ? AND ? GROUP BY tipo, iva ORDER BY tipo, iva DESC");
        $consulta->execute([$p['desde'], $p['hasta']]);
        $repercutido = ['base' => 0, 'cuota' => 0, 'documentos' => 0];
        foreach ($consulta->fetchAll() as $f) {
          $filas[] = ['concepto' => $f['tipo'] === 'rectificativa' ? 'Facturas rectificativas' : 'Facturas emitidas', 'iva' => (int)$f['iva'],
            'documentos' => (int)$f['documentos'], 'base' => (float)$f['base'], 'cuota' => (float)$f['cuota']];
          foreach (['base', 'cuota', 'documentos'] as $c) $repercutido[$c] += (float)$f[$c];
        }
        $filas[] = ['concepto' => 'IVA repercutido (total)', 'iva' => null] + $repercutido;
        $soportado = ['base' => 0, 'cuota' => 0, 'documentos' => 0];
        if (moduloActivo('compras')) {
          $iva = (int)($p['iva_general'] ?? 21);
          $consulta = $pdo->prepare("SELECT COUNT(DISTINCT co.id) AS documentos, COALESCE(SUM(cl.cantidad * cl.coste), 0) AS base
            FROM compras co JOIN compra_lineas cl ON cl.compra_id = co.id WHERE co.estado = 'recibida' AND co.fecha BETWEEN ? AND ?");
          $consulta->execute([$p['desde'], $p['hasta']]);
          $c = $consulta->fetch();
          $soportado = ['base' => (float)$c['base'], 'cuota' => round((float)$c['base'] * $iva / 100, 2), 'documentos' => (int)$c['documentos']];
          $filas[] = ['concepto' => 'IVA soportado en compras recibidas (estimado)', 'iva' => $iva] + $soportado;
        }
        $resultado = round($repercutido['cuota'] - $soportado['cuota'], 2);
        $filas[] = ['concepto' => $resultado >= 0 ? 'Resultado: a ingresar' : 'Resultado: a compensar', 'iva' => null, 'documentos' => null, 'base' => null, 'cuota' => $resultado];
        return $filas;
      },
    ],

    'modelo_347' => [
      'titulo' => 'Operaciones con terceros (modelo 347)', 'grupo' => 'Fiscal', 'modulo' => 'facturacion', 'recurso' => 'facturas',
      'descripcion' => 'Clientes y proveedores con los que se han superado 3.005,06 € (IVA incluido) en el año, por trimestres: la base del modelo 347. Las compras se calculan con el IVA general de Ajustes.',
      'parametros' => ['periodo'], 'periodo_defecto' => 'ano',
      'columnas' => [
        ['clave', 'Clave', 'texto'], ['nif', 'NIF', 'texto'], ['nombre', 'Nombre', 'texto'],
        ['t1', '1T', 'moneda', true], ['t2', '2T', 'moneda', true], ['t3', '3T', 'moneda', true], ['t4', '4T', 'moneda', true], ['total', 'Total anual', 'moneda', true],
      ],
      'generar' => function (PDO $pdo, array $p) {
        $acumular = function (array $filas, string $clave) {
          $porTercero = [];
          foreach ($filas as $f) {
            $id = normalizarNif((string)$f['nif']) ?: mb_strtolower((string)$f['nombre']);
            $porTercero[$id] ??= ['clave' => $clave, 'nif' => $f['nif'], 'nombre' => $f['nombre'], 't1' => 0, 't2' => 0, 't3' => 0, 't4' => 0, 'total' => 0];
            $t = 't' . (int)ceil((int)substr($f['fecha'], 5, 2) / 3);
            $porTercero[$id][$t] = round($porTercero[$id][$t] + (float)$f['importe'], 2);
            $porTercero[$id]['total'] = round($porTercero[$id]['total'] + (float)$f['importe'], 2);
          }
          return array_values(array_filter($porTercero, fn($t) => abs($t['total']) > LIMITE_347));
        };
        $consulta = $pdo->prepare('SELECT nif, cliente AS nombre, fecha, total AS importe FROM facturas WHERE fecha BETWEEN ? AND ?');
        $consulta->execute([$p['desde'], $p['hasta']]);
        $filas = $acumular($consulta->fetchAll(), 'B · Ventas');
        if (moduloActivo('compras')) {
          $iva = (int)($p['iva_general'] ?? 21);
          $consulta = $pdo->prepare("SELECT pv.nif, pv.nombre, co.fecha, SUM(cl.cantidad * cl.coste) * (1 + $iva / 100.0) AS importe
            FROM compras co JOIN compra_lineas cl ON cl.compra_id = co.id JOIN proveedores pv ON pv.id = co.proveedor_id
            WHERE co.estado = 'recibida' AND co.fecha BETWEEN ? AND ? GROUP BY co.id, pv.nif, pv.nombre, co.fecha");
          $consulta->execute([$p['desde'], $p['hasta']]);
          $filas = array_merge($filas, $acumular($consulta->fetchAll(), 'A · Compras'));
        }
        usort($filas, fn($a, $b) => [$a['clave'], -$a['total']] <=> [$b['clave'], -$b['total']]);
        return $filas;
      },
    ],

    'pedidos_pendientes' => [
      'titulo' => 'Pedidos pendientes', 'grupo' => 'Ventas', 'modulo' => 'ventas', 'recurso' => 'pedidos',
      'descripcion' => 'Pedidos sin entregar, del más antiguo al más reciente, con los días que llevan esperando.',
      'parametros' => [], 'filtros' => ['cliente'],
      'columnas' => [
        ['pedido', 'Pedido', 'texto'], ['fecha', 'Fecha', 'fecha'], ['cliente', 'Cliente', 'texto'], ['estado', 'Estado', 'texto'],
        ['dias', 'Días esperando', 'entero'], ['importe', 'Importe', 'moneda', true],
      ],
      'consulta' => function (PDO $pdo, array $p) use ($importe) {
        [$filtro, $valores] = filtrosSql($p, ['cliente' => 'pe.cliente_id']);
        return ["SELECT pe.id AS pedido, pe.fecha, c.nombre AS cliente, pe.estado, SUM($importe) AS importe
          FROM pedidos pe JOIN pedido_lineas l ON l.pedido_id = pe.id JOIN clientes c ON c.id = pe.cliente_id
          WHERE pe.estado IN ('pendiente', 'enviado')$filtro
          GROUP BY pe.id, pe.fecha, c.nombre, pe.estado ORDER BY pe.fecha", $valores];
      },
      // «#12» se compone aquí y no en SQL: la concatenación es distinta en SQLite (||) y MySQL (CONCAT)
      'calcular' => fn(array $f) => ['pedido' => '#' . $f['pedido']] + $f + ['dias' => (int)((time() - strtotime($f['fecha'])) / 86400)],
    ],

    'clientes_inactivos' => [
      'titulo' => 'Clientes sin compras recientes', 'grupo' => 'Clientes', 'modulo' => 'ventas', 'recurso' => 'clientes',
      'descripcion' => 'Clientes que llevan más de N días sin hacer un pedido: candidatos a una llamada comercial.',
      'parametros' => ['dias'],
      'columnas' => [
        ['cliente', 'Cliente', 'texto'], ['empresa', 'Empresa', 'texto'], ['telefono', 'Teléfono', 'texto'],
        ['ultimo', 'Último pedido', 'fecha'], ['dias', 'Días sin comprar', 'entero'], ['historico', 'Compras históricas', 'moneda', true],
      ],
      'consulta' => fn(PDO $pdo, array $p) => ["SELECT c.nombre AS cliente, c.empresa, c.telefono, MAX(pe.fecha) AS ultimo,
          COALESCE(SUM(CASE WHEN $noCancelado THEN $importe END), 0) AS historico
        FROM clientes c LEFT JOIN pedidos pe ON pe.cliente_id = c.id LEFT JOIN pedido_lineas l ON l.pedido_id = pe.id
        WHERE c.estado <> 'inactivo'
        GROUP BY c.id, c.nombre, c.empresa, c.telefono
        HAVING MAX(pe.fecha) IS NULL OR MAX(pe.fecha) < ?
        ORDER BY ultimo", [date('Y-m-d', strtotime("-{$p['dias']} days"))]],
      'calcular' => fn(array $f) => $f + ['dias' => $f['ultimo'] ? (int)((time() - strtotime($f['ultimo'])) / 86400) : null],
    ],

    'inventario_valorado' => [
      'titulo' => 'Inventario valorado', 'grupo' => 'Inventario', 'modulo' => 'inventario', 'recurso' => 'productos',
      'descripcion' => 'Stock de cada producto valorado a precio de venta y, si hay compras, a coste medio.',
      'parametros' => [], 'filtros' => ['categoria'],
      'columnas' => [
        ['producto', 'Producto', 'texto'], ['categoria', 'Categoría', 'texto'], ['stock', 'Stock', 'entero', true],
        ['precio', 'Precio venta', 'moneda'], ['valor_venta', 'Valor a venta', 'moneda', true],
        ['coste_medio', 'Coste medio', 'moneda'], ['valor_coste', 'Valor a coste', 'moneda', true],
      ],
      'consulta' => function (PDO $pdo, array $p) {
        [$filtro, $valores] = filtrosSql($p, ['categoria' => 'pr.id']);
        $coste = moduloActivo('compras')
          ? "(SELECT SUM(cl.cantidad * cl.coste) / SUM(cl.cantidad) FROM compra_lineas cl JOIN compras co ON co.id = cl.compra_id
               WHERE cl.producto_id = pr.id AND co.estado = 'recibida')" : 'NULL';
        return ["SELECT pr.nombre AS producto, pr.categoria, pr.stock, pr.precio, pr.stock * pr.precio AS valor_venta, $coste AS coste_medio
          FROM productos pr WHERE 1 = 1$filtro ORDER BY pr.stock * pr.precio DESC", $valores];
      },
      'calcular' => fn(array $f) => $f + ['valor_coste' => $f['coste_medio'] !== null ? round($f['stock'] * $f['coste_medio'], 2) : null],
    ],

    'compras_proveedor' => [
      'titulo' => 'Compras por proveedor', 'grupo' => 'Compras', 'modulo' => 'compras', 'recurso' => 'compras',
      'descripcion' => 'Importe comprado a cada proveedor (compras recibidas) en el periodo.',
      'parametros' => ['periodo'], 'filtros' => array_keys($filtrosCompras), 'comparar' => 'importe',
      'columnas' => [
        ['proveedor', 'Proveedor', 'texto'], ['compras', 'Compras', 'entero', true], ['unidades', 'Unidades', 'entero', true],
        ['importe', 'Importe', 'moneda', true], ['porcentaje', '% del total', 'porcentaje'],
      ],
      'grafico' => ['etiqueta' => 'proveedor', 'valor' => 'importe', 'tipo' => 'barras', 'maximo' => 10],
      'porcentaje' => 'importe',
      'consulta' => function (PDO $pdo, array $p) use ($filtrosCompras) {
        [$filtro, $valores] = filtrosSql($p, $filtrosCompras);
        return ["SELECT pv.nombre AS proveedor, COUNT(DISTINCT co.id) AS compras, SUM(cl.cantidad) AS unidades, SUM(cl.cantidad * cl.coste) AS importe
          FROM compras co JOIN compra_lineas cl ON cl.compra_id = co.id JOIN proveedores pv ON pv.id = co.proveedor_id
          WHERE co.estado = 'recibida' AND co.fecha BETWEEN ? AND ?$filtro
          GROUP BY pv.id, pv.nombre ORDER BY importe DESC", [$p['desde'], $p['hasta'], ...$valores]];
      },
    ],
  ];
}

// Informes que el usuario puede ver: el módulo debe estar activo y el usuario debe poder ver el recurso
function informesDisponibles(array $esquema, array $usuario): array {
  $lista = [];
  foreach (catalogoInformes() as $id => $i) {
    if (!puede($esquema, $usuario, 'ver', $i['recurso'])) continue;
    if (!in_array($i['modulo'], $GLOBALS['modulos_activos'] ?? [], true)) continue;
    // Los filtros de módulos inactivos o que el usuario no ve, fuera
    $filtros = array_values(array_filter($i['filtros'] ?? [], fn($f) => match ($f) {
      'cliente' => puede($esquema, $usuario, 'ver', 'clientes'), 'proveedor' => puede($esquema, $usuario, 'ver', 'proveedores'),
      'producto', 'categoria' => puede($esquema, $usuario, 'ver', 'productos'), default => true,
    }));
    $lista[] = ['id' => $id, 'titulo' => $i['titulo'], 'grupo' => $i['grupo'], 'descripcion' => $i['descripcion'], 'parametros' => $i['parametros'],
      'filtros' => $filtros, 'comparar' => isset($i['comparar']), 'periodo_defecto' => $i['periodo_defecto'] ?? null];
  }
  return $lista;
}

// Valores para los desplegables de filtros (solo de lo que el usuario puede ver)
function opcionesFiltros(PDO $pdo, array $esquema, array $usuario): array {
  $ver = fn($r) => isset($esquema[$r]) && puede($esquema, $usuario, 'ver', $r);
  $lista = fn($sql) => array_map(fn($f) => ['id' => (int)$f['id'], 'nombre' => $f['nombre']], $pdo->query($sql)->fetchAll());
  return array_filter([
    'cliente' => $ver('clientes') ? $lista('SELECT id, nombre FROM clientes ORDER BY nombre') : null,
    'proveedor' => $ver('proveedores') ? $lista('SELECT id, nombre FROM proveedores ORDER BY nombre') : null,
    'producto' => $ver('productos') ? $lista('SELECT id, nombre FROM productos ORDER BY nombre') : null,
    'categoria' => $ver('productos') ? array_map(fn($c) => ['id' => $c, 'nombre' => $c], $pdo->query("SELECT DISTINCT categoria FROM productos WHERE categoria IS NOT NULL AND categoria <> '' ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN)) : null,
    'estado' => $ver('pedidos') ? array_map(fn($e) => ['id' => $e, 'nombre' => $e], $esquema['pedidos']['campos']['estado']['opciones']) : null,
  ], fn($v) => $v !== null);
}

// Valida los parámetros (fechas, agrupación, días, filtros) con valores por defecto razonables
function parametrosInforme(array $informe, array $entrada): array {
  $fecha = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
  $p = [
    'desde' => $fecha($entrada['desde'] ?? null) ?? date('Y-m-d', strtotime('first day of -11 months')),
    'hasta' => $fecha($entrada['hasta'] ?? null) ?? date('Y-m-d'),
    'agrupacion' => array_key_exists($entrada['agrupacion'] ?? '', AGRUPACIONES) ? $entrada['agrupacion'] : 'mes',
    'dias' => max(1, min(3650, (int)($entrada['dias'] ?? 90))),
    'filtros' => [],
  ];
  foreach ($informe['filtros'] ?? [] as $f) {
    $v = trim((string)($entrada[$f] ?? ''));
    if ($v !== '') $p['filtros'][$f] = in_array($f, ['cliente', 'proveedor', 'producto'], true) ? (int)$v : $v;
  }
  if ($p['desde'] > $p['hasta']) fallar('La fecha de inicio es posterior a la de fin', 422, ['desde' => 'Debe ser anterior a «hasta»']);
  return $p;
}

// Fechas del periodo con el que se compara: el anterior (mismos meses o mismos días) o el del año pasado
function periodoComparado(string $desde, string $hasta, string $tipo): array {
  $d = new DateTimeImmutable($desde);
  $h = new DateTimeImmutable($hasta);
  if ($tipo === 'ano_anterior') return [$d->modify('-1 year')->format('Y-m-d'), $h->modify('-1 year')->format('Y-m-d')];
  // Meses completos (un mes, un trimestre, un año): los mismos meses justo antes
  if ($d->format('d') === '01' && $h->format('Y-m-d') === $h->format('Y-m-t')) {
    $meses = ((int)$h->format('Y') - (int)$d->format('Y')) * 12 + (int)$h->format('n') - (int)$d->format('n') + 1;
    $nuevoDesde = $d->modify("-$meses months");
    return [$nuevoDesde->format('Y-m-d'), $d->modify('-1 day')->format('Y-m-d')];
  }
  $dias = (int)$d->diff($h)->days + 1;
  return [$d->modify("-$dias days")->format('Y-m-d'), $d->modify('-1 day')->format('Y-m-d')];
}

// Ejecuta la consulta del informe con unos parámetros y devuelve sus filas ya con tipos y columnas calculadas
function filasInforme(PDO $pdo, array $informe, array $p, string $id): array {
  if (isset($informe['generar'])) {
    $filas = ($informe['generar'])($pdo, $p);
  } else {
    [$sql, $valores] = ($informe['consulta'])($pdo, $p);
    $inicio = hrtime(true);
    $consulta = $pdo->prepare($sql);
    $consulta->execute($valores);
    $filas = $consulta->fetchAll();
    anotarLenta($pdo, 'informe', $informe['titulo'], $sql, $valores, (hrtime(true) - $inicio) / 1e6, count($filas));
  }

  // Periodos sin datos con valor 0 (como mucho 400: un año por días)
  if (isset($informe['rellenar'])) {
    $porClave = array_column($filas, null, $informe['rellenar']);
    $vacia = array_fill_keys(array_column($informe['columnas'], 0), null);
    foreach ($informe['columnas'] as $c) if (!empty($c[3])) $vacia[$c[0]] = 0;
    $claves = [];
    for ($t = strtotime($p['desde']), $fin = strtotime($p['hasta']); $t <= $fin && count($claves) < 400; $t = strtotime('+1 day', $t)) {
      $claves[clavePeriodo($pdo, $t, $p['agrupacion'])] = true;
    }
    if (count($claves) < 400) $filas = array_map(fn($k) => $porClave[$k] ?? [$informe['rellenar'] => $k] + $vacia, array_keys($claves));
  }

  // Tipos reales en el JSON y columnas calculadas
  $tipos = array_column($informe['columnas'], 2, 0);
  $filas = array_map(function ($f) use ($informe, $tipos) {
    if (isset($informe['calcular'])) $f = ($informe['calcular'])($f);
    foreach ($tipos as $clave => $tipo) {
      if (!isset($f[$clave])) { $f[$clave] = null; continue; }
      if ($tipo === 'moneda') $f[$clave] = round((float)$f[$clave], 2);
      if ($tipo === 'entero') $f[$clave] = (int)$f[$clave];
    }
    return $f;
  }, $filas);

  if (isset($informe['porcentaje'])) {
    $total = array_sum(array_column($filas, $informe['porcentaje'])) ?: 1;
    $filas = array_map(fn($f) => ['porcentaje' => round($f[$informe['porcentaje']] * 100 / $total, 1)] + $f, $filas);
  }
  return $filas;
}

function ejecutarInforme(PDO $pdo, array $esquema, array $usuario, string $id, array $entrada): array {
  $catalogo = catalogoInformes();
  $informe = $catalogo[$id] ?? fallar("Informe desconocido: $id", 404);
  $disponible = array_values(array_filter(informesDisponibles($esquema, $usuario), fn($i) => $i['id'] === $id))[0] ?? null;
  if (!$disponible) fallar('No tienes acceso a este informe o su módulo no está activo.', 403);
  $informe['filtros'] = $disponible['filtros'];
  $p = parametrosInforme($informe, $entrada) + ['iva_general' => $GLOBALS['iva_general'] ?? 21];
  $inicio = hrtime(true);
  $filas = filasInforme($pdo, $informe, $p, $id);
  $columnas = array_map(fn($c) => ['clave' => $c[0], 'etiqueta' => $c[1], 'tipo' => $c[2]], $informe['columnas']);
  $sumables = array_column(array_filter($informe['columnas'], fn($c) => !empty($c[3])), 2, 0);

  // Comparación con otro periodo: columna del valor anterior y variación en %
  $comparacion = null;
  $tipoComparacion = (string)($entrada['comparar'] ?? '');
  if (isset($informe['comparar'], COMPARACIONES[$tipoComparacion]) && in_array('periodo', $informe['parametros'], true)) {
    [$desdeAnterior, $hastaAnterior] = periodoComparado($p['desde'], $p['hasta'], $tipoComparacion);
    $anteriores = filasInforme($pdo, $informe, ['desde' => $desdeAnterior, 'hasta' => $hastaAnterior] + $p, $id);
    $valor = $informe['comparar'];
    $clave = $informe['columnas'][0][0];
    $variacion = fn($actual, $antes) => $antes ? round(($actual - $antes) * 100 / abs($antes), 1) : null;
    if (isset($informe['rellenar'])) {
      // Periodos: se comparan por posición (el 1.er mes con el 1.er mes…)
      foreach ($filas as $i => &$f) {
        $f[$valor . '_anterior'] = $anteriores[$i][$valor] ?? 0;
        $f['periodo_anterior'] = $anteriores[$i][$clave] ?? null;
        $f['variacion'] = $variacion($f[$valor], $f[$valor . '_anterior']);
      }
      unset($f);
    } else {
      // Clientes, productos…: por nombre; los que solo estaban antes también salen (con 0 ahora)
      $porClave = array_column($anteriores, null, $clave);
      foreach ($filas as &$f) {
        $f[$valor . '_anterior'] = $porClave[$f[$clave]][$valor] ?? 0;
        $f['variacion'] = $variacion($f[$valor], $f[$valor . '_anterior']);
        unset($porClave[$f[$clave]]);
      }
      unset($f);
      foreach ($porClave as $antes) {
        $fila = array_fill_keys(array_column($columnas, 'clave'), null);
        foreach ($sumables as $c => $t) $fila[$c] = 0;
        $filas[] = [$clave => $antes[$clave], $valor . '_anterior' => $antes[$valor], 'variacion' => -100.0] + $fila;
      }
    }
    $etiqueta = array_column($columnas, 'etiqueta', 'clave')[$valor];
    $columnas[] = ['clave' => $valor . '_anterior', 'etiqueta' => "$etiqueta anterior", 'tipo' => 'moneda'];
    $columnas[] = ['clave' => 'variacion', 'etiqueta' => 'Variación', 'tipo' => 'porcentaje'];
    $sumables[$valor . '_anterior'] = 'moneda';
    $totalActual = round(array_sum(array_column($filas, $valor)), 2);
    $totalAnterior = round(array_sum(array_column($filas, $valor . '_anterior')), 2);
    $comparacion = ['tipo' => $tipoComparacion, 'nombre' => COMPARACIONES[$tipoComparacion], 'desde' => $desdeAnterior, 'hasta' => $hastaAnterior,
      'actual' => $totalActual, 'anterior' => $totalAnterior, 'variacion' => $variacion($totalActual, $totalAnterior)];
  }

  $totales = [];
  foreach ($sumables as $clave => $tipo) {
    $suma = array_sum(array_map(fn($f) => (float)($f[$clave] ?? 0), $filas));
    $totales[$clave] = $tipo === 'entero' ? (int)$suma : round($suma, 2);
  }
  if ($comparacion) $totales['variacion'] = $comparacion['variacion'];

  return [
    'id' => $id, 'titulo' => $informe['titulo'], 'descripcion' => $informe['descripcion'],
    'parametros' => array_intersect_key($p, array_flip(array_merge(
      in_array('periodo', $informe['parametros'], true) ? ['desde', 'hasta'] : [], $informe['parametros']))) + ['filtros' => (object)$p['filtros']],
    'columnas' => $columnas,
    'filas' => $filas,
    'totales' => $totales,
    'comparacion' => $comparacion,
    'grafico' => $informe['grafico'] ?? null,
    'generado' => date('c'),
    'ms' => round((hrtime(true) - $inicio) / 1e6, 1),
  ];
}

// Exportación de un informe (o de cualquier resultado con columnas y filas) a CSV, Excel o JSON
function exportarResultado(array $resultado, string $formato, string $nombre): never {
  $fichero = preg_replace('/[^a-z0-9_-]+/i', '-', $nombre) . '-' . date('Y-m-d');
  if ($formato === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$fichero.json\"");
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
  if ($formato === 'xlsx' && class_exists('ZipArchive')) {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$fichero.xlsx\"");
    echo libroExcel($resultado, $resultado['titulo'] ?? $nombre);
    exit;
  }
  header('Content-Type: text/csv; charset=utf-8');
  header("Content-Disposition: attachment; filename=\"$fichero.csv\"");
  echo textoCsv($resultado);
  exit;
}

// Libro de Excel (.xlsx) mínimo, sin librerías: una hoja con cabecera en negrita, formatos de euro,
// porcentaje y fecha, fila de totales, filtro automático y la primera fila fija.
function libroExcel(array $resultado, string $titulo): string {
  $x = fn($t) => htmlspecialchars((string)$t, ENT_XML1 | ENT_QUOTES, 'UTF-8');
  $letra = function (int $n) { $s = ''; for ($n++; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s; return $s; };
  $estilos = ['moneda' => 2, 'porcentaje' => 3, 'fecha' => 4, 'decimal' => 5, 'entero' => 6];
  $celda = function (string $ref, $valor, string $tipo, bool $negrita = false) use ($x, $estilos) {
    if ($valor === null || $valor === '') return '';
    if (in_array($tipo, ['moneda', 'porcentaje', 'decimal', 'entero'], true) && is_numeric($valor)) {
      $s = $negrita ? $estilos[$tipo] + 5 : $estilos[$tipo];
      return "<c r=\"$ref\" s=\"$s\"><v>" . ($tipo === 'porcentaje' ? $valor / 100 : $valor) . '</v></c>';
    }
    if ($tipo === 'fecha' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$valor)) {
      $serie = (int)((strtotime($valor . ' UTC') - strtotime('1899-12-30 UTC')) / 86400);
      return "<c r=\"$ref\" s=\"4\"><v>$serie</v></c>";
    }
    return "<c r=\"$ref\" t=\"inlineStr\"" . ($negrita ? ' s="1"' : '') . "><is><t xml:space=\"preserve\">{$x($valor)}</t></is></c>";
  };
  $columnas = $resultado['columnas'];
  $filasXml = '<row r="1">' . implode('', array_map(fn($c, $i) => $celda($letra($i) . '1', $c['etiqueta'], 'texto', true), $columnas, array_keys($columnas))) . '</row>';
  $n = 1;
  foreach ($resultado['filas'] as $fila) {
    $n++;
    $filasXml .= "<row r=\"$n\">" . implode('', array_map(fn($c, $i) => $celda($letra($i) . $n, $fila[$c['clave']] ?? null, $c['tipo']), $columnas, array_keys($columnas))) . '</row>';
  }
  $ultima = $n;
  if (!empty($resultado['totales']) && $resultado['filas']) {
    $n++;
    $filasXml .= "<row r=\"$n\">" . implode('', array_map(fn($c, $i) => $i === 0 ? $celda("A$n", 'TOTAL', 'texto', true)
      : $celda($letra($i) . $n, $resultado['totales'][$c['clave']] ?? null, $c['tipo'], true), $columnas, array_keys($columnas))) . '</row>';
  }
  $anchos = '';
  foreach ($columnas as $i => $c) {
    $largo = max(mb_strlen($c['etiqueta']), ...array_map(fn($f) => mb_strlen((string)($f[$c['clave']] ?? '')), array_slice($resultado['filas'], 0, 200) ?: [[]]));
    $anchos .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(60, max(10, $largo + 3)) . '" customWidth="1"/>';
  }
  $fin = $letra(count($columnas) - 1);
  $hoja = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
    . "<cols>$anchos</cols><sheetData>$filasXml</sheetData>"
    . ($resultado['filas'] ? "<autoFilter ref=\"A1:$fin$ultima\"/>" : '') . '</worksheet>';
  $formatos = '<numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0.00\ &quot;€&quot;"/><numFmt numFmtId="165" formatCode="0.0%"/><numFmt numFmtId="166" formatCode="dd/mm/yyyy"/></numFmts>';
  $xf = fn($fmt, $fuente) => "<xf numFmtId=\"$fmt\" fontId=\"$fuente\" fillId=\"0\" borderId=\"0\" applyNumberFormat=\"1\" applyFont=\"1\"/>";
  $estilosXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . $formatos . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
    . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="12">' . $xf(0, 0) . $xf(0, 1) . $xf(164, 0) . $xf(165, 0) . $xf(166, 0) . $xf(4, 0) . $xf(3, 0)
    . $xf(164, 1) . $xf(165, 1) . $xf(166, 1) . $xf(4, 1) . $xf(3, 1) . '</cellXfs></styleSheet>';
  $nombreHoja = mb_substr(preg_replace('/[\[\]\*\?:\/\\\\]/', '', $titulo) ?: 'Datos', 0, 31);

  $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
  $zip = new ZipArchive();
  $zip->open($ruta, ZipArchive::OVERWRITE);
  $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
  $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
  $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="' . $x($nombreHoja) . '" sheetId="1" r:id="rId1"/></sheets>'
    . ($resultado['filas'] ? '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . $x(str_replace("'", "''", $nombreHoja)) . "'!\$A\$1:\$$fin\$$ultima</definedName></definedNames>" : '')
    . '</workbook>');
  $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
  $zip->addFromString('xl/worksheets/sheet1.xml', $hoja);
  $zip->addFromString('xl/styles.xml', $estilosXml);
  $zip->close();
  $contenido = (string)file_get_contents($ruta);
  unlink($ruta);
  return $contenido;
}

// CSV con «;», BOM y decimales con coma (Excel en español), sin inyección de fórmulas
function textoCsv(array $resultado): string {
  $salida = fopen('php://temp', 'w+');
  fwrite($salida, "\xEF\xBB\xBF");
  fputcsv($salida, array_column($resultado['columnas'], 'etiqueta'), ';', '"', '');
  $celda = function ($valor) {
    if ($valor === null) return '';
    if (is_float($valor) || (is_string($valor) && is_numeric($valor) && str_contains($valor, '.'))) return str_replace('.', ',', (string)$valor);
    $valor = (string)$valor;
    return preg_match('/^[=+\-@\t\r]/', $valor) && !is_numeric($valor) ? "'$valor" : $valor;
  };
  foreach ($resultado['filas'] as $fila) {
    fputcsv($salida, array_map(fn($c) => $celda($fila[$c['clave']] ?? null), $resultado['columnas']), ';', '"', '');
  }
  if (!empty($resultado['totales'])) {
    fputcsv($salida, array_map(fn($c, $i) => $i === 0 ? 'TOTAL' : $celda($resultado['totales'][$c['clave']] ?? null),
      $resultado['columnas'], array_keys($resultado['columnas'])), ';', '"', '');
  }
  rewind($salida);
  return stream_get_contents($salida);
}
