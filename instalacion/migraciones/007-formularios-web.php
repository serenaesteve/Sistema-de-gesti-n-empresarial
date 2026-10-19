<?php
// v5.0 (RA3) · Formularios web públicos.
return [
  'version' => '5.0',
  'descripcion' => 'Formularios web públicos',
  'aplicar' => fn(PDO $pdo, array $esquema) => crearTablas($pdo, $esquema, ['formularios', 'formulario_envios']),
];
