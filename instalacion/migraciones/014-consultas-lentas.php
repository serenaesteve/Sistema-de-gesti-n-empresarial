<?php
// v5.1 · Registro de consultas lentas (RA3, criterio f).
return [
  'version' => '5.1',
  'descripcion' => 'Registro de consultas lentas',
  'aplicar' => function (PDO $pdo, array $esquema) {
    crearTablas($pdo, $esquema, ['consultas_lentas']);
    crearIndice($pdo, 'idx_consultas_lentas_creado', 'consultas_lentas', 'creado');
  },
];
