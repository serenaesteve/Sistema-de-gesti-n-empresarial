"""Pruebas de extremo a extremo en un navegador real (Chromium, con Playwright).

Las lanza tests/ejecutar.sh contra un servidor con una base de datos temporal.
Uso directo:  python3 tests/navegador.py http://127.0.0.1:8765/ [carpeta-para-capturas]
"""
import sys
from playwright.sync_api import sync_playwright, expect

URL = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8765/"
CAPTURAS = sys.argv[2] if len(sys.argv) > 2 else None
superadas, fallidas, errores_consola = 0, [], []


def prueba(nombre, funcion):
    global superadas
    try:
        funcion()
        superadas += 1
        print(f"  \033[32m✓\033[0m {nombre}")
    except Exception as e:  # noqa: BLE001
        fallidas.append(nombre)
        print(f"  \033[31m✗ {nombre}\033[0m\n      {str(e).splitlines()[0]}")


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
        for ruta in ["clientes", "pedidos", "facturas", "productos", "proveedores", "compras", "usuarios", "registro", "sistema"]:
            ir(pg, ruta)
            pg.wait_for_selector("main .tarjeta")
    prueba("Las diez vistas cargan sin errores", todas_las_vistas)

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

    print("\nNavegador · comercial (móvil, modo oscuro)")
    movil = navegador.new_context(viewport={"width": 390, "height": 844}, color_scheme="dark", is_mobile=True)
    pc = movil.new_page()
    pc.on("pageerror", lambda e: errores_consola.append(str(e)))

    def comercial():
        entrar(pc, "pablo@blush.test")
        menu = pc.locator("#menu-lista a").all_inner_texts()
        assert "Sistema" not in menu and "Usuarios" not in menu, menu
        ir(pc, "clientes")
        expect(pc.locator("button[aria-label^='Borrar']")).to_have_count(0)
        ir(pc, "facturas")
        expect(pc.locator("button[aria-label^='Rectificar']")).to_have_count(0)
        ir(pc, "panel")
        if CAPTURAS:
            pc.screenshot(path=f"{CAPTURAS}/movil-panel.png", full_page=True)
    prueba("El comercial no ve Sistema/Usuarios ni puede borrar o rectificar", comercial)

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
