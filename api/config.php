<?php
// Configuración general de Blush ERP.
// Para usar MySQL en lugar de SQLite: crear la base de datos con scripts/mysql.sql
// y cambiar 'motor' a 'mysql' (o definir la variable de entorno ERP_MOTOR=mysql en Apache).
// Variables de entorno: ERP_MOTOR, ERP_SQLITE, ERP_MYSQL_HOST, ERP_MYSQL_BD, ERP_MYSQL_USUARIO,
// ERP_MYSQL_CLAVE (las usan Docker y las pruebas automáticas).

return [
  'app' => [
    'nombre'  => 'serena | blush',
    'version' => '3.0',
  ],

  // Datos del emisor que aparecen en las facturas (empresa ficticia de demostración)
  'empresa' => [
    'nombre'    => 'Blush Studio S.L.',
    'nif'       => 'B12345678',
    'direccion' => 'C/ de la Paz 12, 46003 Valencia',
    'email'     => 'hola@blush.test',
    'iva'       => 21,
  ],

  // Se aplica en api.php aunque php.ini no defina date.timezone.
  'zona_horaria' => 'Europe/Madrid',

  // Filas por página en los listados
  'por_pagina' => 15,

  'motor' => getenv('ERP_MOTOR') ?: 'sqlite',

  'sqlite' => getenv('ERP_SQLITE') ?: __DIR__ . '/../datos/erp.sqlite',

  'mysql' => [
    'host'    => getenv('ERP_MYSQL_HOST') ?: 'localhost',
    'bd'      => getenv('ERP_MYSQL_BD') ?: 'blush_erp',
    'usuario' => getenv('ERP_MYSQL_USUARIO') ?: 'blush',
    // Clave solo para el entorno local de clase; en producción usar ERP_MYSQL_CLAVE.
    'clave'   => getenv('ERP_MYSQL_CLAVE') ?: 'blush_local_2026',
  ],
];
