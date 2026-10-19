<?php
// v5.0 (RA3) · Consultas guardadas.
return [
  'version' => '5.0',
  'descripcion' => 'Consultas guardadas',
  'aplicar' => fn(PDO $pdo, array $esquema) => crearTablas($pdo, $esquema, ['consultas']),
];
