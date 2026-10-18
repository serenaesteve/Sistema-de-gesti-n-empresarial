<?php
// v4.1 · Claves de acceso a la API para otros programas (servicios de acceso).
return [
  'version' => '4.1',
  'descripcion' => 'Claves de acceso a la API',
  'aplicar' => fn(PDO $pdo, array $esquema) => crearTablas($pdo, $esquema, ['claves_api']),
];
