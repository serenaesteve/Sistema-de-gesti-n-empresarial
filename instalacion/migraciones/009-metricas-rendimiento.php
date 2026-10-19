<?php
// v5.0 (RA3) · Métricas de rendimiento.
return [
  'version' => '5.0',
  'descripcion' => 'Métricas de rendimiento',
  'aplicar' => fn(PDO $pdo, array $esquema) => crearTablas($pdo, $esquema, ['metricas', 'peticiones']),
];
