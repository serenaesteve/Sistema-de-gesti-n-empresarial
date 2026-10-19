"""Pruebas de extremo a extremo en un navegador real (Chromium, con Playwright).

Las lanza tests/ejecutar.sh contra un servidor con una base de datos temporal.
Uso directo:  python3 tests/navegador.py http://127.0.0.1:8765/ [carpeta-para-capturas]
"""
import os
import sys
from playwright.sync_api import sync_playwright, expect

URL = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8765/"
CAPTURAS = sys.argv[2] if len(sys.argv) > 2 else None
URL_INSTALADOR = os.environ.get("URL_INSTALADOR")  # ERP sin instalar, para probar el asistente
superadas, fallidas, errores_consola = 0, [], []


def prueba(nombre, funcion):
    global superadas
    try:
        funcion()
        superadas += 1
        print(f"  \033[32m✓\033[0m {nombre}")
    except Exception as e:  # noqa: BLE001
        fallidas.append(nombre)
        print(f"  \033[31m✗ {nombre}\033[0m\n      " + ("\n      ".join(str(e).splitlines()[:6]) if os.environ.get("DETALLE") else str(e).splitlines()[0]))


def captura(pagina, nombre):
    if CAPTURAS:
        pagina.mouse.move(0, 0)
        pagina.evaluate("document.querySelectorAll('.aviso').forEach((a) => a.remove())")
        pagina.wait_for_timeout(400)  # fin de la animación de entrada
        pagina.screenshot(path=f"{CAPTURAS}/{nombre}.png")


def entrar(pagina, email, clave="blush2026"):
    pagina.goto(URL)
    pagina.fill("#acceso-email", email)
    pagina.fill("#acceso-clave", clave)
    pagina.click(".acceso-formulario button[type=submit]")
    pagina.wait_for_selector("#menu-lista a")


def entrar_en(pagina, email, clave):
    pagina.wait_for_selector("#acceso-email")
    pagina.fill("#acceso-email", email)
    pagina.fill("#acceso-clave", clave)
    pagina.click(".acceso-formulario button[type=submit]")
    pagina.wait_for_selector("#menu-lista a")


def ir(pagina, ruta):
    pagina.goto(URL + "#/panel")  # cambiar de ruta garantiza que la vista se vuelve a cargar
    pagina.goto(URL + "#/" + ruta)
    pagina.wait_for_selector("main .vista")


with sync_playwright() as p:
    navegador = p.chromium.launch()
    contexto = navegador.new_context(viewport={"width": 1440, "height": 900}, accept_downloads=True)
    pg = contexto.new_page()
    pg.on("pageerror", lambda e: errores_consola.append(str(e)))

    print("\nNavegador · administración")

    def acceso():
        pg.goto(URL)
        pg.wait_for_selector("#acceso-email")
        captura(pg, "acceso")
        pg.fill("#acceso-email", "serena@blush.test")
        pg.fill("#acceso-clave", "mala")
        pg.click(".acceso-formulario button[type=submit]")
        expect(pg.locator("#acceso-error")).to_contain_text("incorrectos")
        entrar(pg, "serena@blush.test")
        expect(pg.locator("main h1")).to_have_text("Resumen")
        captura(pg, "panel")
    prueba("Acceso con contraseña incorrecta y correcta", acceso)

    def todas_las_vistas():
        for ruta in ["clientes", "pedidos", "facturas", "productos", "proveedores", "compras", "usuarios", "registro", "modulos", "ajustes", "api", "sistema"]:
            ir(pg, ruta)
            pg.wait_for_selector("main .tarjeta")
    prueba("Las trece vistas cargan sin errores", todas_las_vistas)

    def insignia_entorno():
        expect(pg.locator(".insignia-entorno")).to_have_text("Pruebas")
    prueba("La cabecera indica el entorno de pruebas", insignia_entorno)

    def pedido_varias_lineas():
        ir(pg, "pedidos")
        pg.click("#nuevo")
        pg.select_option("#campo-cliente_id", index=1)
        lineas = pg.locator("fieldset.lineas tbody tr")
        lineas.nth(0).locator("select").select_option(index=1)
        lineas.nth(0).locator("[data-campo=cantidad]").fill("2")
        pg.click("text=Añadir línea")
        lineas.nth(1).locator("select").select_option(label=pg.locator("fieldset.lineas tbody tr:nth-child(2) option", has_text="Hub").inner_text())
        lineas.nth(1).locator("[data-campo=cantidad]").fill("999")
        captura(pg, "pedido-lineas")
        pg.click("dialog button[type=submit]")
        expect(lineas.nth(1).locator("[data-campo=cantidad] + .error")).to_contain_text("Stock insuficiente")
        lineas.nth(1).locator("[data-campo=cantidad]").fill("1")
        pg.click("dialog button[type=submit]")
        expect(pg.locator("dialog[open]")).to_have_count(0)
        expect(pg.locator("tbody tr").first.locator("td").nth(2)).to_contain_text("y 1 más")
    prueba("Pedido con varias líneas, con aviso de stock en la línea correcta", pedido_varias_lineas)

    def facturar_y_rectificar():
        ir(pg, "pedidos")
        with contexto.expect_page() as nueva:
            pg.locator("button[aria-label^='Facturar']").first.click()
            pg.click("dialog button[value=si]")
        factura = nueva.value
        factura.wait_for_selector(".totales")
        expect(factura.locator(".lineas tbody tr")).not_to_have_count(0)
        if CAPTURAS:
            factura.screenshot(path=f"{CAPTURAS}/factura.png", full_page=True)
            factura.pdf(path=f"{CAPTURAS}/factura-ejemplo.pdf", format="A4")
        factura.close()
        ir(pg, "facturas")
        with contexto.expect_page() as nueva:
            pg.locator("button[aria-label^='Rectificar']").first.click()
            pg.fill("#motivo", "Error en el precio")
            pg.click("dialog button[value=si]")
        rectificativa = nueva.value
        rectificativa.wait_for_selector(".aviso-rectificativa")
        expect(rectificativa.locator("h1")).to_have_text("Factura rectificativa")
        if CAPTURAS:
            rectificativa.screenshot(path=f"{CAPTURAS}/factura-rectificativa.png", full_page=True)
        rectificativa.close()
        captura(pg, "facturas")
    prueba("Facturar un pedido y emitir una rectificativa", facturar_y_rectificar)

    def reponer_desde_panel():
        ir(pg, "panel")
        pg.locator("a:has-text('Reponer')").first.click()
        pg.wait_for_selector("dialog[open] fieldset.lineas")
        pg.select_option("#campo-proveedor_id", index=1)
        pg.locator("fieldset.lineas [data-campo=coste]").first.fill("30")
        captura(pg, "compra-reponer")
        pg.click("dialog button[type=submit]")
        expect(pg.locator("dialog[open]")).to_have_count(0)
    prueba("Reponer stock desde el panel crea una compra al proveedor", reponer_desde_panel)

    def periodo_panel():
        ir(pg, "panel")
        pg.click(".segmentado button:has-text('Este mes')")
        expect(pg.locator(".segmentado button[aria-pressed=true]")).to_have_text("Este mes")
    prueba("El selector de periodo del panel funciona", periodo_panel)

    def ficha_cliente():
        ir(pg, "clientes")
        pg.locator("a.enlace-ficha").first.click()
        pg.wait_for_selector(".datos")
        captura(pg, "ficha-cliente")
    prueba("Ficha de cliente", ficha_cliente)

    def copia():
        ir(pg, "sistema")
        with pg.expect_download() as descarga:
            pg.click("a:has-text('Descargar copia')")
        assert descarga.value.suggested_filename.endswith(".json")
        captura(pg, "sistema")
    prueba("Descargar copia de seguridad", copia)

    def pedidos_captura():
        ir(pg, "pedidos")
        captura(pg, "pedidos")
        ir(pg, "registro")
        captura(pg, "registro")
    prueba("Listados y registro", pedidos_captura)

    def pantalla_modulos():
        ir(pg, "modulos")
        captura(pg, "modulos")
        inventario = pg.locator("article.modulo:has(h2:text-is('Inventario'))")
        expect(inventario.locator("button")).to_be_disabled()  # lo necesitan Ventas y Compras
        pg.locator("article.modulo:has(h2:text-is('Compras'))").locator("button:has-text('Desactivar')").click()
        with pg.expect_navigation():
            pg.click("dialog button[value=si]")
        pg.wait_for_selector("#menu-lista a")
        expect(pg.locator("#menu-lista a[data-ruta=compras]")).to_have_count(0)
        ir(pg, "modulos")
        with pg.expect_navigation():
            pg.locator("article.modulo:has(h2:text-is('Compras'))").locator("button:has-text('Activar')").click()
        expect(pg.locator("#menu-lista a[data-ruta=compras]")).to_have_count(1)
    prueba("Módulos: Inventario bloqueado por dependencias; Compras se desactiva y se activa", pantalla_modulos)

    def pantalla_ajustes():
        ir(pg, "ajustes")
        pg.fill("[name='serie_rectificativa']", "F")
        pg.click("button:has-text('Guardar ajustes')")
        expect(pg.locator("#ajuste-serie_rectificativa-error")).to_contain_text("distinta")
        pg.fill("[name='serie_rectificativa']", "R")
        pg.fill("[name='stock_minimo']", "10")
        captura(pg, "ajustes")
        with pg.expect_navigation():  # al guardar, la página se recarga
            pg.click("button:has-text('Guardar ajustes')")
        ir(pg, "ajustes")
        expect(pg.locator("[name='stock_minimo']")).to_have_value("10")
    prueba("Ajustes: validación y guardado", pantalla_ajustes)

    def acceso_api():
        ir(pg, "api")
        pg.click("button:has-text('Nueva clave')")
        pg.fill("#clave-nombre", "Tienda online")
        pg.locator("dialog .rejilla-casillas label:has-text('Clientes') input").check()
        pg.click("dialog button:has-text('Crear clave')")
        clave = pg.locator(".clave-generada").input_value()
        assert clave.startswith("blush_") and len(clave) == 46, clave
        captura(pg, "api-clave-creada")
        with pg.expect_navigation():
            pg.click("dialog button:has-text('Hecho')")
        pg.wait_for_selector("main table")
        expect(pg.locator("main tbody tr").first).to_contain_text(clave[:12])
        captura(pg, "api")
        respuesta = pg.request.get(URL + "api/api.php?recurso=clientes", headers={"X-API-Key": clave})
        assert respuesta.ok and respuesta.json()["ok"], respuesta.text()
    prueba("Acceso API: crear una clave, verla una sola vez y usarla", acceso_api)

    def permisos_usuario():
        ir(pg, "usuarios")
        pg.locator("button[aria-label^='Editar «Pablo Gil»']").click()
        pg.wait_for_selector(".permisos-tabla")
        pg.locator(".permisos label.check input").uncheck()
        pg.locator("input[aria-label='Compras: crear']").check()
        expect(pg.locator("input[aria-label='Compras: ver']")).to_be_checked()
        captura(pg, "usuario-permisos")
        pg.click("dialog button[type=submit]")
        expect(pg.locator("dialog[open]")).to_have_count(0)
    prueba("Permisos por módulo: crear marca también ver", permisos_usuario)


    print("\nNavegador · organización y consulta de la información (RA3)")

    def vistas_ra3():
        for ruta in ["informes", "analisis", "consultas", "rendimiento", "intercambio", "formularios", "tareas", "incidencias"]:
            ir(pg, ruta)
            pg.wait_for_selector("main .tarjeta")
        expect(pg.locator("#menu-lista .etiqueta", has_text="Análisis")).to_have_count(1)
        expect(pg.locator("#menu-lista .etiqueta", has_text="Datos")).to_have_count(1)
    prueba("Las siete pantallas nuevas cargan y el menú tiene «Análisis» y «Datos»", vistas_ra3)

    def informes():
        ir(pg, "informes")
        pg.wait_for_selector(".informe table")
        expect(pg.locator(".informe .columnas")).to_have_count(1)
        pg.click(".segmentado button:text-is('Año')")
        anio = pg.locator(".navegador-periodo strong").inner_text()
        pg.click("button[aria-label='Periodo anterior']")
        expect(pg.locator(".navegador-periodo strong")).not_to_have_text(anio)
        pg.click("button[aria-label='Periodo siguiente']")
        expect(pg.locator(".navegador-periodo strong")).to_have_text(anio)
        expect(pg.locator(".informe tfoot")).to_contain_text("Total")
        captura(pg, "informes")
        pg.select_option("#informe", "ventas_cliente")
        pg.wait_for_selector(".informe .barras")
        with pg.expect_download() as descarga:
            pg.click("main .pagina-acciones a:has-text('CSV')")
        assert descarga.value.suggested_filename.startswith("ventas_cliente") and descarga.value.suggested_filename.endswith(".csv")
        captura(pg, "informes-clientes")
    prueba("Informes: periodos con ◀ ▶, totales, gráfico y exportación a CSV", informes)

    def analisis():
        ir(pg, "analisis")
        expect(pg.locator(".recomendaciones li").first).to_be_visible()
        expect(pg.locator("main h2", has_text="Cobertura de stock")).to_have_count(1)
        captura(pg, "analisis")
    prueba("Análisis: recomendaciones, concentración, riesgo, cobertura y márgenes", analisis)

    def consultas():
        ir(pg, "consultas")
        pg.select_option("#c-recurso", "clientes")
        pg.select_option("#c-agrupar", "ciudad")
        pg.click("button:has-text('Ejecutar consulta')")
        expect(pg.locator(".sql-generado pre")).to_contain_text("GROUP BY t.ciudad")
        expect(pg.locator(".tabla-resultado tbody tr").first).to_be_visible()
        captura(pg, "consultas-constructor")
        pg.click(".segmentado button:text-is('Consola SQL')")
        pg.click("button:has-text('Ventas por mes')")
        pg.click("form button:has-text('Ejecutar')")
        expect(pg.locator(".tabla-resultado th").first).to_contain_text("mes")
        captura(pg, "consultas-sql")
        pg.fill("#c-sql", "DELETE FROM clientes")
        pg.click("form button:has-text('Ejecutar')")
        expect(pg.locator(".vacio-estado h3")).to_contain_text("Solo se permiten consultas SELECT")
    prueba("Consultas: constructor con el SQL generado y consola de solo lectura", consultas)

    def importar():
        ir(pg, "intercambio")
        pg.select_option("#destino", "clientes")
        csv = "Nombre;Email;Ciudad\nCliente importado web;importado@web.es;Castellón\nSin nombre email;no-es-email;Valencia\n"
        pg.set_input_files("#archivo-importar", files=[{"name": "clientes.csv", "mimeType": "text/csv", "buffer": csv.encode()}])
        expect(pg.locator(".tabla-previa tr.fila-error")).to_have_count(1)
        captura(pg, "importar-vista-previa")
        pg.click("button:has-text('Importar 1 fila')")
        expect(pg.locator(".vacio-estado h3")).to_contain_text("1 importados")
    prueba("Importar: vista previa con la fila errónea marcada e importación de las correctas", importar)

    def formularios():
        ir(pg, "formularios")
        pg.click("button:has-text('Nuevo formulario')")
        pg.fill("#fw-titulo", "Alta de proveedor")
        pg.select_option("#fw-recurso", "proveedores")
        pg.locator("dialog label:has-text('NIF / CIF') input").check()
        pg.click("dialog button:has-text('Publicar formulario')")
        expect(pg.locator("article.modulo h2", has_text="Alta de proveedor")).to_have_count(1)
        captura(pg, "formularios")
        publica = navegador.new_context(viewport={"width": 900, "height": 900}).new_page()
        publica.on("pageerror", lambda e: errores_consola.append(str(e)))
        publica.goto(URL + "formulario.html?f=alta-de-proveedor")
        publica.wait_for_selector("#f-nombre")
        publica.fill("#f-nombre", "Proveedor desde la web")
        publica.fill("#f-nif", "B12345675")
        publica.click("button:has-text('Enviar')")
        expect(publica.locator("#e-nif")).to_contain_text("no válido")
        publica.fill("#f-nif", "b-12345674")
        if CAPTURAS:
            publica.screenshot(path=f"{CAPTURAS}/formulario-publico.png", full_page=True)
        publica.click("button:has-text('Enviar')")
        expect(publica.locator("h1")).to_have_text("¡Enviado!")
        publica.context.close()
        ir(pg, "proveedores")
        expect(pg.locator("main tbody")).to_contain_text("Proveedor desde la web")
    prueba("Formularios web: se publica uno y un visitante sin sesión lo envía", formularios)

    def tareas():
        ir(pg, "tareas")
        pg.click("main .pagina-acciones button:has-text('Nueva tarea')")
        pg.fill("#t-nombre", "Ventas del mes para dirección")
        pg.select_option("#t-informe", "ventas_producto")
        pg.click("dialog button:has-text('Programar')")
        fila = pg.locator("main tbody tr", has_text="Ventas del mes para dirección")
        fila.locator("button:has-text('Ejecutar')").click()
        expect(pg.locator(".linea-tiempo li").first).to_contain_text("Ventas del mes para dirección")
        captura(pg, "tareas")
        with pg.expect_download() as descarga:
            pg.locator(".linea-tiempo li").first.locator("a").click()
        assert descarga.value.suggested_filename.endswith(".csv")
    prueba("Tareas programadas: programar, ejecutar ahora y descargar la extracción", tareas)

    def rendimiento():
        ir(pg, "rendimiento")
        pg.click("button:has-text('Medir velocidad')")
        expect(pg.locator("main h2", has_text="Prueba de velocidad")).to_have_count(1)
        captura(pg, "rendimiento")
    prueba("Rendimiento: estado del servidor, tiempos por recurso y prueba de velocidad", rendimiento)


    print("\nNavegador · datos reales y más consultas (v5.1)")

    def importar_pedidos_y_deshacer():
        ir(pg, "intercambio")
        pg.select_option("#destino", "pedidos")
        csv = "Referencia;Cliente;Fecha;Estado;Producto;Cantidad\nW-1;Noa Vidal;10/03/2025;entregado;Disco SSD 1TB;2\nW-1;;;;Memoria USB 128GB;5\n"
        pg.set_input_files("#archivo-importar", files=[{"name": "historico.csv", "mimeType": "text/csv", "buffer": csv.encode()}])
        expect(pg.locator(".tarjeta .resumen")).to_contain_text("1 registro")
        captura(pg, "importar-pedidos")
        pg.click("button:has-text('Importar 1 registro')")
        expect(pg.locator(".vacio-estado h3")).to_contain_text("1 importados")
        pg.click("button:has-text('Ver el historial')")
        historial = pg.locator(".linea-tiempo li", has_text="historico.csv").first
        historial.locator("button:has-text('Deshacer')").click()
        pg.click("dialog button[value=si]")
        expect(pg.locator(".linea-tiempo li", has_text="historico.csv").first).to_contain_text("deshecha")
        captura(pg, "importar-historial")
    prueba("Importar pedidos con líneas y deshacer la importación desde el historial", importar_pedidos_y_deshacer)

    def informes_comparar():
        ir(pg, "informes")
        pg.select_option("#informe", "ventas_cliente")
        pg.click(".segmentado button:text-is('Año')")
        pg.select_option("#comparar", "ano_anterior")
        expect(pg.locator(".comparacion")).to_contain_text("Mismo periodo del año pasado")
        expect(pg.locator(".informe .tabla-resultado thead")).to_contain_text("Variación")
        pg.select_option("#filtro-categoria", "Audio")
        expect(pg.locator(".informe")).to_be_visible()
        captura(pg, "informes-comparar")
        with pg.expect_download() as descarga:
            pg.click("main .pagina-acciones a:has-text('Excel')")
        assert descarga.value.suggested_filename.endswith(".xlsx")
        pg.select_option("#informe", "iva_trimestral")
        expect(pg.locator(".navegador-periodo strong")).to_contain_text("trimestre")
        expect(pg.locator(".informe .tabla-resultado tbody")).to_contain_text("Resultado")
        captura(pg, "informes-iva")
    prueba("Informes: comparar con el año pasado, filtrar, Excel e IVA del trimestre", informes_comparar)

    def consultas_cruzada():
        ir(pg, "consultas")
        pg.click(".segmentado button:text-is('Constructor')")
        pg.select_option("#c-recurso", "pedidos")
        pg.select_option("#c-agrupar", "cliente")
        pg.select_option("#c-cruzar", "fecha__mes")
        pg.select_option("#c-agregacion", "suma")
        pg.locator("select[aria-label='Campo a calcular']").select_option("total")
        pg.click("button:has-text('Ejecutar consulta')")
        expect(pg.locator(".tabla-resultado thead th").last).to_contain_text("Total")
        captura(pg, "consultas-cruzada")
        pg.click("button:has-text('Explicar')")
        expect(pg.locator("main h2", has_text="Cómo la ejecuta la base de datos")).to_have_count(1)
    prueba("Consultas: tabla cruzada clientes × meses y «Explicar»", consultas_cruzada)

    def analisis_duplicados():
        cab = {"X-Blush": "1", "Content-Type": "application/json"}
        for cuerpo in [{"nombre": "Irene Duplicada", "email": "irene@dup.es"}, {"nombre": "Irene Duplicada", "telefono": "611 222 333"}]:
            r = pg.request.post(URL + "api/api.php?recurso=clientes", headers=cab, data=cuerpo)
            assert r.ok, r.text()
        ir(pg, "analisis")
        expect(pg.locator("main h2", has_text="Previsión de ventas")).to_have_count(1)
        expect(pg.locator(".segmentos li").first).to_be_visible()
        captura(pg, "analisis-prevision")
        pg.locator("tr", has_text="Clientes").locator("button:has-text('Revisar')").click()
        grupo = pg.locator(".grupo-duplicados", has_text="Irene Duplicada")
        grupo.locator("button:has-text('Fusionar')").click()
        pg.click("dialog button[value=si]")
        expect(pg.locator(".grupo-duplicados", has_text="Irene Duplicada")).to_have_count(0)
        pg.click("dialog button:has-text('Cerrar')")
        r = pg.request.get(URL + "api/api.php?recurso=clientes&q=Irene%20Duplicada")
        assert len(r.json()["datos"]) == 1, r.text()
    prueba("Análisis: previsión, segmentos y fusión de clientes repetidos", analisis_duplicados)

    def panel_avisos_e_incidencias():
        ir(pg, "incidencias")
        pg.click("#nuevo")
        pg.fill("[name='titulo']", "Faltan los NIF de dos proveedores")
        pg.select_option("[name='gravedad']", "alta")
        pg.click("dialog button[type=submit]")
        expect(pg.locator("main tbody")).to_contain_text("Faltan los NIF de dos proveedores")
        captura(pg, "incidencias")
        ir(pg, "panel")
        expect(pg.locator(".avisos-panel")).to_contain_text("incidencia")
        captura(pg, "panel-avisos")
    prueba("Incidencias y avisos en el panel", panel_avisos_e_incidencias)

    def rendimiento_lentas():
        ir(pg, "rendimiento")
        lentas = pg.locator(".tarjeta", has_text="Consultas lentas")
        expect(lentas.locator("tbody tr").first).to_be_visible()
        lentas.locator("button:has-text('Explicar')").first.click()
        expect(pg.locator("main h2", has_text="Cómo la ejecuta la base de datos")).to_have_count(1)
        captura(pg, "rendimiento-lentas")
    prueba("Rendimiento: consultas lentas con su plan", rendimiento_lentas)

    if URL_INSTALADOR:
        print("\nNavegador · asistente de instalación")
        nuevo = navegador.new_context(viewport={"width": 1440, "height": 900})
        pi = nuevo.new_page()
        pi.on("pageerror", lambda e: errores_consola.append(str(e)))

        def asistente():
            pi.goto(URL_INSTALADOR)
            pi.wait_for_url("**/instalar.html")
            pi.wait_for_selector(".pasos")
            captura(pi, "instalador-1-requisitos")
            pi.click("button:has-text('Siguiente')")
            pi.click("label.perfil:has-text('Tienda pequeña')")
            captura(pi, "instalador-2-perfil")
            pi.click("button:has-text('Siguiente')")
            # MySQL con credenciales incorrectas: la prueba de conexión explica el error
            pi.click("label.perfil:has-text('MySQL')")
            pi.fill("#instalar-mysql-bd", "blush_erp")
            pi.fill("#instalar-mysql-usuario", "usuario_inexistente")
            pi.click("button:has-text('Probar conexión')")
            expect(pi.locator(".resultado-prueba.fallo")).not_to_be_empty()
            captura(pi, "instalador-3-mysql")
            pi.click("label.perfil:has-text('SQLite')")
            pi.click("button:has-text('Siguiente')")
            pi.click("button:has-text('Siguiente')")
            expect(pi.locator("#instalar-empresa-nombre-error")).to_contain_text("obligatorio")
            pi.fill("#instalar-empresa-nombre", "Tienda Azahar S.L.")
            pi.fill("#instalar-empresa-nif", "B46999884")
            pi.fill("#instalar-admin-nombre", "Marta Ruiz")
            pi.fill("#instalar-admin-email", "marta@azahar.test")
            pi.fill("#instalar-admin-clave", "marta12345")
            captura(pi, "instalador-4-empresa")
            pi.click("button:has-text('Siguiente')")
            captura(pi, "instalador-5-confirmar")
            pi.click("button:has-text('Instalar')")
            expect(pi.locator("h1")).to_have_text("Blush ERP está instalado")
            captura(pi, "instalador-6-terminado")
            pi.click("a:has-text('Ir al ERP')")
            entrar_en(pi, "marta@azahar.test", "marta12345")
            menu = pi.locator("#menu-lista a").all_inner_texts()
            assert "Pedidos" in menu and "Facturas" in menu and "Compras" not in menu, menu
            captura(pi, "instalado-tienda")
        prueba("Asistente: perfil Tienda, error de MySQL, validación e instalación", asistente)

    print("\nNavegador · comercial (móvil, modo oscuro)")
    movil = navegador.new_context(viewport={"width": 390, "height": 844}, color_scheme="dark", is_mobile=True)
    pc = movil.new_page()
    pc.on("pageerror", lambda e: errores_consola.append(str(e)))

    def comercial():
        entrar(pc, "pablo@blush.test")
        menu = pc.locator("#menu-lista a").all_inner_texts()
        assert "Sistema" not in menu and "Usuarios" not in menu, menu
        assert "Informes" in menu and "Análisis" in menu and "Rendimiento" not in menu and "Tareas programadas" not in menu, menu
        ir(pc, "consultas")
        expect(pc.locator("button:text-is('Consola SQL')")).to_have_count(0)
        ir(pc, "clientes")
        expect(pc.locator("button[aria-label^='Borrar']")).to_have_count(0)
        ir(pc, "facturas")
        expect(pc.locator("button[aria-label^='Rectificar']")).to_have_count(0)
        ir(pc, "panel")
        if CAPTURAS:
            pc.screenshot(path=f"{CAPTURAS}/movil-panel.png", full_page=True)
    prueba("El comercial no ve Sistema/Usuarios/Rendimiento ni la consola SQL, ni puede borrar o rectificar", comercial)

    def cambiar_clave():
        pc.click("#cuenta")
        pc.fill("#clave-actual", "blush2026")
        pc.fill("#clave-nueva", "otra-clave-9")
        pc.click("dialog button[type=submit]")
        expect(pc.locator(".aviso")).to_contain_text("Contraseña actualizada")
        pc.click("#cuenta")
        pc.fill("#clave-actual", "otra-clave-9")
        pc.fill("#clave-nueva", "blush2026")
        pc.click("dialog button[type=submit]")
        expect(pc.locator("dialog[open]")).to_have_count(0)
    prueba("Cambiar la propia contraseña", cambiar_clave)

    navegador.close()

prueba("Sin errores de JavaScript", lambda: (_ for _ in ()).throw(Exception("; ".join(errores_consola))) if errores_consola else None)

total = superadas + len(fallidas)
print(f"\n{'\033[31m' if fallidas else '\033[32m'}{superadas} de {total} pruebas en el navegador superadas\033[0m")
sys.exit(1 if fallidas else 0)
