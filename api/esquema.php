<?php
// Definición de los módulos de datos.
// Es la única fuente de verdad: a partir de aquí se crean las tablas, se valida lo que
// llega a la API, se comprueban los permisos y el frontend genera tablas y formularios.
//
// Tipos de campo: texto, email, tel, clave, opcion, moneda, entero, fecha, relacion.
//
// Opciones de módulo:
//   icono         nombre del icono SVG del menú (nucleo/js/dom.js)
//   grupo         sección del menú lateral
//   principal     campo que identifica el registro («Cliente «Lucía»»); 'id' → «Pedido #3»
//   lista         columnas de la tabla (por defecto, todas); 'lineas' = resumen de las líneas
//   lineas        tabla hija con varias líneas por registro (productos de un pedido o compra)
//   solo_lectura  sin alta/edición/borrado genéricos (las facturas se crean con «Facturar»)
//   femenino      para los textos: «Factura creada», «Nueva factura»
//   ficha         el nombre enlaza a una vista de detalle (#/clientes/3)
//   permisos      acciones del rol «comercial» (el rol «admin» puede hacerlo todo)
//   bloqueo       si este campo de la fila tiene valor, no se puede editar ni borrar
//   ver           enlace de la acción «Ver» de cada fila ({campo} se sustituye)
//
// Opciones de campo:
//   obligatorio, defecto ('hoy' = fecha actual), minimo, alerta (stock bajo), unico,
//   auto (lo rellena la lógica de negocio: no aparece en el formulario),
//   enlace (la celda enlaza a esta URL), oculto (se envía pero no se muestra),
//   detalle (dato extra en el desplegable de una relación: [campo, sufijo])

$producto = ['etiqueta' => 'Producto', 'tipo' => 'relacion', 'obligatorio' => true, 'tabla' => 'productos', 'mostrar' => 'nombre', 'detalle' => ['stock', 'uds.']];
$cantidad = ['etiqueta' => 'Cantidad', 'tipo' => 'entero', 'obligatorio' => true, 'defecto' => 1, 'minimo' => 1];

// Total de un registro con líneas: suma de cantidad × precio
$totalLineas = fn(string $tabla, string $clave, string $precio) =>
  "(SELECT COALESCE(SUM(l.cantidad * l.$precio), 0) FROM $tabla l WHERE l.$clave = t.id)";

// Factura vigente de un pedido: la ordinaria que no ha sido rectificada
$facturaVigente = fn(string $columna) =>
  "(SELECT f.$columna FROM facturas f WHERE f.pedido_id = t.id AND f.tipo = 'ordinaria'"
  . " AND NOT EXISTS (SELECT 1 FROM facturas r WHERE r.rectifica_id = f.id))";

return [

  // ---------- Ventas ----------

  'clientes' => [
    'titulo'    => 'Clientes',
    'singular'  => 'cliente',
    'icono'     => 'clientes',
    'grupo'     => 'Ventas',
    'principal' => 'nombre',
    'ficha'     => true,
    'lista'     => ['nombre', 'empresa', 'email', 'telefono', 'ciudad', 'estado'],
    'permisos'  => ['comercial' => ['ver', 'crear', 'editar']],
    'campos' => [
      'nombre'    => ['etiqueta' => 'Nombre',    'tipo' => 'texto', 'obligatorio' => true],
      'empresa'   => ['etiqueta' => 'Empresa',   'tipo' => 'texto'],
      'nif'       => ['etiqueta' => 'NIF / CIF', 'tipo' => 'texto'],
      'email'     => ['etiqueta' => 'Email',     'tipo' => 'email'],
      'telefono'  => ['etiqueta' => 'Teléfono',  'tipo' => 'tel'],
      'direccion' => ['etiqueta' => 'Dirección', 'tipo' => 'texto'],
      'ciudad'    => ['etiqueta' => 'Ciudad',    'tipo' => 'texto'],
      'estado'    => [
        'etiqueta' => 'Estado', 'tipo' => 'opcion', 'obligatorio' => true, 'defecto' => 'potencial',
        'opciones' => ['potencial', 'activo', 'inactivo'],
        'colores'  => ['potencial' => 'ambar', 'activo' => 'verde', 'inactivo' => 'gris'],
      ],
    ],
  ],

  'pedidos' => [
    'titulo'    => 'Pedidos',
    'singular'  => 'pedido',
    'icono'     => 'pedidos',
    'grupo'     => 'Ventas',
    'principal' => 'id',
    'lista'     => ['cliente_id', 'lineas', 'fecha', 'estado'],
    'bloqueo'   => 'factura',
    'permisos'  => ['comercial' => ['ver', 'crear', 'editar']],
    'campos' => [
      'cliente_id' => ['etiqueta' => 'Cliente', 'tipo' => 'relacion', 'obligatorio' => true, 'tabla' => 'clientes', 'mostrar' => 'nombre'],
      'fecha'      => ['etiqueta' => 'Fecha',   'tipo' => 'fecha',    'obligatorio' => true, 'defecto' => 'hoy'],
      'estado'     => [
        'etiqueta' => 'Estado', 'tipo' => 'opcion', 'obligatorio' => true, 'defecto' => 'pendiente',
        'opciones' => ['pendiente', 'enviado', 'entregado', 'cancelado'],
        'colores'  => ['pendiente' => 'ambar', 'enviado' => 'azul', 'entregado' => 'verde', 'cancelado' => 'gris'],
      ],
    ],
    'lineas' => [
      'tabla' => 'pedido_lineas', 'clave' => 'pedido_id', 'etiqueta' => 'Productos',
      'campos' => [
        'producto_id' => $producto,
        'cantidad'    => $cantidad,
        // Precio del momento de la venta: si luego cambia el catálogo, el pedido no cambia.
        'precio'      => ['etiqueta' => 'Precio unit.', 'tipo' => 'moneda', 'auto' => true],
      ],
    ],
    // Columnas que no se guardan: se calculan en la consulta (t = esta tabla).
    'calculados' => [
      'total'      => ['etiqueta' => 'Total',   'tipo' => 'moneda', 'sql' => $totalLineas('pedido_lineas', 'pedido_id', 'precio')],
      'factura'    => ['etiqueta' => 'Factura', 'tipo' => 'texto',  'sql' => $facturaVigente('numero'), 'enlace' => 'factura.html?id={factura_id}'],
      'factura_id' => ['etiqueta' => 'Id factura', 'tipo' => 'entero', 'oculto' => true, 'sql' => $facturaVigente('id')],
    ],
  ],

  'facturas' => [
    'titulo'       => 'Facturas',
    'singular'     => 'factura',
    'icono'        => 'facturas',
    'grupo'        => 'Ventas',
    'principal'    => 'numero',
    'femenino'     => true,
    'solo_lectura' => true,
    'ver'          => 'factura.html?id={id}',
    'lista'        => ['numero', 'tipo', 'fecha', 'cliente', 'concepto', 'base', 'total'],
    // crear = facturar un pedido; rectificar exige 'editar' (solo admin)
    'permisos'     => ['comercial' => ['ver', 'crear']],
    // Todo se copia del pedido al facturar: la factura no cambia aunque cambie el cliente o el producto.
    'campos' => [
      'numero'       => ['etiqueta' => 'Número',      'tipo' => 'texto',    'obligatorio' => true, 'unico' => true, 'enlace' => 'factura.html?id={id}'],
      'tipo'         => [
        'etiqueta' => 'Tipo', 'tipo' => 'opcion', 'obligatorio' => true, 'defecto' => 'ordinaria',
        'opciones' => ['ordinaria', 'rectificativa'],
        'colores'  => ['ordinaria' => 'gris', 'rectificativa' => 'rojo'],
      ],
      'fecha'        => ['etiqueta' => 'Fecha',       'tipo' => 'fecha',    'obligatorio' => true],
      'pedido_id'    => ['etiqueta' => 'Pedido',      'tipo' => 'relacion', 'tabla' => 'pedidos', 'mostrar' => 'id'],
      'rectifica_id' => ['etiqueta' => 'Rectifica a', 'tipo' => 'relacion', 'tabla' => 'facturas', 'mostrar' => 'numero'],
      'motivo'       => ['etiqueta' => 'Motivo',      'tipo' => 'texto'],
      'cliente'      => ['etiqueta' => 'Cliente',     'tipo' => 'texto',    'obligatorio' => true],
      'nif'          => ['etiqueta' => 'NIF / CIF',   'tipo' => 'texto'],
      'direccion'    => ['etiqueta' => 'Dirección',   'tipo' => 'texto'],
      'concepto'     => ['etiqueta' => 'Concepto',    'tipo' => 'texto',    'obligatorio' => true],
      'base'         => ['etiqueta' => 'Base',        'tipo' => 'moneda',   'obligatorio' => true],
      'iva'          => ['etiqueta' => 'IVA %',       'tipo' => 'entero',   'obligatorio' => true],
      'cuota'        => ['etiqueta' => 'Cuota IVA',   'tipo' => 'moneda',   'obligatorio' => true],
      'total'        => ['etiqueta' => 'Total',       'tipo' => 'moneda',   'obligatorio' => true],
    ],
    'lineas' => [
      'tabla' => 'factura_lineas', 'clave' => 'factura_id', 'etiqueta' => 'Líneas',
      'campos' => [
        'concepto' => ['etiqueta' => 'Concepto', 'tipo' => 'texto',  'obligatorio' => true],
        'cantidad' => ['etiqueta' => 'Cantidad', 'tipo' => 'entero', 'obligatorio' => true],
        'precio'   => ['etiqueta' => 'Precio',   'tipo' => 'moneda', 'obligatorio' => true],
        'importe'  => ['etiqueta' => 'Importe',  'tipo' => 'moneda', 'obligatorio' => true],
      ],
    ],
    'calculados' => [
      'rectificada' => ['etiqueta' => 'Rectificada por', 'tipo' => 'texto', 'oculto' => true, 'sql' => '(SELECT r.numero FROM facturas r WHERE r.rectifica_id = t.id)'],
    ],
  ],

  // ---------- Compras ----------

  'productos' => [
    'titulo'    => 'Productos',
    'singular'  => 'producto',
    'icono'     => 'productos',
    'grupo'     => 'Compras',
    'principal' => 'nombre',
    'permisos'  => ['comercial' => ['ver']],
    'campos' => [
      'nombre'    => ['etiqueta' => 'Nombre',    'tipo' => 'texto',  'obligatorio' => true],
      'categoria' => ['etiqueta' => 'Categoría', 'tipo' => 'texto'],
      'precio'    => ['etiqueta' => 'Precio',    'tipo' => 'moneda', 'obligatorio' => true],
      'stock'     => ['etiqueta' => 'Stock',     'tipo' => 'entero', 'obligatorio' => true, 'defecto' => 0, 'minimo' => 0, 'alerta' => 15],
    ],
  ],

  'proveedores' => [
    'titulo'    => 'Proveedores',
    'singular'  => 'proveedor',
    'icono'     => 'proveedores',
    'grupo'     => 'Compras',
    'principal' => 'nombre',
    'permisos'  => ['comercial' => ['ver']],
    'campos' => [
      'nombre'   => ['etiqueta' => 'Nombre',    'tipo' => 'texto', 'obligatorio' => true],
      'nif'      => ['etiqueta' => 'NIF / CIF', 'tipo' => 'texto'],
      'email'    => ['etiqueta' => 'Email',     'tipo' => 'email'],
      'telefono' => ['etiqueta' => 'Teléfono',  'tipo' => 'tel'],
      'ciudad'   => ['etiqueta' => 'Ciudad',    'tipo' => 'texto'],
    ],
  ],

  'compras' => [
    'titulo'    => 'Compras',
    'singular'  => 'compra',
    'icono'     => 'compras',
    'grupo'     => 'Compras',
    'principal' => 'id',
    'femenino'  => true,
    'lista'     => ['proveedor_id', 'lineas', 'fecha', 'estado'],
    'permisos'  => ['comercial' => ['ver']],
    'campos' => [
      'proveedor_id' => ['etiqueta' => 'Proveedor', 'tipo' => 'relacion', 'obligatorio' => true, 'tabla' => 'proveedores', 'mostrar' => 'nombre'],
      'fecha'        => ['etiqueta' => 'Fecha',     'tipo' => 'fecha',    'obligatorio' => true, 'defecto' => 'hoy'],
      // Al marcarla como recibida, el stock de sus productos sube.
      'estado'       => [
        'etiqueta' => 'Estado', 'tipo' => 'opcion', 'obligatorio' => true, 'defecto' => 'pedida',
        'opciones' => ['pedida', 'recibida', 'cancelada'],
        'colores'  => ['pedida' => 'azul', 'recibida' => 'verde', 'cancelada' => 'gris'],
      ],
    ],
    'lineas' => [
      'tabla' => 'compra_lineas', 'clave' => 'compra_id', 'etiqueta' => 'Productos',
      'campos' => [
        'producto_id' => $producto,
        'cantidad'    => $cantidad,
        'coste'       => ['etiqueta' => 'Coste unit.', 'tipo' => 'moneda', 'obligatorio' => true],
      ],
    ],
    'calculados' => [
      'total' => ['etiqueta' => 'Total', 'tipo' => 'moneda', 'sql' => $totalLineas('compra_lineas', 'compra_id', 'coste')],
    ],
  ],

  // ---------- Administración ----------

  'usuarios' => [
    'titulo'    => 'Usuarios',
    'singular'  => 'usuario',
    'icono'     => 'usuarios',
    'grupo'     => 'Administración',
    'principal' => 'nombre',
    'permisos'  => [],
    'campos' => [
      'nombre' => ['etiqueta' => 'Nombre',     'tipo' => 'texto', 'obligatorio' => true],
      'email'  => ['etiqueta' => 'Email',      'tipo' => 'email', 'obligatorio' => true, 'unico' => true],
      'clave'  => ['etiqueta' => 'Contraseña', 'tipo' => 'clave', 'obligatorio' => true],
      'rol'    => [
        'etiqueta' => 'Rol', 'tipo' => 'opcion', 'obligatorio' => true, 'defecto' => 'comercial',
        'opciones' => ['admin', 'comercial'],
        'colores'  => ['admin' => 'rosa', 'comercial' => 'azul'],
      ],
    ],
  ],

];
