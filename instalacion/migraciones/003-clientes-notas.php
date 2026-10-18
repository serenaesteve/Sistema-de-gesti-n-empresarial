<?php
// v4.1 · Campo «Notas» en la ficha del cliente (CRM).
return [
  'version' => '4.1',
  'descripcion' => 'Notas en los clientes',
  'aplicar' => fn(PDO $pdo, array $esquema) => anadirColumna($pdo, 'clientes', 'notas', 'TEXT'),
];
