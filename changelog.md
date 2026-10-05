# Changelog — serena | blush

Registro de cambios del proyecto. Parte de **Crimson ERP 0.2** (versión de clase).

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
