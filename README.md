# serena | blush · ERP-CRM

Mini ERP-CRM hecho para los **RA1, RA2 y RA3 de 0491 · Sistemas de gestión empresarial** (DAM2).
Parte de la estructura de **Crimson**, el proyecto de clase: `api/`, `nucleo/`, `modulos/` y componentes con `data-include`. A partir de ahí añade:

- base de datos real
- usuarios con roles
- ventas con varias líneas, compras a proveedores, control de stock y facturación con rectificativas
- copias de seguridad
- **instalador** (asistente web y por terminal), **módulos activables** con dependencias, **perfiles** por tipo de empresa y **ajustes**
- **actualizaciones** con migraciones y copia automática, **claves de API** para otros programas, **entornos** (desarrollo, pruebas y explotación) y **permisos por módulo** para cada usuario
- **organización y consulta de la información** para trabajar con datos reales: importación desde CSV/JSON, formularios web públicos, NIF/CIF validados, constructor de consultas y consola SQL de solo lectura, informes por periodo con gráfico y exportación, extracciones programadas, pantalla de rendimiento y análisis del negocio con recomendaciones
- diagnóstico del sistema y registro de operaciones
- pruebas automáticas

La interfaz es sobria y mantiene la identidad de [serenaesteve.com](https://serenaesteve.com): logotipo, tipografía DM Sans y acento frambuesa.

- **Autora:** Serena Esteve
- **Versión:** 5.1, 19/10/2026
- **Pila:** Ubuntu 24.04 · Apache 2.4 · PHP 8.3 · SQLite o MySQL · HTML + CSS + JavaScript sin librerías

![Panel](docs/capturas/panel.png)

---

## Acceso

| Rol | Email | Contraseña | Qué puede hacer |
|---|---|---|---|
| Administración | `serena@blush.test` | `blush2026` | Todo: rectificar facturas, borrar, Productos, Compras, Usuarios, Sistema y copias de seguridad |
| Comercial | `pablo@blush.test` | `blush2026` | Ver todo; crear y editar clientes y pedidos; facturar. No puede borrar, rectificar, modificar productos ni compras, ni ver Usuarios o Sistema |

Son las cuentas de la instalación de demostración. En una instalación nueva, el administrador se elige en el asistente; si se marcan los datos de demostración, se crea además el comercial `pablo@blush.test`.

Los permisos se comprueban **en el servidor**; el frontend solo oculta lo que no se puede hacer. Cada usuario cambia su contraseña pulsando su nombre en la barra superior.

## Instalación y configuración (RA2)

### Tipos de instalación

| Tipo | Cómo | Para qué |
|---|---|---|
| **Asistente web** | Abrir la aplicación sin instalar: redirige a `instalar.html` (requisitos → perfil → base de datos → empresa y administrador → confirmar) | Instalación típica, con interfaz gráfica |
| **Desatendida (terminal)** | `php scripts/instalar.php --perfil=tienda --empresa=… --admin-email=… [--demo]` | Servidores sin entorno gráfico, scripts de despliegue y pruebas automáticas |
| **Contenedores** | `docker compose up -d --build` (Apache + PHP + MySQL) | Despliegue reproducible; el asistente se abre con MySQL ya elegido |
| **Entorno de pruebas** | `bash tests/ejecutar.sh` | Instala en carpetas temporales con `php -S`, sin tocar los datos reales |
| **Actualización** | Una base de datos de la v3 sin `instalacion.json` se adopta sola como perfil «Distribuidora» y se migra (tablas y columnas nuevas) | Pasar de versión sin perder datos |

El estado de la instalación se guarda en `datos/instalacion.json` (motor, perfil, fecha y versión; nunca contraseñas de usuarios). Está protegido por `datos/.htaccess`. Mientras no existe, la API responde 503 y solo funcionan `estado`, `requisitos`, `probar-bd` e `instalar`. Una vez instalado, el instalador responde 409.

### Módulos

Cada módulo se describe en `api/modulos/<id>.json` (como el `modulo.json` de clase) con sus pantallas y dependencias:

| Módulo | Pantallas | Necesita |
|---|---|---|
| Núcleo y administración (obligatorio) | Panel, Usuarios, Registro, Módulos, Ajustes, Sistema | — |
| CRM | Clientes (y su ficha) | — |
| Inventario | Productos | — |
| Ventas | Pedidos | CRM, Inventario |
| Facturación | Facturas | Ventas |
| Compras | Proveedores, Compras | Inventario |

En **Administración → Módulos**, activar un módulo activa también los que necesita. No se puede desactivar uno que otro módulo activo necesita, ni el núcleo. Un módulo desactivado desaparece del menú y su API responde 403, pero **sus datos se conservan**.

### Perfiles (supuestos)

| Perfil | Empresa tipo | Módulos | Base de datos recomendada |
|---|---|---|---|
| Consultoría / agencia | Servicios: solo gestiona su cartera de clientes | CRM | SQLite |
| Tienda pequeña | Vende de su almacén y factura; compra poco | CRM, Inventario, Ventas, Facturación | SQLite |
| Distribuidora (ERP completo) | Compra, almacena, vende y factura; varios usuarios | Todos | MySQL |
| Personalizada | Se eligen los módulos uno a uno | A elección (+ dependencias) | — |

Los perfiles están en `instalacion/perfiles/*.json`, y las pruebas automáticas instalan y comprueban los tres.

### Ajustes

En **Administración → Ajustes** (se guardan en la tabla `ajustes`):

- Datos de la empresa: aparecen en las facturas, el menú y el pie.
- IVA y series de facturas y rectificativas: `FV2026-0001`…
- Stock mínimo para el aviso de stock bajo.
- Filas por página.

Los ajustes de un módulo inactivo se ocultan pero se conservan. Cada cambio queda en el Registro.

### Actualizaciones

Cada cambio de estructura de la base de datos es una **migración numerada** en `instalacion/migraciones/NNN-nombre.php` (ver su README). Las aplicadas se anotan en la tabla `migraciones`.

- Si el código es más nuevo que la base de datos, el ERP queda **en mantenimiento** (la API responde 503). Al entrar, un administrador ve la pantalla **Actualizar** con las migraciones pendientes; el resto de usuarios ve «En mantenimiento».
- Antes de actualizar se guarda una **copia automática** en `datos/copias/`: la base de datos SQLite completa, o una exportación JSON en MySQL.
- Con SQLite, todas las migraciones van en una transacción: si una falla, no se aplica nada. Con MySQL el DDL no se puede deshacer, así que se para en la que falla y se indica la copia.
- Probado de verdad: la instalación de Apache pasó de la 4.0 a la 4.1 aplicando 5 migraciones ([antes](docs/capturas/actualizacion-pendiente.png) / [después](docs/capturas/actualizacion-hecha.png)).

### Servicios de acceso: claves de API

En **Administración → Acceso API** se crean claves para otros programas (una tienda online, una hoja de cálculo…):

- Cada clave da acceso a los recursos elegidos, en solo lectura o en lectura y escritura. **Nunca** permite borrar ni administrar.
- La clave (`blush_` + 40 caracteres) **solo se muestra al crearla**. En la base de datos se guarda su huella SHA-256 y en la pantalla solo su prefijo.
- Se puede **revocar** en cualquier momento, y cada uso queda en el Registro como «API · nombre del programa».

```bash
curl -H "X-API-Key: blush_…" "http://localhost/Sistema-de-gesti-n-empresarial/api/api.php?recurso=clientes"
curl -H "X-API-Key: blush_…" -X POST -d '{"nombre":"Cliente desde la web"}' ".../api/api.php?recurso=clientes"
```

### Entornos

Se eligen al instalar y se pueden cambiar en Ajustes. Quedan guardados en `instalacion.json`:

| Entorno | Cabecera | Errores internos | Comprobaciones de Sistema |
|---|---|---|---|
| Desarrollo | Franja e insignia ámbar | Mensaje real con archivo y línea | Normales |
| Pruebas | Franja e insignia azul | Ocultos | Normales |
| Explotación | Sin marca | Ocultos | Sin HTTPS o con `display_errors` → **error**; avisa si quedan cuentas de demostración |

### Permisos por usuario

Al editar un usuario aparece una cuadrícula **módulo × (ver, crear, editar, borrar)**. Si se deja marcado «Usar los permisos por defecto del rol comercial», se aplican los de siempre. Marcar crear, editar o borrar marca también ver. El servidor lo comprueba en cada petición.

### Informe de instalación y reinstalación

- **Informe de instalación** (Markdown): versión, perfil, entorno, motor, módulos, migraciones, requisitos y usuarios. Se descarga al terminar el asistente y desde Sistema.
- **Reinstalar** (Sistema): pide el nombre de la empresa y la contraseña, guarda una copia en `datos/copias/`, retira la instalación y abre de nuevo el asistente.

### Migrar de SQLite a MySQL (supuesto «Distribuidora»)

1. En Sistema, **Descargar copia** (JSON).
2. `sudo mysql < scripts/mysql.sql`: crea la base de datos y el usuario.
3. En Sistema, **Reinstalar**. En el asistente, elegir MySQL (**Probar conexión**), el mismo perfil y sin datos de demostración.
4. En Sistema, **Restaurar** la copia del paso 1: el formato es el mismo para los dos motores.

> ⚠️ No se ha podido probar en el equipo porque falta el usuario MySQL (incidencias I-01 e I-15).

| | | |
|---|---|---|
| ![Perfil](docs/capturas/instalador-2-perfil.png) | ![MySQL](docs/capturas/instalador-3-mysql.png) | ![Módulos](docs/capturas/modulos.png) |
| ![Actualización](docs/capturas/actualizacion-pendiente.png) | ![Clave de API](docs/capturas/api-clave-creada.png) | ![Permisos](docs/capturas/usuario-permisos.png) |

## Organización y consulta de la información (RA3)

Todo lo necesario para la unidad 4, cuando se meten los datos reales de una empresa. Está en dos secciones nuevas del menú: **Análisis** y **Datos**.

| Criterio | Dónde | Qué hay |
|---|---|---|
| a) Consultar y manipular datos | Análisis → **Consultas** | Constructor (datos, columnas, filtros, agrupar con contar/sumar/media/mín./máx., **tabla cruzada**, orden) que enseña el SQL generado · consola SQL de solo lectura (admin) · **Explicar** · consultas guardadas · **fusionar duplicados** |
| b) Formularios | Datos → **Formularios web** · **Importar y exportar** · formularios del ERP | Formularios públicos · importación con vista previa (también **pedidos y compras con líneas**), modo **actualizar** y **deshacer** · NIF/NIE/CIF, **IBAN**, **código postal con provincia** y **teléfono** validados |
| c) Informes | Análisis → **Informes** | 10 informes (incluidos **IVA del trimestre** y **modelo 347**) por día, semana, mes, trimestre, año o fechas a medida, con **filtros**, **comparación** con el periodo anterior o el año pasado, totales y gráfico |
| d) Exportar | Informes, Consultas, Importar y exportar, cada listado | **Excel (.xlsx)** · CSV para Excel (BOM, `;`, decimales con coma, fila TOTAL) · JSON · imprimir / PDF |
| e) Automatizar extracciones | Datos → **Tareas programadas** · `scripts/tareas.php` | Un informe o una **consulta guardada** cada día, lunes o día 1, a Excel/CSV/JSON en `datos/extracciones/` |
| f) Rendimiento | Análisis → **Rendimiento** · `scripts/monitor.php` · `scripts/carga.php` | CPU, memoria, disco y BD; tiempo de cada petición; **consultas lentas con su plan**; prueba de velocidad; prueba de carga |
| g) Documentar operaciones e incidencias | **Incidencias** · **Registro** · tablas de abajo | Registro de **incidencias** (las tareas que fallan abren una sola) · importaciones, exportaciones, fusiones, extracciones y pruebas anotadas |
| h) Información relevante | **Panel** (avisos) · Análisis → **Análisis** | **Avisos** del día en el panel · tendencia y **previsión de ventas**, **segmentos de clientes (RFM)**, concentración, clientes en riesgo, cobertura de stock, márgenes, pendiente de facturar, calidad de los datos y recomendaciones |

![Informes](docs/capturas/informes.png)

![Análisis](docs/capturas/analisis.png)

![Previsión y segmentos](docs/capturas/analisis-prevision.png)

### Informes

| Informe | Para qué sirve |
|---|---|
| Ventas por periodo | Pedidos, unidades, importe y ticket medio por día, semana, mes o año (también los periodos sin ventas) |
| Ventas por cliente · por producto | Quién compra y qué se vende más, con su % del total |
| Libro de facturas emitidas | Base, IVA y total de cada factura y rectificativa: lo que pide la gestoría |
| Pedidos pendientes | Pendientes y enviados, con los días que llevan esperando |
| Clientes sin compras recientes | Más de N días sin pedir: a quién llamar |
| Inventario valorado | Stock a precio de venta y a coste medio de compra |
| Compras por proveedor | Importe recibido de cada proveedor |
| IVA del trimestre | IVA repercutido por tipo (y rectificativas), soportado en compras (estimado con el IVA general) y resultado: el resumen del modelo 303 |
| Operaciones con terceros (347) | Clientes y proveedores con más de 3.005,06 € al año (IVA incluido), por trimestres |

Los de ventas y compras se pueden **filtrar** (cliente, producto, categoría, proveedor, estado) y **comparar** con el periodo anterior o con el mismo periodo del año pasado (columna «anterior» y variación en %).

Se ven en pantalla, se imprimen (hoja de estilos de impresión) y se descargan en Excel, CSV o JSON con la misma dirección: `api/api.php?recurso=informes&informe=libro_facturas&desde=2026-07-01&hasta=2026-09-30&formato=xlsx`.

### Consultas

- **Constructor**: solo usa columnas de una lista blanca sacada del esquema y valores parametrizados. Muestra el SQL para aprender a escribirlo.
- **Consola SQL** (solo administración): una sentencia `SELECT`/`WITH`, sin `;` ni comentarios, sin las tablas `usuarios` y `claves_api`, y en una conexión aparte en **modo solo lectura del propio motor** (`PRAGMA query_only = ON` en SQLite, transacción `READ ONLY` en MySQL). Aunque una comprobación fallara, el motor no dejaría escribir.
- **Tabla cruzada**: agrupar por dos campos (por ejemplo, clientes × meses de la fecha), hasta 24 columnas y su total.
- **Explicar**: enseña el plan de la base de datos (`EXPLAIN QUERY PLAN` / `EXPLAIN`) y dice en palabras si usa índices o recorre la tabla entera.
- Hasta 1.000 filas. Las consultas se guardan para repetirlas, exportarlas o programarlas como tarea.

### Datos reales: importar y formularios

- **Importar** clientes, productos o proveedores desde la hoja de Excel de la empresa: plantilla descargable, columnas reconocidas por nombre o etiqueta (sin importar tildes ni mayúsculas), `;` `,` o tabulador, UTF-8 o Windows-1252, decimales con coma y fechas `15/03/2026`. Primero se ve una **vista previa** con los errores de cada fila y los posibles repetidos (por NIF, email o nombre); después se importan solo las filas correctas, en una transacción.
- **Formularios web**: `formulario.html?f=pide-presupuesto` (o incrustado con `<iframe>`). Crean el registro con la misma validación que el alta a mano; un cliente entra como «potencial». Tienen un campo trampa para robots y un límite de 5 envíos por hora, origen y formulario. No se guarda la IP, solo una huella.
- **Pedidos y compras** también se importan: una fila por línea con una columna «Referencia»; el cliente o proveedor se busca por nombre o NIF y el producto por nombre. Son historial y no cambian el stock.
- Los **repetidos** se pueden omitir, **actualizar** (solo las columnas que trae el archivo) o importar igualmente, y **cada importación se puede deshacer** desde su historial.
- **Duplicados**: Análisis → Calidad de los datos → «Revisar» agrupa los clientes o proveedores repetidos y los **fusiona** (sus pedidos pasan al que se queda).
- **NIF, NIE y CIF**, **IBAN** (dígitos de control), **código postal** (con su provincia, que se rellena sola) y **teléfono** se comprueban y se guardan normalizados.

### Automatizar: tareas programadas

```bash
crontab -e
*/15 * * * * php /var/www/html/Sistema-de-gesti-n-empresarial/scripts/tareas.php >> /var/log/blush-tareas.log 2>&1
* * * * *    php /var/www/html/Sistema-de-gesti-n-empresarial/scripts/monitor.php > /dev/null 2>&1
```

`php scripts/tareas.php --todas` las ejecuta todas para probar. Cada ejecución (también las del botón «Ejecutar») se anota con sus filas, milisegundos y archivo; si falla, aparece como **incidencia** en el Registro. Se conservan los 30 últimos archivos de cada tarea.

### Rendimiento y prueba de carga

```bash
php scripts/carga.php                              # 2.000 clientes y 20.000 pedidos en una BD temporal
php scripts/carga.php --clientes=500 --pedidos=3000
```

Resultado en este equipo (SQLite, PHP 8.3, mediana de 3 repeticiones):

| Prueba | 3.000 pedidos | 20.000 pedidos (≈ 50.000 líneas) |
|---|---:|---:|
| Listado de clientes / pedidos (página 1) | < 1 ms | 0,1 / 1,0 ms |
| Búsqueda de clientes | 0,3 ms | 0,7 ms |
| Panel de inicio | 8,9 ms | 113 ms |
| Informes de ventas (por periodo, cliente, producto) | 4–6 ms | 54–71 ms |
| Análisis | 286 ms → **19 ms** con la migración 010 | 225 ms |

En la 5.1, con 20.000 pedidos: análisis completo (con previsión y segmentos) 297 ms, avisos del panel 64 ms, tabla cruzada clientes × meses 25 ms e informe comparado con el año pasado 41 ms.

Las consultas que pasan de 500 ms (informes, constructor, consola y listados) se guardan con su SQL y se ven en Rendimiento, con el botón **Explicar**.

La primera medida del análisis (286 ms con solo 3.000 pedidos) llevó a la **migración 010**, que añade índices en `pedido_lineas.producto_id`, `compra_lineas.producto_id` y `pedidos(cliente_id, fecha)`.

## Qué hace

| Sección | Módulo | Para qué sirve |
|---|---|---|
| | **Panel** | Ventas del periodo elegido (mes, 90 días, año, todo), pedidos pendientes, pedidos por facturar, compras en curso, ventas por mes y por producto, últimos pedidos y stock bajo con botón **Reponer** |
| Ventas | **Clientes** | CRM con estado *potencial / activo / inactivo*. El nombre abre su **ficha**: datos, total comprado, ticket medio e historial |
| | **Pedidos** | **Varias líneas** por pedido. Cada línea guarda el precio del momento de la venta y **descuenta stock** (no deja vender sin existencias). Los entregados se **facturan** con un clic y quedan bloqueados |
| | **Facturas** | Numeración correlativa por año (`F2026-0001`), IVA, una línea por producto y **hoja A4 imprimible**. Una factura emitida no se borra: se anula con una **factura rectificativa** (`R2026-0001`, importes en negativo y motivo) |
| Compras | **Productos** | Catálogo con precio, stock y aviso de stock bajo |
| | **Proveedores** | Datos de contacto |
| | **Compras** | Pedidos a proveedores con varias líneas y coste. Al marcarlas como **recibidas**, el stock sube |
| Administración | **Usuarios** | Contraseñas cifradas (`password_hash`) y rol |
| | **Registro** | Altas, ediciones, borrados, facturas, rectificativas, copias y accesos, con **quién** lo hizo. Exportable a `.md` |
| | **Sistema** | Diagnóstico en directo de SO, Apache, PHP, extensiones, límites, base de datos y seguridad, y **copias de seguridad** (descargar y restaurar en JSON, válidas para SQLite y MySQL) |

En todas las tablas: buscador (atajo `/`), filtro por fechas, **ordenación y paginación en el servidor** y **exportación a CSV** para Excel. Modo claro/oscuro y diseño adaptado a móvil.

### Relación con los criterios del RA1

| Criterio | Dónde se trabaja |
|---|---|
| a, b, c) ERP-CRM del mercado, licencias, comparativa | Esta aplicación implementa los módulos típicos de un ERP-CRM (CRM, ventas, facturación, compras, inventario) para entender cómo funcionan |
| d) SO adecuado | Vista **Sistema** → *Sistema operativo*; `Dockerfile` |
| e) SGBD adecuado | La misma aplicación funciona con **SQLite y MySQL** (`api/config.php`, `docker-compose.yml`) |
| f) Verificar configuraciones | Vista **Sistema** (39 comprobaciones), `scripts/configurar-servidor.sh`, capturas [antes](docs/capturas/sistema-antes.png) / después |
| g) Documentar operaciones | Vista **Registro** (automático, con usuario) + [Operaciones realizadas](#operaciones-realizadas) |
| h) Documentar incidencias | [Incidencias](#incidencias) |

---

## Mejoras respecto a Crimson (versión de clase)

| | Crimson 0.2 | Blush 3.0 |
|---|---|---|
| Datos | JSON escrito a mano en `api.php` | SQLite o MySQL; tablas creadas y migradas automáticamente |
| Operaciones | Solo lectura | CRUD completo con líneas, paginación, ordenación, filtros y CSV |
| Lógica de negocio | — | Stock, precio guardado, compras, facturación con IVA, rectificativas |
| Usuarios | Nombre fijo en el HTML | Inicio de sesión, contraseñas cifradas y roles |
| Módulos | 3 entradas de menú que muestran lo mismo | 11 vistas; un módulo nuevo se añade solo en `api/esquema.php` |
| Seguridad | `$_GET['bloque']` sin comprobar | Consultas preparadas, listas blancas, sin XSS, cookie `HttpOnly` + `SameSite`, cabecera anti-CSRF, BD bloqueada |
| Integridad | — | Claves foráneas y transacciones |
| Instalación | Instalador que crea las tablas desde un modelo | Asistente web, instalación desatendida, perfiles, módulos con dependencias y ajustes |
| Actualización y acceso | — | Migraciones con copia automática, claves de API, entornos y permisos por usuario |
| Pruebas | — | 79 pruebas: 34 de instalación y configuración, 27 de API y 18 en navegador |
| Despliegue | Copiar a `/var/www/html` | Apache, o `docker compose up` con MySQL |

---

## Instalación

### Con Apache (como en clase)

```bash
cd /var/www/html
git clone git@github.com:serenaesteve/Sistema-de-gesti-n-empresarial.git
cd Sistema-de-gesti-n-empresarial
sudo chown www-data:www-data datos          # Apache tiene que poder escribir la BD (en clase: chmod 777 datos)
sudo bash scripts/configurar-servidor.sh    # opcional: gd, intl, límites de php.ini y zona horaria
```

Abrir `http://localhost/Sistema-de-gesti-n-empresarial/`: la primera vez se abre el **asistente de instalación**. Marcando «Cargar datos de demostración» se crean seis meses de datos de ejemplo de los módulos elegidos.

**Requisitos:** PHP ≥ 8.1 con `pdo_sqlite` (o `pdo_mysql`), `json` y `mbstring`, y Apache con `AllowOverride All` para que se aplique `datos/.htaccess`.

**Con MySQL:** crear la base de datos y el usuario con `sudo mysql < scripts/mysql.sql` y elegir MySQL en el asistente. El botón **Probar conexión** comprueba los datos antes de instalar.

**Sin navegador:** `php scripts/instalar.php --ayuda` muestra todas las opciones de la instalación desatendida.

### Con Docker (Apache + PHP + MySQL)

```bash
docker compose up -d --build                # → http://localhost:8080
```

La imagen ya trae las extensiones y la configuración de `php.ini` recomendadas.

> ⚠️ Los archivos de Docker no se han podido probar en el equipo de desarrollo, porque el usuario no tiene permiso sobre `docker.sock`. Ver incidencia I-11.

### Compatibilidad SQLite / MySQL

El código tiene en cuenta las diferencias entre los dos motores:

- `AUTO_INCREMENT` frente a `AUTOINCREMENT`.
- `SHOW COLUMNS` frente a `PRAGMA table_info`.
- `time_zone = '+00:00'` para que las fechas coincidan con SQLite.
- `LIMIT` con enteros ya validados, porque con prepares emulados MySQL los pondría entre comillas.
- Tablas de líneas creadas después de las tablas a las que referencian, porque InnoDB no admite claves foráneas a tablas que aún no existen.

## Pruebas automáticas

```bash
bash tests/ejecutar.sh                      # 34 de instalación + 54 de API + 3 de scripts + 32 en navegador (si Playwright está instalado)
SOLO_API=1 bash tests/ejecutar.sh           # solo API (PHP + curl, sin dependencias)
CAPTURAS=docs/capturas bash tests/ejecutar.sh   # además, regenera las capturas de la documentación
```

Arrancan su propio servidor (`php -S`) con una **base de datos temporal**, así que no tocan los datos reales ni necesitan Apache. Cubren:

- sesiones, permisos y la protección CSRF
- validación
- stock con varias líneas y transacciones deshechas
- precio guardado en el pedido
- compras
- facturación y rectificativas
- paginación, filtros y CSV
- usuarios y cambio de contraseña
- copias de seguridad
- registro y diagnóstico
- RA3: NIF/CIF, informes y sus totales, CSV/JSON, constructor y consola SQL (intentos de escribir o leer tablas protegidas), consultas guardadas, importación con errores y repetidos, formularios públicos (validación, robots, límite, pausa), tareas y su archivo, rendimiento, análisis y los scripts de cron

---

## Estructura

```
├── index.html · factura.html       Aplicación y factura imprimible
├── formulario.html                 Formulario web público (RA3)
├── instalar.html                   Asistente de instalación
├── api/
│   ├── api.php                     Punto de entrada, sesión y enrutado
│   ├── config.php                  Motor de BD, empresa emisora, IVA (también por variables de entorno)
│   ├── esquema.php                 Módulos, campos, líneas y permisos (fuente única)
│   ├── instalacion.php             Estado, requisitos, instalador, módulos, ajustes, entornos, informe y reinstalación
│   ├── migraciones.php             Migraciones pendientes, copia automática y actualización
│   ├── claves.php                  Claves de API (servicios de acceso)
│   ├── modulos/*.json              Catálogo de módulos (pantallas y dependencias)
│   ├── nucleo.php                  Respuestas y errores comunes (API y terminal)
│   ├── auth.php                    Inicio de sesión, roles y permisos (según módulos activos)
│   ├── bd.php                      Conexión, tablas, migraciones y datos de demostración
│   ├── crud.php                    Listar / paginar / filtrar / CSV / crear / editar / borrar, con líneas
│   ├── negocio.php                 Stock, precios, compras, facturación y rectificativas
│   ├── copia.php                   Copias de seguridad y cambio de contraseña
│   ├── panel.php · sistema.php     Indicadores y diagnóstico
│   ├── informes.php                Catálogo de informes, periodos y exportación CSV/JSON (RA3)
│   ├── consultas.php               Constructor, consola SQL de solo lectura y consultas guardadas (RA3)
│   ├── intercambio.php             Importación CSV/JSON con vista previa y plantillas (RA3)
│   ├── formularios.php             Formularios web públicos (RA3)
│   ├── tareas.php                  Extracciones programadas (RA3)
│   ├── rendimiento.php             Métricas del servidor, tiempos de la API y prueba de velocidad (RA3)
│   ├── analisis.php                Tendencia, Pareto, riesgo, cobertura, márgenes y recomendaciones (RA3)
├── nucleo/                         Componentes HTML, estilos y JS comunes
├── modulos/                        Vistas: acceso, panel, crud (genérica), ficha-cliente, registro, sistema, módulos, ajustes,
│                                   claves-api, actualizar, reinstalar, mi-clave · informes, analisis, consultas,
│                                   rendimiento, intercambio, formularios, tareas (RA3)
├── instalacion/perfiles/*.json     Perfiles de instalación (consultoría, tienda, distribuidora)
├── instalacion/migraciones/*.php   Migraciones numeradas (001 … 014)
├── tests/                          marco.php, instalacion.php, api.php, navegador.py y ejecutar.sh
├── scripts/                        instalar.php (instalación desatendida), tareas.php y monitor.php (cron), carga.php
│                                   (prueba de carga), configurar-servidor.sh y mysql.sql
├── Dockerfile · docker-compose.yml
├── docs/capturas/                  Capturas y facturas de ejemplo
└── datos/                          erp.sqlite, instalacion.json, copias/ y extracciones/ (no se suben a git) + .htaccess
```

## API

| Método | `api/api.php?recurso=…` | Acción |
|---|---|---|
| GET | `estado` | ¿Está instalado? |
| GET · POST | `requisitos` · `probar-bd` · `instalar` | Asistente de instalación (solo sin instalar) |
| GET · POST | `modulos[&modulo=compras]` · `ajustes` | Módulos `{activo}` y ajustes, incluido el `entorno` (admin) |
| GET · POST | `actualizar` | Migraciones pendientes · actualizar (admin; solo en mantenimiento) |
| GET · POST · DELETE | `claves[&id=3]` | Claves de API: listar · crear · revocar (admin) |
| GET · POST | `informe` · `reinstalar` | Informe de instalación · reinstalar `{confirmacion, clave}` (admin) |
| Cualquiera | con cabecera `X-API-Key` | Recursos de datos permitidos por la clave, sin sesión |
| GET · POST | `sesion` · `entrar` · `salir` · `mi-clave` | Sesión y contraseña |
| GET | `pedidos&q=&desde=&hasta=&orden=&dir=&pagina=` | Listado (`formato=csv` para descargar; `cliente_id=3` para filtrar) |
| GET | `pedidos&id=3` | Un registro con sus `lineas` |
| POST · PUT · DELETE | `pedidos[&id=3]` | Alta · edición · borrado (cuerpo JSON con `lineas: [{producto_id, cantidad}]`) |
| POST | `facturar&id=5` · `rectificar&id=7` | Facturar un pedido · rectificar una factura `{motivo}` |
| GET · POST | `copia` · `restaurar` | Copia de seguridad en JSON |
| GET | `menu` · `esquema` · `info` · `panel&periodo=` · `registro` · `sistema` | Datos de la interfaz |
| GET | `informes[&informe=…&desde=&hasta=&agrupacion=&dias=&formato=csv\|json]` | Lista de informes · un informe (RA3) |
| GET | `analisis` | Análisis del negocio (RA3) |
| GET · POST · DELETE | `consultas[&definicion={…}\|&sql=…\|&guardada=3][&formato=]` | Constructor · consola SQL (admin) · guardar · borrar (RA3) |
| GET · POST | `intercambio[&plantilla=clientes]` · `intercambio&accion=analizar\|importar&destino=clientes` | Importar `{contenido, formato, nombre, omitir_duplicados}` (RA3) |
| GET · POST · PUT · DELETE | `formularios[&id=]` | Formularios web (admin, RA3) |
| GET · POST | `formulario-publico&f=slug` | Formulario público, sin sesión ni `X-Blush` (RA3) |
| GET · POST · PUT · DELETE | `tareas[&id=][&accion=ejecutar][&descarga=7]` | Tareas programadas (admin, RA3) |
| GET · POST | `rendimiento&horas=24` · `rendimiento&accion=muestra\|medir` | Rendimiento (admin, RA3) |

Los listados aceptan también `formato=json`. Las escrituras exigen la cabecera `X-Blush: 1` (salvo el formulario público). Respuestas: `{"ok": true, "datos": …}` o `{"ok": false, "error": "…", "errores": {"lineas.1.cantidad": "…"}}` con su código HTTP (400, 401, 403, 404, 405, 409, 422, 500, 503).

---

## Operaciones realizadas

| Nº | Operación | Comando / acción | Resultado |
|---|---|---|---|
| 1 | Analizar el proyecto de clase | Lectura de `011-Crimson` | Se mantiene su arquitectura |
| 2 | Comprobar el SO y el software | `cat /etc/os-release`, `apache2 -v`, `php -v`, `mysql --version` | Ubuntu 24.04.3, Apache 2.4.58, PHP 8.3.6, MySQL 8.0.46 |
| 3 | Comprobar los drivers de BD | `php -m \| grep -i pdo` | `pdo_mysql` y `pdo_sqlite` |
| 4 | Crear la estructura y dar permisos | `chmod 777 datos` | Apache puede crear `erp.sqlite` |
| 5 | Comprobar que la BD no se descarga | `curl -I …/datos/erp.sqlite` con y sin `.htaccess` | 200 sin `.htaccess` → **403** con él |
| 6 | v1: API + interfaz | `curl` y Playwright | Correcto |
| 7 | v2: usuarios, stock, facturas, CSV | `curl` y Playwright | Correcto |
| 8 | v2.1: rediseño profesional | Capturas en claro, oscuro y móvil | Correcto |
| 9 | v3: pedidos con líneas, compras, rectificativas, copias, contraseña, filtros | `bash tests/ejecutar.sh` | **27/27 API · 12/12 navegador** |
| 10 | Captura de Sistema «antes» (Apache) | [sistema-antes.png](docs/capturas/sistema-antes.png) | 0 errores · **8 avisos** |
| 11 | Preparar scripts de servidor y Docker | `scripts/`, `Dockerfile`, `docker-compose.yml` | Pendientes de ejecutar (requieren `sudo` / Docker) |
| 12 | **RA2:** estudiar Crimson 012–037 (instalador, `modulo.json`, presets, opciones) | Repositorio de clase | Mismas ideas, aplicadas sobre mi ERP |
| 13 | Catálogo de módulos y perfiles | `api/modulos/*.json`, `instalacion/perfiles/*.json` | 6 módulos con dependencias y 3 perfiles |
| 14 | Instalador web y por terminal | `instalar.html`, `scripts/instalar.php` | Instalan; repetir da 409; datos erróneos dan 422 campo a campo |
| 15 | Adoptar la instalación existente de Apache | Abrir el ERP con la v4 | `datos/instalacion.json` creado como «Distribuidora (adoptada desde la v3.0)»; datos intactos |
| 16 | Supuestos | `tests/instalacion.php` instala los 3 perfiles | Cada uno muestra solo sus módulos |
| 17 | Verificación completa | `bash tests/ejecutar.sh` | **61/61** (19 instalación · 27 API · 15 navegador) |
| 18 | Verificación en Apache | Vista Sistema | 37 correctas · 9 avisos · 0 errores; `instalacion.json` → 403 |
| 19 | Migraciones numeradas y pantalla Actualizar | `instalacion/migraciones/`, `api/migraciones.php` | Prueba automática: BD «4.0» simulada → 4 migraciones, copia creada, 503 mientras tanto |
| 20 | **Actualización real** de la instalación de Apache | Navegador: Actualizar ahora | 4.0 → 4.1, 5 migraciones, copia `datos/copias/erp-…-antes-de-actualizar.sqlite` (403 desde fuera) |
| 21 | Claves de API | Acceso API + `curl -H "X-API-Key: …"` | Lectura con clave; escritura solo si se permite; borrar → 403; revocada → 401 |
| 22 | Entornos y permisos por usuario | Ajustes y Usuarios | Insignia por entorno; en explotación, sin HTTPS → error; cuadrícula de permisos aplicada por el servidor |
| 23 | Informe y reinstalación | Sistema | Informe en Markdown; reinstalar pide confirmación, guarda copia y vuelve al asistente |
| 24 | Verificación completa | `bash tests/ejecutar.sh` (dos veces) | **79/79** (34 instalación · 27 API · 18 navegador) |
| 25 | Verificación en Apache tras actualizar | Vista Sistema | 40 correctas · 9 avisos · 0 errores |
| 26 | **RA3:** estudiar `003-Organización y consulta de la información` del repositorio de clase | Formularios, informes por periodo, gráficos SVG, monitor con psutil, intercambio CSV/JSON y BI | Mismas ideas, aplicadas a mi ERP y a sus datos |
| 27 | Validación de NIF/NIE/CIF y NIF válidos en la demo | Tipo de campo `nif` | 422 con la letra mal; se guarda normalizado |
| 28 | Informes, consultas, importación, formularios, tareas, rendimiento y análisis | Migraciones 006–010 | Probado con `bash tests/ejecutar.sh` |
| 29 | Prueba de carga | `php scripts/carga.php` | Análisis 286 ms con 3.000 pedidos → migración 010 con índices → 19 ms; con 20.000 pedidos todo por debajo de 250 ms |
| 30 | Scripts de cron | `php scripts/tareas.php --todas` · `php scripts/monitor.php` | Archivo en `datos/extracciones/` y muestra en `metricas` |
| 31 | Verificación completa | `bash tests/ejecutar.sh` | **104/104** (34 instalación · 41 API · 3 scripts · 26 navegador) |
| 33 | **v5.1:** pedidos y compras con líneas, modo actualizar, deshacer, duplicados, CP/IBAN/teléfono, filtros y comparación, IVA y 347, tabla cruzada, Excel, tareas con consultas, avisos, consultas lentas, incidencias, previsión y RFM | Migraciones 011–014 | **123/123** (34 instalación · 54 API · 3 scripts · 32 navegador) |
| 34 | Prueba de carga 5.1 | `php scripts/carga.php` | Todo por debajo de 300 ms con 20.000 pedidos |
| 35 | Actualización real de Apache a la 5.1 | Navegador: Actualizar ahora | 5.0 → 5.1, 4 migraciones, copia `datos/copias/erp-20261019-144546-antes-de-actualizar.sqlite`; Sistema: 50 correctas · 9 avisos · 0 errores; el panel avisa de los 7 clientes y 3 proveedores antiguos con NIF inventado |
| 32 | **Actualización real** de la instalación de Apache a la 5.0 | Navegador: Actualizar ahora | 4.1 → 5.0, 5 migraciones (006–010), copia `datos/copias/erp-20261019-130737-antes-de-actualizar.sqlite`; Sistema: 47 correctas · 9 avisos · 0 errores; `datos/extracciones/` → 403 |

## Incidencias

| Nº | Incidencia | Causa | Solución | Estado |
|---|---|---|---|---|
| I-01 | `Access denied for user 'serena'@'localhost'` en MySQL | En Ubuntu, `root` de MySQL entra por `auth_socket` y no había usuario para la app | SQLite mientras tanto; `scripts/mysql.sql` crea un usuario con permisos solo sobre `blush_erp` | ⏳ Pendiente de `sudo` |
| I-02 | Apache no podría crear la BD (detectado antes de instalar) | `datos/` pertenece a `serena`; Apache escribe como `www-data` | `chmod 777 datos` (producción: `chown www-data`). La API y Sistema lo avisan | ✅ Prevenida |
| I-03 | `datos/erp.sqlite` se podía descargar (HTTP 200) | Está dentro de `/var/www/html` | `datos/.htaccess` (`000-default.conf` tiene `AllowOverride All`). Verificado: 403; Sistema lo comprueba en cada visita | ✅ Resuelta |
| I-04 | `date.timezone` sin definir: PHP usa UTC | Valor por defecto desde PHP 8.2 | La app fija `Europe/Madrid`; el script y el `Dockerfile` lo definen en `php.ini` | ✅ App · ⏳ `php.ini` |
| I-05 | La API devolvía las horas 2 h atrasadas | SQLite guarda `CURRENT_TIMESTAMP` en UTC | El frontend las interpreta como UTC; en MySQL `time_zone = '+00:00'` | ✅ Resuelta |
| I-06 | Faltan `gd` e `intl` | No vienen con PHP por defecto | `scripts/configurar-servidor.sh` / `Dockerfile` | ⏳ Pendiente de `sudo` |
| I-07 | Límites bajos en el `php.ini` de Apache | Valores por defecto de Ubuntu (el `php.ini` de la terminal es otro) | `scripts/configurar-servidor.sh` (con copia de seguridad) | ⏳ Pendiente de `sudo` |
| I-08 | En la v1, cambiar el precio de un producto cambiaba los pedidos antiguos | El total usaba el precio actual del catálogo | Precio guardado en cada línea del pedido | ✅ Resuelta |
| I-09 | Riesgo de operaciones a medias | Varias consultas por operación | Transacciones con `rollBack()`; probado: un pedido sin stock no altera nada | ✅ Resuelta |
| I-10 | La BD de la v2 (un producto por pedido) no encaja con la v3 (varias líneas) | Cambio de estructura | La v3 guardaba la BD antigua como `erp-v2-FECHA.sqlite`; desde la v4 la app la detecta y pide una instalación nueva | ✅ Resuelta |
| I-11 | No se puede probar Docker en el equipo | El usuario no tiene permiso sobre `/var/run/docker.sock` y falta el plugin `compose` | `sudo usermod -aG docker $USER` y `sudo apt install docker-compose-plugin` | ⏳ Pendiente de `sudo` |
| I-13 | El ERP ya instalado en Apache (v3) no tenía `instalacion.json` y el asistente lo habría tratado como no instalado | La v3 no tenía instalador | Una BD existente sin `instalacion.json` se adopta automáticamente como «Distribuidora» con todos los módulos | ✅ Resuelta |
| I-14 | Un ERP instalado desde la terminal (usuario `serena`) no podría escribir desde Apache (`www-data`) | Los archivos los crea el usuario que ejecuta el script | El instalador da permisos `0666` a la BD y `0644` a `instalacion.json` (en producción: `chown www-data`) | ✅ Prevenida |
| I-15 | La instalación con MySQL no se ha podido completar en el equipo | Sigue sin existir el usuario MySQL (I-01) | El asistente lo detecta con **Probar conexión** y muestra el error real (`Access denied…`) antes de instalar | ⏳ Pendiente de `sudo` |
| I-16 | Las copias de seguridad de la v3 no se pueden restaurar en la v4 | La v4 añade la tabla `ajustes` (esquema 4) | La restauración comprueba la versión del esquema y rechaza la copia sin tocar nada (422) | ✅ Documentada |
| I-17 | Reinstalar fallaba con «cannot VACUUM - SQL statements in progress» | La copia (`VACUUM INTO`) no puede hacerse con una consulta abierta (la de comprobar la contraseña). Se localizó gracias al entorno de desarrollo, que muestra el error real | Cerrar la consulta (`closeCursor()`) antes de la copia | ✅ Resuelta |
| I-18 | Una prueba del navegador fallaba solo al hacer capturas | Tras guardar Ajustes la página se recarga a los 700 ms; con la pausa de las capturas, la recarga caía en la prueba siguiente | Las pruebas esperan a la navegación (`expect_navigation`) | ✅ Resuelta |
| I-19 | Migrar la instalación de SQLite a MySQL no se ha podido probar | Falta el usuario MySQL (I-01) | Procedimiento documentado (copia → reinstalar con MySQL → restaurar) | ⏳ Pendiente de `sudo` |
| I-20 | Los datos de demostración tenían NIF inventados que ahora no pasan la validación | Antes el NIF era texto libre | NIF válidos en la demo. En la instalación de Apache (datos antiguos) un cliente con NIF inválido pide corregirlo al editarlo, y **Análisis → Calidad de los datos** los señala | ✅ Resuelta · ⚠️ datos antiguos |
| I-21 | `'#' \|\| id` solo funciona en SQLite | MySQL concatena con `CONCAT()` | El número de pedido se compone en PHP, igual en los dos motores | ✅ Resuelta |
| I-22 | La parte FROM del constructor se cortaba mal | Se buscaba « FROM » en la consulta, y las columnas calculadas tienen subconsultas con su propio FROM | `consultaBase()` devuelve la parte FROM por separado | ✅ Resuelta |
| I-23 | Todas las pruebas del navegador fallaban por tiempo de espera | Un paréntesis de más en `modulos/consultas.js` impedía cargar la aplicación (`Unexpected token ')'`) | Corregido; ahora se comprueba que todos los módulos JS cargan | ✅ Resuelta |
| I-24 | El límite de envíos de los formularios web contaba todos los formularios juntos | La huella del origen era la misma para todos | Límite por origen **y** formulario | ✅ Resuelta |
| I-25 | El análisis tardaba 286 ms con solo 3.000 pedidos | Subconsultas por producto sin índice en `producto_id` | Migración 010 con índices: 19 ms | ✅ Resuelta |
| I-26 | Las tareas y el monitor no se ejecutan solos en este equipo | Hay que añadir las líneas a `crontab` del usuario del servidor | Líneas documentadas arriba y en las pantallas Tareas y Rendimiento; se han probado a mano | ⏳ Pendiente de activar |
| I-27 | Al importar pedidos, las filas que continuaban un pedido daban «cliente obligatorio» | Solo la primera fila de cada referencia trae el cliente | Las siguientes filas heredan la cabecera vacía de la primera | ✅ Resuelta |
| I-28 | El «% del total» de los informes salía vacío | Se rellenaba la columna con `null` antes de calcularlo y el valor calculado no la sustituía | Se calcula después y sustituye al vacío | ✅ Resuelta |
| I-29 | Las consultas lentas de los listados no se podían explicar | Los listados usan parámetros con nombre (`:q0`) y se guardaban como lista | Se guardan con su nombre | ✅ Resuelta |
| I-12 | Con `php -S` (pruebas), Sistema marca la BD como descargable | El servidor integrado de PHP no aplica `.htaccess` | Correcto: el aviso es real en ese servidor; las pruebas usan una BD temporal fuera del proyecto | ✅ Documentada |
