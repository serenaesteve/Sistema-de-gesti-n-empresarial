<?php
// v5.1 · Historial de importaciones, para poder deshacerlas.
return [
  'version' => '5.1',
  'descripcion' => 'Historial de importaciones (deshacer)',
  'aplicar' => fn(PDO $pdo, array $esquema) => crearTablas($pdo, $esquema, ['importaciones']),
];
