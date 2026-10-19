<?php
// v5.0 (RA3) · Índices para los informes y el análisis con datos reales.
// La prueba de carga (scripts/carga.php) mostró que el análisis tardaba ~300 ms con 3.000 pedidos:
// recorría todas las líneas de pedido y de compra por cada producto.
return [
  'version' => '5.0',
  'descripcion' => 'Índices para informes y análisis',
  'aplicar' => function (PDO $pdo, array $esquema) {
    crearIndice($pdo, 'idx_pedido_lineas_producto', 'pedido_lineas', 'producto_id');
    crearIndice($pdo, 'idx_compra_lineas_producto', 'compra_lineas', 'producto_id');
    crearIndice($pdo, 'idx_pedidos_cliente', 'pedidos', 'cliente_id, fecha');
    crearIndice($pdo, 'idx_peticiones_creado', 'peticiones', 'creado');
    crearIndice($pdo, 'idx_metricas_creado', 'metricas', 'creado');
    crearIndice($pdo, 'idx_envios_origen', 'formulario_envios', 'origen, creado');
  },
];
