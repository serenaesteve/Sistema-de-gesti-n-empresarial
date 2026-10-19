// Gráficos sencillos para informes, análisis y rendimiento (RA3), sin librerías externas.
// Columnas y barras reutilizan el estilo del panel; las líneas se dibujan en SVG.
// Cada gráfico lleva una tabla oculta con los mismos datos para lectores de pantalla.

import { h } from "./dom.js";

const SVG = "http://www.w3.org/2000/svg";

function s(etiqueta, atributos = {}, ...hijos) {
  const elemento = document.createElementNS(SVG, etiqueta);
  for (const [clave, valor] of Object.entries(atributos)) if (valor != null) elemento.setAttribute(clave, valor);
  elemento.append(...hijos.flat().filter((x) => x != null).map((x) => (x instanceof Node ? x : document.createTextNode(String(x)))));
  return elemento;
}

function tablaOculta(titulo, filas, etiqueta, valor, formatear) {
  return h("table", { class: "oculto" },
    h("caption", {}, titulo),
    h("tbody", {}, filas.map((f) => h("tr", {}, h("th", {}, f[etiqueta]), h("td", {}, formatear(f[valor]))))));
}

// Columnas verticales (una por periodo). Si hay muchas, las etiquetas se espacian.
export function graficoColumnas(filas, { etiqueta, valor, formatear, titulo = "", etiquetaCorta = (x) => x, clase = () => "" }) {
  const maximo = Math.max(...filas.map((f) => f[valor] ?? 0), 1);
  const mayor = filas.reduce((a, b) => ((b[valor] ?? 0) > (a[valor] ?? 0) ? b : a), filas[0]);
  const salto = Math.ceil(filas.length / 12);
  return h("div", {},
    h("div", { class: `columnas ${filas.length > 12 ? "columnas--densas" : ""}`, "aria-hidden": "true", style: { "grid-template-columns": `repeat(${filas.length}, minmax(0, 1fr))` } },
      filas.map((f, i) => h("div", { class: `columna ${f === mayor ? "columna--mayor" : ""} ${clase(f)}`, tabindex: "0", "data-tip": `${f[etiqueta]}: ${formatear(f[valor])}` },
        h("span", { class: "columna-pista", style: { "--alto": `${((f[valor] ?? 0) / maximo) * 100}%` } },
          f === mayor && filas.length <= 12 && h("span", { class: "columna-valor" }, formatear(f[valor])),
          h("span", { class: "columna-relleno" })),
        h("span", { class: "columna-mes" }, i % salto === 0 ? etiquetaCorta(f[etiqueta]) : " ")))),
    tablaOculta(titulo, filas, etiqueta, valor, formatear));
}

// Barras horizontales (ranking: clientes, productos, proveedores)
export function graficoBarras(filas, { etiqueta, valor, formatear, maximo: tope = 10, titulo = "" }) {
  const lista = filas.slice(0, tope);
  const maximo = Math.max(...lista.map((f) => f[valor] ?? 0), 1);
  return h("div", {},
    h("ul", { class: "barras", "aria-hidden": "true" }, lista.map((f) => h("li", { class: "barra" },
      h("span", { class: "nombre" }, f[etiqueta] ?? "—"),
      h("span", { class: "importe" }, formatear(f[valor])),
      h("span", { class: "pista", style: { "--ancho": `${((f[valor] ?? 0) / maximo) * 100}%` } }, h("span", { class: "relleno" }))))),
    tablaOculta(titulo, lista, etiqueta, valor, formatear));
}

// Líneas en el tiempo (rendimiento). series: [{clave, nombre, clase}] sobre las mismas filas.
export function graficoLineas(filas, { x, series, maximo, sufijo = "", titulo = "", etiquetaX = (v) => v }) {
  const ancho = 640, alto = 180, margen = { arriba: 10, derecha: 8, abajo: 22, izquierda: 36 };
  const util = { ancho: ancho - margen.izquierda - margen.derecha, alto: alto - margen.arriba - margen.abajo };
  const tope = maximo ?? Math.max(1, ...filas.flatMap((f) => series.map((s) => f[s.clave] ?? 0))) * 1.1;
  const px = (i) => margen.izquierda + (filas.length < 2 ? util.ancho / 2 : (i / (filas.length - 1)) * util.ancho);
  const py = (v) => margen.arriba + util.alto - (Math.min(v, tope) / tope) * util.alto;
  const marcas = [0, 0.25, 0.5, 0.75, 1].map((p) => tope * p);
  const decimales = tope < 10 ? 1 : 0;

  const svg = s("svg", { viewBox: `0 0 ${ancho} ${alto}`, class: "grafico-lineas", role: "img", "aria-label": titulo },
    marcas.map((m) => s("g", {},
      s("line", { x1: margen.izquierda, x2: ancho - margen.derecha, y1: py(m), y2: py(m), class: "rejilla-linea" }),
      s("text", { x: margen.izquierda - 6, y: py(m) + 4, class: "eje", "text-anchor": "end" }, m.toFixed(decimales)))),
    [0, Math.floor((filas.length - 1) / 2), filas.length - 1].filter((v, i, a) => filas[v] && a.indexOf(v) === i).map((i) =>
      s("text", { x: px(i), y: alto - 4, class: "eje", "text-anchor": i === 0 ? "start" : i === filas.length - 1 ? "end" : "middle" }, etiquetaX(filas[i][x]))),
    series.map((serie) => {
      const puntos = filas.map((f, i) => (f[serie.clave] == null ? null : `${px(i).toFixed(1)},${py(f[serie.clave]).toFixed(1)}`)).filter(Boolean);
      return s("g", { class: `serie ${serie.clase ?? ""}` },
        s("polyline", { points: puntos.join(" "), fill: "none" }),
        filas.length <= 60 && filas.map((f, i) => f[serie.clave] != null && s("circle", { cx: px(i), cy: py(f[serie.clave]), r: 2.5 },
          s("title", {}, `${etiquetaX(f[x])} · ${serie.nombre}: ${f[serie.clave]}${sufijo}`))));
    }));

  return h("div", { class: "grafico" },
    svg,
    series.length > 1 && h("p", { class: "leyenda" }, series.map((serie) => h("span", { class: `leyenda-item ${serie.clase ?? ""}` }, serie.nombre))),
    h("table", { class: "oculto" }, h("caption", {}, titulo),
      h("thead", {}, h("tr", {}, h("th", {}, "Momento"), series.map((serie) => h("th", {}, serie.nombre)))),
      h("tbody", {}, filas.slice(-30).map((f) => h("tr", {}, h("th", {}, etiquetaX(f[x])), series.map((serie) => h("td", {}, f[serie.clave] ?? "—")))))));
}
