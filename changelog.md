# Changelog — serena | blush

Registro de cambios del proyecto. Parte de **Crimson ERP 0.2** (versión de clase).

---

## [5.1] — 2026-10-19 · RA3: mejoras para los datos reales

### Meter datos reales
* **Importar pedidos y compras con sus líneas**: una fila por línea; las filas con la misma «Referencia» forman un solo registro y las siguientes heredan cliente, fecha y estado. El cliente o proveedor se busca por nombre o NIF y el producto por nombre; el precio, si no viene, es el del catálogo. Son historial: no cambian el stock.
* **Modo de importación** para los repetidos (mismo NIF, email o nombre): no importarlos, **actualizar** los que ya existen (solo las columnas que trae el archivo) o importarlos igualmente.
* **Deshacer una importación** desde el historial: se borra lo creado y lo actualizado vuelve a como estaba. Si algo ya se usa (por ejemplo, un pedido de un cliente importado), no se toca nada (409). Tabla `importaciones` (migración 011).
* **Fusionar duplicados** desde Análisis → Calidad de los datos: se elige cuál se queda; pedidos, compras y facturas pasan a él y sus datos vacíos se completan con los del otro.
* **Código postal** (con la **provincia** calculada sola), **IBAN** con dígitos de control y **teléfono** (español o con prefijo internacional) en clientes y proveedores (migración 012).

### Informes y consultas
* **Comparar** con el periodo anterior o con el mismo periodo del año pasado: columna del valor anterior, variación en % y total comparado.
* **Filtros** en los informes: cliente, producto, categoría, proveedor y estado.
* **Informes fiscales**: IVA del trimestre (repercutido por tipo, rectificativas, soportado estimado y resultado, para el modelo 303) y **modelo 347** (clientes y proveedores de más de 3.005,06 € al año, por trimestres). Los informes fiscales empiezan en su periodo natural.
* **Tabla cruzada** en el constructor (por ejemplo, clientes × meses) y agrupación de fechas por mes o año.
* **Exportar a Excel (.xlsx)** informes, consultas, listados y tareas: cabecera en negrita, formatos de euro, porcentaje y fecha, fila de totales, filtro y primera fila fija. Sin librerías (`ZipArchive`).

### Automatizar y rendimiento
* Las **tareas** pueden extraer **consultas guardadas** y guardar en Excel.
* **Avisos en el panel**: productos que se agotan, tareas que fallan, incidencias abiertas, NIF mal, altas por formularios web y consultas lentas (estos dos últimos, según el rol).
* **Consultas lentas**: las de informes, constructor, consola y listados que pasan de 500 ms se guardan con su SQL (migración 014); **Explicar** muestra el plan de la base de datos y dice en palabras si usa índices o recorre la tabla entera. También en el constructor y la consola.

### Documentar y analizar
* **Incidencias** (Administración): qué pasó, tipo, gravedad, estado, responsable y solución (migración 013). Cualquiera las apunta; solo administración las borra. Una tarea que falla abre sola una (solo una mientras siga abierta).
* **Previsión de ventas**: tendencia de los 12 últimos meses y previsión del mes en curso y los dos siguientes, con su fiabilidad.
* **Clientes por segmentos (RFM)**: campeones, leales, nuevos, en riesgo, dormidos y ocasionales según cuándo, cada cuánto y cuánto compran.

### Pruebas
* 123 pruebas: 34 de instalación (la actualización simulada va de la 4.0 a la 5.1, con 13 migraciones), 54 de API (13 nuevas), 3 de scripts y 32 en navegador (6 nuevas). El servidor de pruebas anota todas las consultas como lentas (`ERP_UMBRAL_LENTA=0`) para probar esa parte.
* Instalación de Apache actualizada de la 5.0 a la 5.1 (4 migraciones y copia automática); Sistema: 50 correctas, 9 avisos, 0 errores.
* Prueba de carga (20.000 pedidos): análisis 297 ms, avisos del panel 64 ms, tabla cruzada 25 ms, informe comparado 41 ms.

---

## [5.0] — 2026-10-19 · RA3: organización y consulta de la información

Preparado para meter los datos reales de una empresa (unidad 4): cargarlos, consultarlos, sacar informes y comprobar que el sistema aguanta.

### Datos reales
* **NIF, NIE y CIF** con su letra o dígito de control (tipo de campo `nif`) en clientes, proveedores y empresa. Se guardan normalizados (`b-12345674` → `B12345674`). Los NIF de los datos de demostración ahora son válidos.
* **Importar** clientes, productos y proveedores desde CSV (`;`, `,` o tabulador; UTF-8 o Windows-1252) o JSON: plantilla descargable, columnas reconocidas por nombre o etiqueta, decimales con coma y fechas `dd/mm/aaaa`, vista previa con los errores de cada fila y aviso de repetidos (NIF, email o nombre). Solo se importan las filas correctas, todas en una transacción.
* **Formularios web públicos** (`formulario.html?f=…`) que crean clientes, proveedores o productos, con código para incrustarlos. Misma validación que el alta a mano, campo trampa contra robots, máximo de 5 envíos por hora y origen (se guarda una huella, no la IP) y autor «Formulario web · …» en el Registro.

### Consultar
* **Constructor de consultas**: datos, columnas, filtros (igual, contiene, mayor que, vacío…), agrupación con contar/sumar/media/mínimo/máximo, orden y límite. Solo columnas de una lista blanca y valores parametrizados; muestra el SQL generado.
* **Consola SQL** (administración): una sola sentencia SELECT, sin comentarios, sin tablas de usuarios y claves, en una conexión de solo lectura del propio motor (`PRAGMA query_only` / transacción `READ ONLY`). Máximo 1.000 filas.
* **Consultas guardadas**: se repiten con un clic y se exportan.

### Informes y exportación
* **8 informes**: ventas por periodo, por cliente y por producto, libro de facturas emitidas, pedidos pendientes, clientes sin compras recientes, inventario valorado y compras por proveedor.
* Periodos día / semana / mes / trimestre / año con ◀ ▶, o fechas a medida; agrupación por día, semana, mes o año (los periodos sin ventas salen con 0). Totales, porcentaje del total y gráfico.
* Exportación a **CSV** (BOM, `;`, decimales con coma, fila TOTAL y protección contra fórmulas) y **JSON**; **imprimir / PDF** con hoja de estilos de impresión.
* Todos los listados se exportan también en JSON.

### Automatizar
* **Tareas programadas**: un informe con un periodo relativo (ayer, semana anterior, mes anterior, últimos 30 días, año en curso), cada día, cada lunes o el día 1 a las 7:00, en CSV o JSON.
* `scripts/tareas.php` para cron (`--todas`, `--id=`), botón «Ejecutar», archivos en `datos/extracciones/` (los 30 últimos por tarea) y descarga desde el ERP. Los errores quedan como **incidencia** en el Registro.

### Rendimiento
* `scripts/monitor.php` (cron, cada minuto) guarda CPU, memoria, disco, carga y tamaño de la base de datos leyendo `/proc`, sin dependencias.
* Cada petición a la API anota recurso, método, código y milisegundos (se guardan 7 días).
* Pantalla **Rendimiento**: gráficos, media / p95 / máximo / errores por recurso, filas por tabla, avisos por umbral, prueba de velocidad e informe en Markdown.
* `scripts/carga.php`: prueba de carga en una base de datos temporal (2.000 clientes y 20.000 pedidos por defecto).
* Migración 010 con índices para informes y análisis: el análisis pasa de 286 ms a 19 ms con 3.000 pedidos.

### Información relevante
* Pantalla **Análisis**: ventas del mes frente al mes anterior y previsión, concentración de clientes (cuántos hacen el 80 %), clientes en riesgo, cobertura de stock con cantidad a reponer, margen por producto, pendiente de facturar, ventas por día de la semana, **calidad de los datos** (NIF/CIF no válidos, contactos sin email ni teléfono, posibles duplicados) y **recomendaciones** en texto.

### Menú y permisos
* Nuevas secciones **Análisis** (Informes, Análisis, Consultas, Rendimiento) y **Datos** (Importar y exportar, Formularios web, Tareas programadas), en el módulo Núcleo.
* El comercial ve Informes, Análisis y el constructor de consultas, siempre solo con los datos que puede ver; el resto es de administración.

### Actualización
* Migraciones 006–010 (tablas `consultas`, `formularios`, `formulario_envios`, `tareas`, `tarea_ejecuciones`, `metricas`, `peticiones` e índices). Esquema 6; las copias de la 4.1 (esquema 5) se pueden restaurar.

### Pruebas
* 104 pruebas: 34 de instalación (ahora la actualización simulada va de la 4.0 a la 5.0, con 9 migraciones), 41 de API (14 nuevas de RA3, entre ellas intentos de escribir o leer tablas protegidas desde la consola SQL y el límite de los formularios públicos), 3 de los scripts de cron y 26 en navegador (8 nuevas).
* Instalación de Apache actualizada de la 4.1 a la 5.0 desde la pantalla Actualizar: 5 migraciones y copia automática; Sistema con 0 errores.

---

## [4.1] — 2026-10-18 · RA2: actualización, acceso y entornos

### Actualizaciones
* Migraciones numeradas en `instalacion/migraciones/` (001–005), idempotentes y anotadas en la tabla `migraciones`.
* Con migraciones pendientes, el ERP entra en **mantenimiento** (503). El administrador ve la pantalla **Actualizar** y el resto de usuarios, «En mantenimiento».
* **Copia automática** antes de actualizar (SQLite con `VACUUM INTO`, MySQL en JSON) en `datos/copias/`, protegida por `.htaccess`.
* Con SQLite, todas las migraciones van en una transacción; con MySQL se para en la que falla e indica la copia.
* Las tablas se crean solo al instalar; la evolución posterior va siempre por migraciones.

### Servicios de acceso
* **Claves de API** (Administración → Acceso API): por recursos, en solo lectura o en lectura y escritura, y revocables. Se guarda la huella SHA-256 y la clave solo se muestra una vez.
* Otros programas usan la misma API con la cabecera `X-API-Key`, sin sesión ni cookie. Nunca pueden borrar ni administrar, y cada uso queda en el Registro.

### Entornos
* Desarrollo, pruebas y explotación: se eligen al instalar (también `--entorno` en la terminal) y se cambian en Ajustes.
* En desarrollo, los errores 500 muestran el mensaje real con archivo y línea. Desarrollo y pruebas muestran insignia y franja de color.
* En explotación, la falta de HTTPS o `display_errors` activo pasan a ser errores en Sistema, que además avisa de las cuentas de demostración.

### Usuarios y documentación
* **Permisos por módulo** para cada usuario (cuadrícula ver/crear/editar/borrar), con los del rol como opción por defecto (migración 005).
* **Notas** en los clientes (migración 003).
* **Informe de instalación** en Markdown: al terminar el asistente y desde Sistema.
* **Reinstalar** desde Sistema, con confirmación, contraseña y copia previa.
* Esquema 5: las copias de seguridad de la 4.0 no se restauran en la 4.1.

### Diseño
* Hoja de estilos rehecha con un sistema de tokens: superficies en capas, sombras suaves, radios, curvas de animación y modo oscuro ajustado aparte.
* Cabecera traslúcida con desenfoque y menú lateral con indicador del elemento activo.
* Tablas con cabecera fija y acciones que se resaltan al pasar por la fila.
* Desplegables propios, anillo de foco frambuesa e insignias con borde.
* Gráficos con degradado y animación de entrada.
* Pantallas de acceso e instalación con fondo de cuadrícula de puntos, y pasos del asistente conectados.
* Modales y avisos animados, y botón Guardar flotante en Ajustes.
* Los efectos al pasar el ratón solo se aplican en dispositivos con ratón.
* Se respetan `prefers-reduced-motion` y `forced-colors`.
* Corregido: los estilos del menú, la cabecera, el pie y los avisos se aplicaban también a elementos internos (`<nav>` del paginador, `<header>` y `<footer>` de las tarjetas de módulo, filas de aviso de Sistema).

### Pruebas
* 79 pruebas: 34 de instalación y configuración (actualización simulada, claves de API, permisos, entornos, informe y reinstalación), 27 de API y 18 en navegador.

---

## [4.0] — 2026-10-18 · RA2: implantación

### Instalación
* **Asistente web** (`instalar.html`) en 5 pasos: requisitos del servidor, perfil, base de datos (SQLite o MySQL con **Probar conexión**), empresa y administrador, y confirmación con datos de demostración opcionales.
* Mientras el ERP no está instalado, `index.html` redirige al asistente y la API responde 503. Una vez instalado, el instalador responde 409.
* **Instalación desatendida** desde la terminal: `scripts/instalar.php` (con `--ayuda`).
* Estado de la instalación en `datos/instalacion.json`, protegido por `.htaccess` y sin contraseñas de usuarios.
* Las instalaciones de la v3 se **adoptan automáticamente** como perfil «Distribuidora» sin perder datos.
* Docker: volumen para `datos/` y asistente con MySQL preseleccionado.

### Módulos y perfiles
* Catálogo de **6 módulos** en `api/modulos/*.json`: Núcleo, CRM, Inventario, Ventas, Facturación y Compras, con **dependencias**.
* **3 perfiles** (supuestos) en `instalacion/perfiles/*.json`: Consultoría, Tienda pequeña y Distribuidora, además de una instalación personalizada.
* Pantalla **Administración → Módulos**: activar un módulo activa sus dependencias; no se puede desactivar uno que otro necesita ni el núcleo; desactivar conserva los datos.
* Los permisos tienen en cuenta los módulos activos: menú, API (403 «El módulo X no está activo»), panel y ficha de cliente.
* Los datos de demostración solo incluyen los módulos instalados.

### Ajustes
* Pantalla **Administración → Ajustes**: datos de la empresa, IVA, series de facturas y rectificativas, stock mínimo y filas por página. Se guardan en la nueva tabla `ajustes`.
* Validación campo a campo y cambios anotados en el Registro.
* Vista Sistema: nuevo grupo «Instalación» (versión, perfil, fecha, módulos y motor recomendado) y comprobación de que `instalacion.json` no se puede descargar.

### Pruebas
* `tests/instalacion.php` (19 pruebas): asistente, validaciones, MySQL incorrecto, módulos, dependencias, ajustes y los tres perfiles instalados con el instalador de terminal.
* `tests/navegador.py`: asistente completo, pantallas Módulos y Ajustes (15 pruebas).
* `tests/ejecutar.sh` prepara tres entornos temporales: sin instalar, instalado por terminal y para el asistente. En total, **61 pruebas**.

### Cambios internos
* `api/nucleo.php` reúne las respuestas y los errores comunes a la API y a la terminal.
* Esquema de base de datos 4: nueva tabla `ajustes`. Las copias de seguridad de la v3 no se restauran en la v4.

---

## [3.0] — 2026-10-05

### Ventas
* **Pedidos con varias líneas**: cada línea guarda producto, cantidad y precio del momento de la venta. El stock se reserva por producto y los errores se señalan en la línea concreta (`lineas.1.cantidad`).
* **Facturas con líneas** y **facturas rectificativas** (serie `R2026-…`): anulan una factura con importes negativos y un motivo; el pedido vuelve a poder facturarse.
* Factura imprimible con todas las líneas y aviso de rectificación o anulación.

### Compras
* Módulos nuevos **Proveedores** y **Compras** (con líneas y coste). Una compra recibida sube el stock; si deja de estarlo, lo resta (solo si no se ha vendido ya).
* «Stock bajo» del panel muestra las unidades ya pedidas y un botón **Reponer** que abre la compra con la cantidad sugerida.

### Administración
* **Copias de seguridad** desde Sistema: descarga en JSON y restauración validada (tablas y columnas en lista blanca, todo en una transacción). El mismo archivo sirve para SQLite y MySQL.
* **Cambio de la propia contraseña** desde la barra superior.
* Menú agrupado en Ventas, Compras y Administración.

### Datos e interfaz
* **Filtro por fechas** en los listados y **selector de periodo** en el panel.
* El esquema admite módulos con líneas (`lineas`) de forma genérica: la API, la validación, el CSV y el formulario se generan solos.
* Las bases de datos de la v2 se guardan como copia (`erp-v2-FECHA.sqlite`) y se crea una nueva.

### Calidad y despliegue
* **Pruebas automáticas** en `tests/`: 27 de API (PHP + curl) y 12 en navegador (Playwright), con servidor y base de datos temporales.
* **Docker**: `Dockerfile` (Apache + PHP 8.3 con `gd`, `intl`, `pdo_mysql` y `php.ini` ajustado) y `docker-compose.yml` con MySQL 8. Sin probar en el equipo de desarrollo (incidencia I-11).
* Configuración por variables de entorno (`ERP_MOTOR`, `ERP_SQLITE`, `ERP_MYSQL_*`).

---

## [2.1] — 2026-10-05

### Diseño más profesional
* Estructura clásica de aplicación de gestión: barra superior fija, menú lateral y superficies sólidas (sin fondo animado ni efecto cristal).
* Iconos SVG de línea en lugar de emojis en el menú, los estados, el registro, los avisos y los estados vacíos.
* DM Sans en toda la interfaz; la Playfair cursiva queda solo en el logotipo.
* Paleta neutra con un único acento frambuesa (`#A3154A`, contraste 7,2:1), botones rectangulares y animaciones mínimas.
* Textos más formales: «Resumen», «Pedidos», «Estado del sistema», «Registro de operaciones».
* Factura con cabecera y totales en estilo corporativo.

---

## [2.0] — 2026-10-05

### Usuarios y seguridad
* Inicio de sesión con contraseñas cifradas (`password_hash`) y dos roles: **admin** y **comercial**.
* Permisos por módulo y acción definidos en `api/esquema.php`, comprobados en el servidor (403) y reflejados en la interfaz (menú, botones).
* Cookie de sesión `HttpOnly` + `SameSite=Strict`, regeneración del id al entrar, cabecera `X-Blush` obligatoria en las escrituras y pausa tras un intento fallido.
* Módulo **Usuarios**: nadie puede borrarse ni quitarse el rol de administración a sí mismo.
* El registro guarda **quién** hizo cada operación, además de los inicios y cierres de sesión.

### Lógica de negocio
* **Precio guardado en el pedido**: cambiar el catálogo ya no modifica los pedidos antiguos.
* **Control de stock**: los pedidos descuentan unidades, no se puede vender sin stock (422), y al cancelar, editar o borrar se devuelven.
* **Facturación**: botón «Facturar» en los pedidos entregados, numeración correlativa por año, IVA, datos del cliente copiados en la factura y pedido bloqueado 🔒 después.
* **Factura imprimible** (`factura.html`) en A4, con «Guardar como PDF».
* Todas las escrituras van en **transacciones**: los errores se lanzan como excepción y se deshace todo.

### Datos e interfaz
* **Paginación y ordenación en el servidor** (15 por página) y **exportación a CSV** compatible con Excel.
* **Ficha de cliente** (`#/clientes/3`) con total comprado, ticket medio e historial de pedidos y facturas.
* **Ventas por mes** en el panel (6 meses), con tabla accesible para lectores de pantalla, e indicador «Por facturar».
* Seis meses de datos de demostración, con facturas ya emitidas.
* Migración automática: las bases de datos de la versión 1.0 reciben las columnas y tablas nuevas.

### Servidor (RA1, criterios e y f)
* Código preparado para MySQL (pendiente de probar en el servidor) (zona horaria UTC, `LIMIT` con prepares emulados, InnoDB `utf8mb4`).
* `scripts/mysql.sql`: base de datos y usuario MySQL con permisos mínimos.
* `scripts/configurar-servidor.sh`: instala `gd` e `intl`, ajusta `php.ini` (con copia de seguridad) y reinicia Apache.
* Capturas en `docs/capturas/`.

---

## [1.0] — 2026-10-05

### Datos y API
* Base de datos real con PDO: **SQLite** por defecto y **MySQL** cambiando una línea en `api/config.php`.
* Tablas creadas automáticamente a partir de `api/esquema.php`, con claves foráneas.
* Datos de demostración en la primera visita (los mismos productos que Crimson, además de clientes y pedidos).
* API con CRUD completo (`GET`, `POST`, `PUT`, `DELETE`) para cualquier módulo del esquema.
* Validación en el servidor con errores por campo (422), borrado protegido (409) y respuestas JSON homogéneas.
* Consultas preparadas y lista blanca de tablas y columnas.
* Columnas calculadas (total del pedido) y relaciones (nombre del cliente y del producto en lugar de su id).

### Módulos
* **Panel**: indicadores, ventas por producto, últimos pedidos y stock que conviene reponer.
* **Clientes**, **Productos** y **Pedidos**: una sola vista genérica que se genera a partir del esquema.
* **Registro**: anota cada operación con los cambios campo a campo y se exporta a Markdown.
* **Sistema**: diagnóstico en directo de SO, Apache, PHP, extensiones, límites, BD y descarga de la BD; también se exporta a Markdown.

### Interfaz
* Rutas `#/modulo` sin recargar la página.
* `incluir.js` mejorado: carga en paralelo, includes anidados, devuelve una promesa y muestra los errores.
* Buscador en la cabecera (atajo `/`), ordenación por columnas, formularios en ventana modal y confirmación de borrado.
* Avisos flotantes de éxito y error.
* Estilo de serenaesteve.com: fondo aurora, cristal, DM Sans + Playfair Display, acento grafito y rosa.
* Modo claro / oscuro (sigue al sistema y se recuerda la elección).
* Diseño adaptado a móvil.
* Accesibilidad: foco visible, `aria-sort`, `aria-current`, estados con texto e icono además del color, y respeto de `prefers-reduced-motion`.

### Seguridad
* `datos/.htaccess` bloquea la descarga de la base de datos (comprobado: 200 → 403).
* Los textos se insertan siempre como texto (sin `innerHTML` con datos), así que no hay XSS.
* Los errores internos de PDO no se envían al navegador; se guardan en el log de Apache.

---

## [0.2] — 2026-09-17 · Crimson (clase)
* Changelog.

## [0.1] — 2026-09-17 · Crimson (clase)
* Arquitectura `api/`, `modulos/`, `nucleo/`, componentes con `data-include` y datos JSON de demostración.
