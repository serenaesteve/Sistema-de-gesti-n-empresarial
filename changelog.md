# Changelog — serena | blush

Registro de cambios del proyecto. Parte de **Crimson ERP 0.2** (versión de clase).

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
