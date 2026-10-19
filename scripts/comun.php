<?php
// Arranque común de los scripts de terminal (tareas.php, monitor.php, carga.php):
// carga la configuración y la API, conecta con la base de datos y lee los módulos activos.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("Este script solo se ejecuta desde la terminal.\n");

$raiz = dirname(__DIR__) . '/api';
$config  = require "$raiz/config.php";
$esquema = require "$raiz/esquema.php";
date_default_timezone_set($config['zona_horaria']);
foreach (['nucleo', 'auth', 'bd', 'instalacion', 'migraciones', 'copia', 'negocio', 'crud', 'panel',
          'informes', 'consultas', 'formularios', 'tareas', 'rendimiento', 'analisis', 'intercambio'] as $archivo) require "$raiz/$archivo.php";

function arrancar(array $config, array $esquema): array {
  $config = conInstalacion($config);
  if ($config['instalacion'] === null) throw new RuntimeException('El ERP no está instalado.');
  $pdo = conectar($config, $esquema);
  asegurarTablaMigraciones($pdo);
  if ($pendientes = migracionesPendientes($pdo)) {
    throw new RuntimeException(count($pendientes) . ' migraciones pendientes: actualiza el ERP desde el navegador antes de usar este script.');
  }
  $catalogo = catalogoModulos();
  $GLOBALS['catalogo'] = $catalogo;
  $GLOBALS['recurso_modulo'] = mapaRecursos($catalogo);
  $ajustes = leerAjustes($pdo, $config, $catalogo);
  $config = aplicarAjustes($config, $esquema, $ajustes);
  $GLOBALS['modulos_activos'] = $ajustes['modulos'];
  $GLOBALS['iva_general'] = (int)$config['empresa']['iva'];
  $GLOBALS['config_erp'] = $config;
  return [$pdo, $config];
}
