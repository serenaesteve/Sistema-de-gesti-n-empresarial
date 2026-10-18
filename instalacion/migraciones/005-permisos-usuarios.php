<?php
// v4.1 · Permisos por módulo y usuario (además de los del rol).
return [
  'version' => '4.1',
  'descripcion' => 'Permisos por módulo en cada usuario',
  'aplicar' => fn(PDO $pdo, array $esquema) => anadirColumna($pdo, 'usuarios', 'permisos', 'TEXT'),
];
