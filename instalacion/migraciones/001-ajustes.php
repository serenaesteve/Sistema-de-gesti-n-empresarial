<?php
// v4.0 · Tabla de ajustes (empresa, IVA, módulos activos…). Las bases de datos de la v3 no la tenían.
return [
  'version' => '4.0',
  'descripcion' => 'Tabla de ajustes de la instalación',
  'aplicar' => fn(PDO $pdo, array $esquema) => crearTablas($pdo, $esquema, ['ajustes']),
];
