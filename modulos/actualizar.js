// Actualización del ERP (RA2): cuando el código es más nuevo que la base de datos,
// un administrador aplica las migraciones pendientes. Antes se guarda una copia de seguridad.

import { api } from "../nucleo/js/api.js";
import { h, icono } from "../nucleo/js/dom.js";

export async function vista({ usuario }) {
  const tarjeta = h("section", { class: "instalador tarjeta vidrio" },
    h("p", { class: "logo" }, "serena", h("span", {}, "|"), h("em", {}, "blush")));

  if (usuario.rol !== "admin") {
    tarjeta.append(
      h("h1", {}, "En mantenimiento"),
      h("p", { class: "nota" }, "Hay una actualización pendiente. Un administrador tiene que aplicarla antes de seguir trabajando."),
      salir());
    return tarjeta;
  }

  const info = await api("actualizar");
  const boton = h("button", { class: "boton boton--principal", type: "button" }, icono("rectificar"), "Actualizar ahora");
  const zona = h("div", { class: "instalador-cuerpo" },
    h("h1", {}, `Actualización ${info.instalada} → ${info.nueva}`),
    h("p", { class: "nota" }, "El código del ERP es más nuevo que la base de datos. Estas migraciones adaptan la estructura sin perder datos:"),
    h("ul", { class: "comprobaciones" }, info.pendientes.map((m) =>
      h("li", {},
        h("span", { class: "icono", "aria-hidden": "true" }, icono("instalar")),
        h("span", { class: "nombre" }, m.descripcion),
        h("span", { class: "valor" }, `${m.id} · v${m.version}`)))),
    h("p", { class: "nota" }, info.motor === "mysql"
      ? "Antes se exporta una copia de seguridad a datos/copias/. Con MySQL los cambios de estructura no se pueden deshacer: si una migración falla, restaura esa copia."
      : "Antes se guarda una copia de la base de datos en datos/copias/. Si una migración falla, no se aplica ningún cambio."),
    h("div", { class: "instalador-pie" }, salir(), boton));

  boton.addEventListener("click", async () => {
    boton.disabled = true;
    boton.textContent = "Actualizando…";
    try {
      const r = await api("actualizar", { metodo: "POST" });
      zona.replaceChildren(
        h("span", { class: "vacio-icono icono-ok", "aria-hidden": "true" }, icono("ok")),
        h("h1", {}, `Actualizado a la versión ${r.hasta}`),
        h("p", { class: "nota" }, `${r.aplicadas.length} migraciones aplicadas. Copia de seguridad: datos/copias/${r.copia}`),
        h("a", { class: "boton boton--principal", href: "./" }, "Entrar al ERP"));
      zona.classList.add("instalador--fin");
    } catch (error) {
      zona.append(h("p", { class: "error", role: "alert" }, error.message));
      boton.disabled = false;
      boton.textContent = "Reintentar";
    }
  });

  tarjeta.append(zona);
  return tarjeta;
}

function salir() {
  return h("button", { class: "boton", type: "button", onclick: async () => {
    await api("salir", { metodo: "POST" }).catch(() => {});
    location.reload();
  } }, icono("salir"), "Salir");
}
