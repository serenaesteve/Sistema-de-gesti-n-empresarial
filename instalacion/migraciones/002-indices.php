<?php
// v4.1 · Índices para que los listados y el panel sigan siendo rápidos con muchos datos.
return [
  'version' => '4.1',
  'descripcion' => 'Índices en fechas, estados y líneas',
  'aplicar' => function (PDO $pdo, array $esquema) {
    crearIndice($pdo, 'idx_pedidos_fecha', 'pedidos', 'fecha');
    crearIndice($pdo, 'idx_pedido_lineas_pedido', 'pedido_lineas', 'pedido_id');
    crearIndice($pdo, 'idx_compra_lineas_compra', 'compra_lineas', 'compra_id');
    crearIndice($pdo, 'idx_facturas_fecha', 'facturas', 'fecha');
    crearIndice($pdo, 'idx_clientes_estado', 'clientes', 'estado');
    crearIndice($pdo, 'idx_registro_fecha', 'registro', 'fecha');
  },
];
