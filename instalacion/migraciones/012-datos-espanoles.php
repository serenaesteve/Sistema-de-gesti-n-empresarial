<?php
// v5.1 · Código postal, provincia e IBAN en clientes y proveedores.
return [
  'version' => '5.1',
  'descripcion' => 'Código postal, provincia e IBAN en clientes y proveedores',
  'aplicar' => function (PDO $pdo, array $esquema) {
    foreach (['clientes', 'proveedores'] as $tabla) {
      anadirColumna($pdo, $tabla, 'codigo_postal', 'VARCHAR(5)');
      anadirColumna($pdo, $tabla, 'provincia', 'VARCHAR(255)');
      anadirColumna($pdo, $tabla, 'iban', 'VARCHAR(34)');
    }
  },
];
