<?php
// v5.1 · Registro de incidencias (RA3, criterio g).
return [
  'version' => '5.1',
  'descripcion' => 'Registro de incidencias',
  'aplicar' => fn(PDO $pdo, array $esquema) => crearTablas($pdo, $esquema, ['incidencias']),
];
