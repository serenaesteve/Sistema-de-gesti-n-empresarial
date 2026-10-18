# serena | blush · ERP-CRM

Mini ERP-CRM hecho para los **RA1 y RA2 de 0491 · Sistemas de gestión empresarial** (DAM2).
Parte de la estructura de **Crimson**, el proyecto de clase: `api/`, `nucleo/`, `modulos/` y componentes con `data-include`. A partir de ahí añade:

- base de datos real
- usuarios con roles
- ventas con varias líneas, compras a proveedores, control de stock y facturación con rectificativas
- copias de seguridad
- **instalador** (asistente web y por terminal), **módulos activables** con dependencias, **perfiles** por tipo de empresa y **ajustes**
- **actualizaciones** con migraciones y copia automática, **claves de API** para otros programas, **entornos** (desarrollo, pruebas y explotación) y **permisos por módulo** para cada usuario
- diagnóstico del sistema y registro de operaciones
- pruebas automáticas

La interfaz es sobria y mantiene la identidad de [serenaesteve.com](https://serenaesteve.com): logotipo, tipografía DM Sans y acento frambuesa.

- **Autora:** Serena Esteve
- **Versión:** 4.1, 18/10/2026
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
bash tests/ejecutar.sh                      # 34 de instalación + 27 de API + 18 en navegador (si Playwright está instalado)
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

---

## Estructura

```
├── index.html · factura.html       Aplicación y factura imprimible
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
├── nucleo/                         Componentes HTML, estilos y JS comunes
├── modulos/                        Vistas: acceso, panel, crud (genérica), ficha-cliente, registro, sistema, módulos, ajustes,
│                                   claves-api, actualizar, reinstalar, mi-clave
├── instalacion/perfiles/*.json     Perfiles de instalación (consultoría, tienda, distribuidora)
├── instalacion/migraciones/*.php   Migraciones numeradas (001 … 005)
├── tests/                          marco.php, instalacion.php, api.php, navegador.py y ejecutar.sh
├── scripts/                        instalar.php (instalación desatendida), configurar-servidor.sh y mysql.sql
├── Dockerfile · docker-compose.yml
├── docs/capturas/                  Capturas y facturas de ejemplo
└── datos/                          erp.sqlite, instalacion.json y copias/ (no se suben a git) + .htaccess
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

Las escrituras exigen la cabecera `X-Blush: 1`. Respuestas: `{"ok": true, "datos": …}` o `{"ok": false, "error": "…", "errores": {"lineas.1.cantidad": "…"}}` con su código HTTP (400, 401, 403, 404, 405, 409, 422, 500, 503).

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
| I-12 | Con `php -S` (pruebas), Sistema marca la BD como descargable | El servidor integrado de PHP no aplica `.htaccess` | Correcto: el aviso es real en ese servidor; las pruebas usan una BD temporal fuera del proyecto | ✅ Documentada |
