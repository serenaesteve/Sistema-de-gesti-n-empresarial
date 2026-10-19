// Formulario web público (RA3, criterio b): formulario.html?f=<dirección>.
// Sin sesión: los datos llegan al ERP con la misma validación que un alta a mano.

import { api } from "./api.js";
import { h } from "./dom.js";

const contenedor = document.getElementById("formulario-publico");
const slug = new URLSearchParams(location.search).get("f") ?? "";
if (new URLSearchParams(location.search).has("incrustado")) document.body.classList.add("incrustado");

try {
  const def = await api("formulario-publico", { parametros: { f: slug } });
  document.title = `${def.titulo} · ${def.empresa}`;
  const errores = {};
  const controles = {};

  const campos = def.campos.map((c) => {
    const comunes = { id: `f-${c.clave}`, name: c.clave, required: c.obligatorio };
    const tipos = { email: { type: "email", autocomplete: "email" }, tel: { type: "tel", autocomplete: "tel" },
      moneda: { type: "number", step: "0.01", min: "0" }, entero: { type: "number", step: "1" }, fecha: { type: "date" },
      nif: { type: "text", maxlength: "12", autocapitalize: "characters", spellcheck: "false" } };
    controles[c.clave] = c.tipo === "largo"
      ? h("textarea", { ...comunes, rows: "4", maxlength: "5000" })
      : c.tipo === "opcion"
        ? h("select", comunes, c.opciones.map((o) => h("option", { value: o }, o[0].toUpperCase() + o.slice(1))))
        : h("input", { ...comunes, ...(tipos[c.tipo] ?? { type: "text", maxlength: "255" }), autocomplete: tipos[c.tipo]?.autocomplete ?? (c.clave === "nombre" ? "name" : "off") });
    errores[c.clave] = h("span", { class: "error", id: `e-${c.clave}` });
    controles[c.clave].setAttribute("aria-describedby", `e-${c.clave}`);
    return h("div", { class: `campo ${c.tipo === "largo" ? "campo--ancho" : ""}` },
      h("label", { for: `f-${c.clave}` }, c.etiqueta, c.obligatorio && h("span", { class: "obligatorio", "aria-hidden": "true" }, " *")),
      controles[c.clave], errores[c.clave]);
  });

  // Campo trampa para robots: oculto a las personas (y a los lectores de pantalla)
  const trampa = h("div", { class: "trampa", "aria-hidden": "true" },
    h("label", { for: "f-web" }, "Web"), h("input", { id: "f-web", name: "web", tabindex: "-1", autocomplete: "off" }));
  const enviar = h("button", { class: "boton boton--principal", type: "submit" }, "Enviar");
  const general = h("p", { class: "error", role: "alert" });

  const formulario = h("form", { class: "formulario", novalidate: true }, campos, trampa,
    h("p", { class: "nota campo--ancho" }, `Tus datos los recibirá ${def.empresa} solo para atender tu solicitud.`),
    general, h("div", { class: "acciones-formulario" }, enviar));

  formulario.addEventListener("submit", async (e) => {
    e.preventDefault();
    Object.values(errores).forEach((x) => (x.textContent = ""));
    Object.values(controles).forEach((x) => x.removeAttribute("aria-invalid"));
    general.textContent = "";
    enviar.disabled = true;
    try {
      const datos = Object.fromEntries(Object.entries(controles).map(([k, c]) => [k, c.value]));
      const r = await api("formulario-publico", { metodo: "POST", parametros: { f: slug }, cuerpo: { datos, web: trampa.querySelector("input").value } });
      contenedor.replaceChildren(h("div", { class: "vacio-estado" }, h("h1", {}, "¡Enviado!"), h("p", {}, r.mensaje)));
    } catch (error) {
      const campos = Object.keys(error.errores ?? {});
      campos.forEach((k) => { if (errores[k]) { errores[k].textContent = error.errores[k]; controles[k].setAttribute("aria-invalid", "true"); } });
      if (campos.length) controles[campos[0]]?.focus();
      else general.textContent = error.message;
      enviar.disabled = false;
    }
  });

  contenedor.replaceChildren(
    h("p", { class: "antetitulo" }, def.empresa),
    h("h1", {}, def.titulo),
    formulario);
} catch (error) {
  contenedor.replaceChildren(h("div", { class: "vacio-estado" }, h("h1", {}, "Formulario no disponible"), h("p", {}, error.message)));
}
