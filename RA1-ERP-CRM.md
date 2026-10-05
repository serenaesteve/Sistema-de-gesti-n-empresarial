## a) Sistemas ERP-CRM del mercado

Un ERP es un programa que junta en un mismo sitio todo lo que hace una empresa por dentro: ventas, compras, facturas, almacén… Un CRM se centra en los clientes. Hoy casi todos hacen las dos cosas.

Los más conocidos son:

- **Libres:** Odoo, Dolibarr, ERPNext y SuiteCRM.
- **De pago:** SAP, Sage y Microsoft Dynamics.
- **En la nube:** Salesforce y Holded.

## b) Tipos de licencia

- **Software libre (GPL, LGPL, AGPL):** lo puedes usar y modificar gratis, como Dolibarr o Odoo Community.
- **Open core:** una versión gratis y otra de pago con más cosas, como Odoo Enterprise.
- **Licencia propietaria:** pagas por usar el programa, normalmente por usuario, como SAP o Sage.
- **Suscripción en la nube:** pagas una cuota cada mes, como Salesforce o Holded.

Que sea gratis no quiere decir que no cueste nada, porque alguien tiene que instalarlo y mantenerlo.

## c) Comparativa

- **Odoo:** el más completo de los libres.
- **Dolibarr:** el más sencillo de instalar.
- **SAP:** muy potente, pero solo compensa en empresas grandes por lo que cuesta.

Para una pyme o para aprender, yo elegiría Odoo o Dolibarr. Mi propio ERP, que he hecho a partir del Crimson de clase, se parece a Dolibarr: PHP, Apache y base de datos.

## d) Sistema operativo

La mayoría de ERP web van mejor en Linux (Ubuntu o Debian). SAP pide SUSE o Red Hat, Sage funciona en Windows y los de la nube solo necesitan un navegador. Mi ordenador tiene Ubuntu 24.04, que sirve para casi todos.

## e) Base de datos

- **Odoo:** solo funciona con PostgreSQL.
- **Dolibarr y SuiteCRM:** usan MySQL o MariaDB.
- **SAP:** usa su propia base de datos, HANA.

Mi ERP funciona con SQLite y también está preparado para MySQL. En mi ordenador está MySQL 8.0 instalado, pero no PostgreSQL.

## f) Verificación de la configuración

Comprobé el equipo con comandos y además hice una pantalla **Sistema** en mi ERP que lo revisa sola. En mi equipo hay:

- Ubuntu 24.04
- Apache 2.4
- PHP 8.3
- MySQL 8.0

El resultado fue: 31 comprobaciones correctas, 8 avisos y ningún error. Los avisos son cosas de PHP que se pueden mejorar (faltan las extensiones `gd` e `intl` y algunos límites son bajos). Dejé un script preparado para arreglarlos.

## g) Operaciones realizadas

1. Revisé el sistema, los programas instalados y los servicios activos.
2. Estudié el proyecto Crimson de clase.
3. Hice mi ERP con clientes, pedidos, facturas, productos, proveedores, compras y usuarios.
4. Comprobé que la base de datos no se pudiera descargar desde el navegador.
5. Hice pruebas automáticas. Pasaron todas.
6. Lo subí a GitHub.

Además, el ERP guarda solo un registro de todo lo que se hace y de quién lo hace.

## h) Incidencias

- **No podía entrar en MySQL con mi usuario.** Usé SQLite y preparé un script para crear un usuario propio en MySQL.
- **La base de datos se podía descargar desde el navegador.** Lo arreglé con un archivo `.htaccess`.
- **Las horas salían con 2 horas de diferencia.** Era porque se guardaban en UTC; lo corregí.
- **Al cambiar el precio de un producto cambiaban los pedidos antiguos.** Ahora cada pedido guarda el precio del momento de la venta.
- **Faltan extensiones de PHP y algunos límites son bajos.** Hay un script preparado para arreglarlo, pero necesito `sudo` para ejecutarlo.
- **No pude probar Docker porque mi usuario no tiene permisos.** Queda pendiente de `sudo`.
