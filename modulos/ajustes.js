// Ajustes de la instalación (RA2): datos de la empresa y parámetros de cada módulo.
// Los de un módulo inactivo no se muestran, pero se conservan.

import { api } from "../nucleo/js/api.js";
import { h, formato, cabeceraPagina, aviso } from "../nucleo/js/dom.js";

export async function vista({ info }) {
  const ajustes = await api("ajustes");
  const activo = (m) => info.modulos.includes(m);
  const errores = {};

  // Campo de formulario; «nombre» admite rutas como empresa.nombre
  const campo = (nombre, etiqueta, valor, atributos = {}, ayuda = "") => {
    const id = `ajuste-${nombre.replace(".", "-")}`;
    errores[nombre] = h("span", { class: "error", id: `${id}-error` });
    const control = atributos.opciones
      ? h("select", { id, name: nombre, "aria-describedby": `${id}-error` },
          atributos.opciones.map((o) => h("option", { value: o, selected: o === valor }, o)))
      : h("input", { id, name: nombre, value: valor ?? "", "aria-describedby": `${id}-error`, ...atributos });
    return h("div", { class: "campo" },
      h("label", { for: id }, etiqueta, atributos.required && h("span", { class: "obligatorio", "aria-hidden": "true" }, " *")),
      control, ayuda && h("small", { class: "ayuda" }, ayuda), errores[nombre]);
  };

  const seccion = (titulo, descripcion, ...campos) =>
    h("fieldset", { class: "tarjeta vidrio ajustes-seccion" },
      h("legend", {}, titulo), h("p", { class: "nota" }, descripcion),
      h("div", { class: "formulario" }, campos));

  const guardar = h("button", { class: "boton boton--principal", type: "submit" }, "Guardar ajustes");
  const formulario = h("form", { class: "ajustes", novalidate: true },
    seccion("Empresa", "Aparece en las facturas y en el menú.",
      campo("empresa.nombre", "Razón social", ajustes.empresa.nombre, { required: true, maxlength: "255" }),
      campo("empresa.nif", "NIF / CIF", ajustes.empresa.nif, { maxlength: "20" }),
      campo("empresa.direccion", "Dirección", ajustes.empresa.direccion, { maxlength: "255" }),
      campo("empresa.email", "Email", ajustes.empresa.email, { type: "email" }),
    ),
    activo("facturacion") && seccion("Facturación", "Se aplica a las facturas que se emitan a partir de ahora; las ya emitidas no cambian.",
      campo("iva", "IVA (%)", ajustes.iva, { type: "number", min: "0", max: "100", step: "1" }, "General 21 %, reducido 10 %, superreducido 4 %"),
      campo("serie_factura", "Serie de facturas", ajustes.serie_factura, { maxlength: "3" }, `Ejemplo: ${ajustes.serie_factura}2026-0001`),
      campo("serie_rectificativa", "Serie de rectificativas", ajustes.serie_rectificativa, { maxlength: "3" }, `Ejemplo: ${ajustes.serie_rectificativa}2026-0001`),
    ),
    activo("inventario") && seccion("Inventario", "Por debajo de este stock, el producto aparece en «Stock bajo».",
      campo("stock_minimo", "Stock mínimo (uds.)", ajustes.stock_minimo, { type: "number", min: "0", step: "1" }),
    ),
    seccion("Interfaz", "Preferencias comunes a todos los usuarios.",
      campo("por_pagina", "Filas por página", String(ajustes.por_pagina), { opciones: ["10", "15", "25", "50"] }),
    ),
    seccion("Entorno", "Desarrollo y pruebas muestran una franja de aviso; en explotación se ocultan los errores y las comprobaciones de seguridad son más estrictas.",
      h("div", { class: "campo campo--ancho perfiles perfiles--tres", role: "radiogroup", "aria-label": "Entorno" },
        Object.entries(info.entornos).map(([valor, texto]) => h("label", { class: `perfil ${ajustes.entorno === valor ? "elegido" : ""}` },
          h("input", { type: "radio", name: "entorno", value: valor, checked: ajustes.entorno === valor,
            onchange: (e) => e.target.closest("[role=radiogroup]").querySelectorAll(".perfil").forEach((p) => p.classList.toggle("elegido", p.contains(e.target))) }),
          h("span", { class: "perfil-texto" }, h("strong", {}, formato.capital(valor === "explotacion" ? "explotación" : valor)), h("small", {}, texto.split(": ")[1]))))),
      errores.entorno = h("span", { class: "error" }),
    ),
    h("div", { class: "ajustes-pie" }, guardar),
  );

  formulario.addEventListener("submit", async (evento) => {
    evento.preventDefault();
    Object.values(errores).forEach((e) => (e.textContent = ""));
    formulario.querySelectorAll("[aria-invalid]").forEach((c) => c.removeAttribute("aria-invalid"));
    const valor = (nombre, defecto) => formulario.elements[nombre]?.value ?? defecto;
    const cuerpo = {
      empresa: Object.fromEntries(["nombre", "nif", "direccion", "email"].map((c) => [c, valor(`empresa.${c}`, "")])),
      iva: valor("iva", ajustes.iva),
      serie_factura: valor("serie_factura", ajustes.serie_factura),
      serie_rectificativa: valor("serie_rectificativa", ajustes.serie_rectificativa),
      stock_minimo: valor("stock_minimo", ajustes.stock_minimo),
      por_pagina: valor("por_pagina", ajustes.por_pagina),
      entorno: formulario.querySelector("[name=entorno]:checked")?.value ?? ajustes.entorno,
    };
    guardar.disabled = true;
    try {
      await api("ajustes", { metodo: "POST", cuerpo });
      aviso("Ajustes guardados");
      setTimeout(() => location.reload(), 700); // empresa, paginación y stock mínimo se usan en toda la aplicación
    } catch (error) {
      const campos = Object.keys(error.errores ?? {});
      if (!campos.length) aviso(error.message, "error");
      campos.forEach((c) => {
        if (errores[c]) errores[c].textContent = error.errores[c];
        formulario.elements[c]?.setAttribute("aria-invalid", "true");
      });
      formulario.querySelector("[aria-invalid]")?.focus();
    } finally {
      guardar.disabled = false;
    }
  });

  return h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: "Administración",
      titulo: "Ajustes",
      descripcion: "Parámetros de la instalación. Los cambios quedan anotados en el registro de operaciones.",
    }),
    formulario,
  );
}
