# RA2 · Implantación de sistemas ERP-CRM

**Serena Esteve** · 0491 Sistemas de gestión empresarial

Para este RA he seguido trabajando sobre mi ERP (serena | blush) con las mismas ideas que vimos en clase con Crimson: instalador, módulos con su `modulo.json`, presets y opciones.

## a) Módulos del ERP-CRM

Mi ERP tiene 6 módulos. Cada uno está descrito en un archivo JSON (`api/modulos/`) con sus pantallas y los módulos que necesita:

- **Núcleo y administración:** usuarios, panel, registro, ajustes y sistema. Es obligatorio.
- **CRM:** los clientes.
- **Inventario:** los productos y el stock.
- **Ventas:** los pedidos. Necesita CRM e Inventario.
- **Facturación:** las facturas. Necesita Ventas.
- **Compras:** proveedores y compras. Necesita Inventario.

## b) Tipos de instalación

Se puede instalar de varias formas:

- **Con el asistente web:** la primera vez que abres el ERP te lleva solo a `instalar.html`, que va paso a paso: requisitos, perfil, base de datos, empresa y administrador, y confirmar.
- **Desde la terminal, sin navegador:** con `php scripts/instalar.php`. Sirve para servidores sin pantalla o para automatizarlo.
- **Con Docker:** con `docker compose up`, que monta Apache, PHP y MySQL.
- **En un entorno de pruebas:** las pruebas automáticas instalan el ERP en carpetas temporales.

Además, como ya lo tenía instalado en Apache de la versión anterior, el ERP lo detecta y lo adopta sin perder los datos.

**Actualizaciones:** cada cambio en la base de datos es una migración numerada. Cuando hay alguna pendiente, el ERP se pone en mantenimiento y el administrador ve la pantalla «Actualizar». Antes de aplicar nada, guarda una copia de seguridad. Así actualicé mi instalación de Apache de la 4.0 a la 4.1.

**Entornos:** al instalar eliges desarrollo, pruebas o explotación. En desarrollo se ven los errores reales y una franja de color; en explotación se ocultan y Sistema es más estricto (por ejemplo, exige HTTPS).

**Reinstalar:** desde Sistema se puede volver a instalar. Pide la contraseña y el nombre de la empresa, y antes guarda una copia.

## c) Configuración de los módulos

En **Administración → Módulos** se activan y desactivan los módulos:

- Si activas Ventas, se activan también CRM e Inventario, porque los necesita.
- No te deja desactivar uno que otro necesita, ni el núcleo.
- Al desactivar un módulo desaparece del menú, pero sus datos no se borran.

En **Administración → Ajustes** se configuran los datos de la empresa, el IVA, las series de las facturas, el stock mínimo, las filas por página y el entorno.

Además:

- **Permisos por usuario:** en cada usuario se marca qué puede hacer en cada módulo (ver, crear, editar, borrar).
- **Acceso API:** se crean claves para que otros programas (por ejemplo, una tienda online) lean o den de alta datos sin iniciar sesión. Nunca pueden borrar, y la clave se puede revocar.

## d) Instalaciones según el supuesto

Hice tres perfiles para tres tipos de empresa:

- **Consultoría:** solo necesita el CRM.
- **Tienda pequeña:** CRM, Inventario, Ventas y Facturación, con SQLite.
- **Distribuidora:** todo, incluidas las compras. Se recomienda MySQL porque trabajan varios usuarios a la vez.

También se puede hacer una instalación personalizada eligiendo los módulos uno a uno.

## e) Verificación

Lo comprobé de dos maneras:

- **Pruebas automáticas:** instalan los tres perfiles y comprueban:
  - que cada uno tiene solo sus módulos;
  - que no se puede instalar dos veces y que los datos erróneos se rechazan;
  - que los ajustes se aplican;
  - la actualización, las claves de API, los permisos, los entornos y la reinstalación.

  Son 79 pruebas en total y pasan todas.
- **Vista Sistema:** después de actualizar, la instalación de Apache da 40 comprobaciones correctas, 9 avisos y ningún error. Los archivos de `datos/` no se pueden descargar.
- **Informe de instalación:** el ERP genera uno que resume lo instalado y se puede adjuntar.

## f) Operaciones e incidencias

**Operaciones:**

1. Estudié las versiones de Crimson de clase.
2. Creé el catálogo de módulos y los perfiles.
3. Hice el instalador web y el de la terminal.
4. Añadí las pantallas de Módulos y Ajustes.
5. Probé los tres supuestos.
6. Verifiqué la instalación en Apache.
7. Añadí actualizaciones, claves de API, entornos y permisos por usuario.
8. Actualicé mi instalación de Apache de la 4.0 a la 4.1.

El propio ERP también anota en el Registro la instalación y cada cambio de configuración.

**Incidencias:**

- **El ERP ya estaba instalado sin el archivo de instalación nuevo.** Hice que lo adopte solo, sin perder datos.
- **Si instalas desde la terminal, los archivos son de mi usuario y Apache no podría escribir.** El instalador les da los permisos necesarios.
- **No pude instalar con MySQL porque mi usuario de MySQL no existe.** El asistente lo detecta con «Probar conexión» y muestra el error. Necesito `sudo` para crear el usuario.
- **Las copias de seguridad de la versión anterior no sirven en la nueva**, porque hay una tabla más. El ERP las rechaza sin tocar nada.
- **Docker sigue sin poder probarse** porque mi usuario no tiene permisos.
- **Reinstalar fallaba al hacer la copia** porque quedaba una consulta abierta. El entorno de desarrollo me mostró el error real y lo arreglé.
- **El cambio de SQLite a MySQL** está explicado paso a paso, pero no he podido probarlo por el mismo problema del usuario de MySQL.
