# Migraciones

Cada archivo `NNN-nombre.php` describe un cambio en la estructura de la base de datos y devuelve:

```php
return [
  'version' => '4.1',                       // versión del ERP que lo introduce
  'descripcion' => 'Notas en los clientes',
  'aplicar' => fn(PDO $pdo, array $esquema) => anadirColumna($pdo, 'clientes', 'notas', 'TEXT'),
];
```

- Se aplican en orden y solo una vez: las aplicadas quedan en la tabla `migraciones`.
- Deben ser **idempotentes** (`anadirColumna`, `crearIndice` y `crearTablas` comprueban antes si ya existe), porque una instalación nueva crea las tablas en su última versión y después las ejecuta todas.
- Antes de actualizar se hace una copia de seguridad automática en `datos/copias/`.
- Con SQLite, si una falla se deshace todo (el DDL de SQLite es transaccional). Con MySQL, el DDL no se puede deshacer: se para en la que falla y se indica la copia para restaurar.
