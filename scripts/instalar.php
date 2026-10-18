<?php
// Instalación desatendida desde la terminal (sin navegador).
// Útil en servidores sin entorno gráfico, en scripts de despliegue y en las pruebas automáticas.
//
//   php scripts/instalar.php --perfil=tienda --empresa="Mi Tienda S.L." \
//       --admin-nombre="Ana López" --admin-email=ana@mitienda.es --admin-clave=secreto123 [--demo]
//
// Opciones:
//   --perfil=consultoria|tienda|distribuidora|personalizado   (obligatoria)
//   --modulos=crm,inventario,ventas                           solo con --perfil=personalizado
//   --motor=sqlite|mysql                                      por defecto, sqlite
//   --mysql-host= --mysql-bd= --mysql-usuario= --mysql-clave= con --motor=mysql
//   --entorno=desarrollo|pruebas|explotacion                  por defecto, explotacion
//   --empresa= --nif= --direccion= --email=                   datos de la empresa
//   --admin-nombre= --admin-email= --admin-clave=             primer administrador
//   --demo                                                    carga datos de demostración
//   --sqlite=ruta --instalacion=ruta                          otras rutas (pruebas automáticas)
//   --ayuda

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("Este script solo se ejecuta desde la terminal.\n");

$opciones = getopt('', [
  'perfil:', 'modulos:', 'motor:', 'entorno:', 'mysql-host:', 'mysql-bd:', 'mysql-usuario:', 'mysql-clave:',
  'empresa:', 'nif:', 'direccion:', 'email:', 'admin-nombre:', 'admin-email:', 'admin-clave:',
  'demo', 'sqlite:', 'instalacion:', 'ayuda',
]);

if (isset($opciones['ayuda']) || !isset($opciones['perfil'])) {
  // La ayuda es el comentario del principio de este archivo
  preg_match_all('/^\/\/ ?(.*)$/m', (string)file_get_contents(__FILE__), $ayuda);
  echo implode("\n", array_slice($ayuda[1], 0, 18)), "\n";
  exit(isset($opciones['ayuda']) ? 0 : 1);
}

// Las rutas se pasan a la configuración igual que en Docker: con variables de entorno
if (isset($opciones['sqlite'])) putenv('ERP_SQLITE=' . $opciones['sqlite']);
if (isset($opciones['instalacion'])) putenv('ERP_INSTALACION=' . $opciones['instalacion']);

$raiz = dirname(__DIR__) . '/api';
$config  = require "$raiz/config.php";
$esquema = require "$raiz/esquema.php";
date_default_timezone_set($config['zona_horaria']);
foreach (['nucleo', 'auth', 'bd', 'instalacion', 'migraciones', 'copia', 'negocio'] as $archivo) require "$raiz/$archivo.php";

$entrada = [
  'perfil'  => $opciones['perfil'],
  'modulos' => isset($opciones['modulos']) ? explode(',', $opciones['modulos']) : [],
  'motor'   => $opciones['motor'] ?? 'sqlite',
  'entorno' => $opciones['entorno'] ?? 'explotacion',
  'mysql'   => [
    'host' => $opciones['mysql-host'] ?? 'localhost', 'bd' => $opciones['mysql-bd'] ?? '',
    'usuario' => $opciones['mysql-usuario'] ?? '', 'clave' => $opciones['mysql-clave'] ?? '',
  ],
  'empresa' => [
    'nombre' => $opciones['empresa'] ?? '', 'nif' => $opciones['nif'] ?? '',
    'direccion' => $opciones['direccion'] ?? '', 'email' => $opciones['email'] ?? '',
  ],
  'admin' => [
    'nombre' => $opciones['admin-nombre'] ?? '', 'email' => $opciones['admin-email'] ?? '', 'clave' => $opciones['admin-clave'] ?? '',
  ],
  'demo' => isset($opciones['demo']),
];

try {
  $config = conInstalacion($config);
  $resultado = instalar($config, $esquema, $entrada);
  $catalogo = catalogoModulos();
  echo "✓ Blush ERP " . VERSION_APP . " instalado\n";
  echo "  Perfil:   {$resultado['perfil']}\n";
  echo "  Motor:    {$resultado['motor']}\n";
  echo "  Entorno:  {$resultado['entorno']}\n";
  echo "  Módulos:  " . implode(', ', array_map(fn($m) => $catalogo[$m]['nombre'], $resultado['modulos'])) . "\n";
  echo "  Archivo:  " . rutaInstalacion() . "\n";
  exit(0);
} catch (ErrorApi $e) {
  fwrite(STDERR, "✗ {$e->getMessage()}\n");
  foreach ($e->errores as $campo => $mensaje) fwrite(STDERR, "  · $campo: $mensaje\n");
  exit(1);
} catch (Throwable $e) {
  fwrite(STDERR, "✗ Error: {$e->getMessage()}\n");
  exit(1);
}
