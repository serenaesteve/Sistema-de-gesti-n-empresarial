// Página de factura imprimible: factura.html?id=7
// Para obtener el PDF se usa la impresión del navegador («Guardar como PDF»).

import { api } from "./api.js";
import { h, formato } from "./dom.js";

const contenedor = document.getElementById("factura");
const imprimir = document.getElementById("imprimir");
const id = Number(new URLSearchParams(location.search).get("id"));

try {
  if (!id) throw new Error("Falta el número de factura en la dirección (factura.html?id=…).");
  const [factura, info] = await Promise.all([api("facturas", { id }), api("info")]);
  const empresa = info.empresa;

  const rectificativa = factura.tipo === "rectificativa";
  document.title = `Factura ${factura.numero} · ${empresa.nombre}`;
  // h() ignora los false, pero replaceChildren los escribiría como texto: se filtran
  contenedor.replaceChildren(...[
    h("header", { class: "cabecera" },
      h("div", {},
        h("p", { class: "logo" }, "serena", h("span", {}, "|"), h("em", {}, "blush")),
        h("p", { class: "emisor" },
          h("strong", {}, empresa.nombre), h("br"),
          `NIF ${empresa.nif}`, h("br"),
          empresa.direccion, h("br"),
          empresa.email)),
      h("div", { class: "titulo" },
        h("h1", {}, rectificativa ? "Factura rectificativa" : "Factura"),
        h("p", { class: "numero" }, factura.numero),
        h("p", {}, `Fecha: ${formato.fecha(factura.fecha)}`),
        factura.pedido_id && h("p", {}, `Pedido: #${factura.pedido_id}`))),

    rectificativa && h("section", { class: "aviso-rectificativa" },
      h("p", {}, h("strong", {}, `Rectifica a la factura ${factura.rectifica}. `), `Motivo: ${factura.motivo}`)),
    !rectificativa && factura.rectificada && h("section", { class: "aviso-rectificativa" },
      h("p", {}, h("strong", {}, "Factura anulada "), `por la rectificativa ${factura.rectificada}.`)),

    h("section", { class: "cliente" },
      h("p", { class: "etiqueta" }, "Facturar a"),
      h("p", {}, h("strong", {}, factura.cliente)),
      factura.nif && h("p", {}, `NIF ${factura.nif}`),
      factura.direccion && h("p", {}, factura.direccion)),

    h("table", { class: "lineas" },
      h("thead", {}, h("tr", {},
        h("th", {}, "Concepto"), h("th", { class: "numero" }, "Cantidad"),
        h("th", { class: "numero" }, "Precio"), h("th", { class: "numero" }, "Importe"))),
      h("tbody", {}, factura.lineas.map((l) => h("tr", {},
        h("td", {}, l.concepto),
        h("td", { class: "numero" }, formato.numero(l.cantidad)),
        h("td", { class: "numero" }, formato.moneda(l.precio)),
        h("td", { class: "numero" }, formato.moneda(l.importe)))))),

    h("dl", { class: "totales" },
      h("dt", {}, "Base imponible"), h("dd", {}, formato.moneda(factura.base)),
      h("dt", {}, `IVA ${factura.iva} %`), h("dd", {}, formato.moneda(factura.cuota)),
      h("dt", { class: "total" }, "Total"), h("dd", { class: "total" }, formato.moneda(factura.total))),

    h("footer", { class: "pie" },
      `${empresa.nombre} · NIF ${empresa.nif} · Factura emitida con serena | blush ERP-CRM. `,
      "Empresa y datos ficticios con fines educativos."),
  ].filter(Boolean));
  imprimir.disabled = false;
  imprimir.addEventListener("click", () => print());
} catch (error) {
  contenedor.replaceChildren(
    h("div", { class: "error" },
      h("h1", {}, "No se puede mostrar la factura"),
      h("p", {}, error.message),
      error.estado === 401 && h("a", { class: "boton boton--principal", href: "./" }, "Iniciar sesión")));
}
