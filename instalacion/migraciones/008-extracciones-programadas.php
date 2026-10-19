<?php
// v5.0 (RA3) · Extracciones programadas de informes.
return [
  'version' => '5.0',
  'descripcion' => 'Extracciones programadas de informes',
  'aplicar' => fn(PDO $pdo, array $esquema) => crearTablas($pdo, $esquema, ['tareas', 'tarea_ejecuciones']),
];
