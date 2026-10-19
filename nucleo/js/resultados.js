// Tabla de resultados para informes y consultas (RA3): columnas con tipo, filas y fila de totales.

import { h, formato } from "./dom.js";

const decimales = new Intl.NumberFormat("es-ES", { maximumFractionDigits: 2 });
const NUMERICOS = ["moneda", "entero", "decimal", "porcentaje"];

export function valorCelda(tipo, valor) {
  if (valor == null || valor === "") return "—";
  switch (tipo) {
    case "moneda": return formato.moneda(Number(valor));
    case "entero": return formato.numero(Number(valor));
    case "decimal": return decimales.format(Number(valor));
    case "porcentaje": return `${decimales.format(Number(valor))} %`;
    case "fecha": return /^\d{4}-\d{2}-\d{2}$/.test(valor) ? formato.fecha(valor) : valor;
    default: return String(valor);
  }
}

// Ordenable al pulsar la cabecera (en el navegador: los datos ya están aquí)
export function tablaResultado({ columnas, filas, totales = {} }, { titulo = "Resultado", vacio = "Sin resultados para estos filtros." } = {}) {
  const orden = { clave: null, asc: true };
  const cuerpo = h("tbody");
  const pintar = () => {
    let lista = filas;
    if (orden.clave) {
      const tipo = columnas.find((c) => c.clave === orden.clave)?.tipo;
      lista = [...filas].sort((a, b) => {
        const x = a[orden.clave], y = b[orden.clave];
        const r = NUMERICOS.includes(tipo) ? (Number(x) || 0) - (Number(y) || 0) : String(x ?? "").localeCompare(String(y ?? ""), "es");
        return orden.asc ? r : -r;
      });
    }
    cuerpo.replaceChildren(...(lista.length
      ? lista.map((f) => h("tr", {}, columnas.map((c) =>
          c.clave === "variacion" && f[c.clave] != null
            ? h("td", { class: `numero variacion ${f[c.clave] >= 0 ? "sube" : "baja"}` }, `${f[c.clave] > 0 ? "+" : ""}${valorCelda(c.tipo, f[c.clave])}`)
            : h("td", { class: NUMERICOS.includes(c.tipo) ? "numero" : "" }, valorCelda(c.tipo, f[c.clave])))))
      : [h("tr", {}, h("td", { class: "vacio", colspan: columnas.length }, vacio))]));
  };
  const cabeceras = columnas.map((c) => {
    const th = h("th", { scope: "col", class: NUMERICOS.includes(c.tipo) ? "numero" : "" },
      h("button", { class: "ordenar", type: "button", onclick: () => {
        orden.asc = orden.clave === c.clave ? !orden.asc : !NUMERICOS.includes(c.tipo);
        orden.clave = c.clave;
        cabeceras.forEach((otra) => otra.removeAttribute("aria-sort"));
        th.setAttribute("aria-sort", orden.asc ? "ascending" : "descending");
        pintar();
      } }, c.etiqueta, h("span", { class: "flecha", "aria-hidden": "true" }, "↑")));
    return th;
  });
  pintar();
  const hayTotales = Object.keys(totales ?? {}).length > 0 && filas.length > 0;
  return h("table", { class: "tabla-resultado" },
    h("caption", { class: "oculto" }, titulo),
    h("thead", {}, h("tr", {}, cabeceras)),
    cuerpo,
    hayTotales && h("tfoot", {}, h("tr", {}, columnas.map((c, i) =>
      h(i === 0 ? "th" : "td", { class: NUMERICOS.includes(c.tipo) ? "numero" : "", scope: i === 0 ? "row" : null },
        i === 0 ? "Total" : c.clave in totales ? valorCelda(c.tipo, totales[c.clave]) : "")))));
}
