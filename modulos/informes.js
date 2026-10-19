// Informes (RA3, criterio c): resúmenes por periodo con totales y gráfico.
// Periodos rápidos (día, semana, mes, trimestre, año) con ◀ ▶ para moverse, o fechas a medida.
// Cada informe se puede filtrar, comparar con el periodo anterior o con el año pasado, imprimir o guardar
// como PDF y exportar a Excel, CSV o JSON.

import { api, urlApi } from "../nucleo/js/api.js";
import { h, icono, formato, cabeceraPagina, estadoVacio, aviso } from "../nucleo/js/dom.js";
import { tablaResultado, valorCelda } from "../nucleo/js/resultados.js";
import { graficoColumnas, graficoBarras } from "../nucleo/js/graficos.js";

const PERIODOS = [["dia", "Día"], ["semana", "Semana"], ["mes", "Mes"], ["trimestre", "Trimestre"], ["ano", "Año"], ["libre", "A medida"]];
// Agrupación que tiene sentido para cada periodo en «Ventas por periodo»
const AGRUPACION_DE = { dia: "dia", semana: "dia", mes: "dia", trimestre: "semana", ano: "mes", libre: "mes" };
const iso = (fecha) => fecha.toLocaleDateString("sv-SE");
const mesLargo = new Intl.DateTimeFormat("es-ES", { month: "long", year: "numeric" });
const diaLargo = new Intl.DateTimeFormat("es-ES", { weekday: "long", day: "numeric", month: "long", year: "numeric" });

// Se recuerda el último informe y periodo mientras dure la sesión
const estado = { informe: null, periodo: "mes", referencia: new Date(), desde: "", hasta: "", agrupacion: null, dias: 90, filtros: {}, comparar: "" };
const FILTROS = { cliente: "Cliente", producto: "Producto", categoria: "Categoría", proveedor: "Proveedor", estado: "Estado del pedido" };

// [desde, hasta] del periodo que contiene la fecha de referencia
function rango(periodo, ref) {
  const a = ref.getFullYear(), m = ref.getMonth(), d = ref.getDate();
  switch (periodo) {
    case "dia": return [new Date(a, m, d), new Date(a, m, d)];
    case "semana": {
      const lunes = new Date(a, m, d - ((ref.getDay() + 6) % 7));
      return [lunes, new Date(lunes.getFullYear(), lunes.getMonth(), lunes.getDate() + 6)];
    }
    case "trimestre": { const t = Math.floor(m / 3) * 3; return [new Date(a, t, 1), new Date(a, t + 3, 0)]; }
    case "ano": return [new Date(a, 0, 1), new Date(a, 11, 31)];
    default: return [new Date(a, m, 1), new Date(a, m + 1, 0)];
  }
}

function mover(periodo, ref, paso) {
  const a = ref.getFullYear(), m = ref.getMonth(), d = ref.getDate();
  return {
    dia: new Date(a, m, d + paso), semana: new Date(a, m, d + 7 * paso), mes: new Date(a, m + paso, 1),
    trimestre: new Date(a, m + 3 * paso, 1), ano: new Date(a + paso, 0, 1),
  }[periodo];
}

function nombrePeriodo(periodo, desde, hasta) {
  switch (periodo) {
    case "dia": return formato.capital(diaLargo.format(desde));
    case "semana": return `Semana del ${formato.fecha(iso(desde))} al ${formato.fecha(iso(hasta))}`;
    case "mes": return formato.capital(mesLargo.format(desde));
    case "trimestre": return `${Math.floor(desde.getMonth() / 3) + 1}.º trimestre de ${desde.getFullYear()}`;
    case "ano": return `Año ${desde.getFullYear()}`;
    default: return "";
  }
}

export async function vista() {
  const { informes, agrupaciones, comparaciones, opciones } = await api("informes");
  if (!informes.length) {
    return h("section", { class: "vista" },
      cabeceraPagina({ antetitulo: "Análisis", titulo: "Informes" }),
      h("div", { class: "tarjeta vidrio" }, estadoVacio("informes", "No hay informes disponibles", "Los informes dependen de los módulos activos y de tus permisos.")));
  }
  if (!informes.some((i) => i.id === estado.informe)) estado.informe = informes[0].id;

  const zona = h("div", { class: "informe-resultado" });
  const controles = h("div", { class: "tarjeta vidrio informe-controles no-imprimir" });
  const enlaces = {
    xlsx: h("a", { class: "boton", download: "" }, icono("descargar"), "Excel"),
    csv: h("a", { class: "boton", download: "" }, icono("descargar"), "CSV"),
    json: h("a", { class: "boton", download: "" }, icono("descargar"), "JSON"),
  };
  const imprimir = h("button", { class: "boton", type: "button", onclick: () => print() }, icono("imprimir"), "Imprimir / PDF");

  // Selector de informe agrupado por sección
  const grupos = [...new Set(informes.map((i) => i.grupo))];
  const selector = h("select", { id: "informe", "aria-label": "Informe", onchange: (e) => {
    estado.informe = e.target.value;
    // Los informes fiscales empiezan en su periodo natural (trimestre o año)
    if (actual().periodo_defecto) { estado.periodo = actual().periodo_defecto; estado.referencia = new Date(); estado.agrupacion = null; }
    pintarControles();
    cargar();
  } },
    grupos.map((g) => h("optgroup", { label: g }, informes.filter((i) => i.grupo === g).map((i) =>
      h("option", { value: i.id, selected: i.id === estado.informe }, i.titulo)))));

  function actual() { return informes.find((i) => i.id === estado.informe); }

  function parametros() {
    const p = { informe: estado.informe };
    const def = actual();
    if (def.parametros.includes("periodo")) {
      if (estado.periodo === "libre") Object.assign(p, { desde: estado.desde, hasta: estado.hasta });
      else { const [d, hh] = rango(estado.periodo, estado.referencia); Object.assign(p, { desde: iso(d), hasta: iso(hh) }); }
    }
    if (def.parametros.includes("agrupacion")) p.agrupacion = estado.agrupacion ?? AGRUPACION_DE[estado.periodo];
    if (def.parametros.includes("dias")) p.dias = estado.dias;
    for (const f of def.filtros) if (estado.filtros[f]) p[f] = estado.filtros[f];
    if (def.comparar && estado.comparar) p.comparar = estado.comparar;
    return p;
  }

  function pintarControles() {
    const def = actual();
    const filas = [h("div", { class: "campo" }, h("label", { for: "informe" }, "Informe"), selector)];
    if (def.parametros.includes("periodo")) {
      const [d, hh] = rango(estado.periodo, estado.referencia);
      filas.push(h("div", { class: "campo" }, h("span", { class: "etiqueta-campo" }, "Periodo"),
        h("div", { class: "segmentado", role: "group", "aria-label": "Periodo" }, PERIODOS.map(([valor, texto]) =>
          h("button", { type: "button", "aria-pressed": String(valor === estado.periodo), onclick: () => {
            if (valor === "libre" && !estado.desde) [estado.desde, estado.hasta] = [iso(d), iso(hh)];
            estado.periodo = valor;
            estado.agrupacion = null;
            pintarControles();
            cargar();
          } }, texto)))));
      filas.push(estado.periodo === "libre"
        ? h("div", { class: "filtro-fechas", role: "group", "aria-label": "Fechas" }, ["desde", "hasta"].map((l) =>
            h("label", {}, formato.capital(l), h("input", { type: "date", value: estado[l], onchange: (e) => { estado[l] = e.target.value; cargar(); } }))))
        : h("div", { class: "navegador-periodo" },
            h("button", { class: "boton-icono", type: "button", "aria-label": "Periodo anterior", onclick: () => { estado.referencia = mover(estado.periodo, estado.referencia, -1); pintarControles(); cargar(); } }, icono("anterior")),
            h("strong", { "aria-live": "polite" }, nombrePeriodo(estado.periodo, d, hh)),
            h("button", { class: "boton-icono", type: "button", "aria-label": "Periodo siguiente", onclick: () => { estado.referencia = mover(estado.periodo, estado.referencia, 1); pintarControles(); cargar(); } }, icono("siguiente")),
            h("button", { class: "boton boton--pequeno", type: "button", onclick: () => { estado.referencia = new Date(); pintarControles(); cargar(); } }, "Hoy")));
    }
    if (def.parametros.includes("agrupacion")) {
      filas.push(h("div", { class: "campo" }, h("label", { for: "agrupacion" }, "Agrupar por"),
        h("select", { id: "agrupacion", onchange: (e) => { estado.agrupacion = e.target.value; cargar(); } },
          Object.entries(agrupaciones).map(([v, t]) => h("option", { value: v, selected: v === (estado.agrupacion ?? AGRUPACION_DE[estado.periodo]) }, t)))));
    }
    if (def.parametros.includes("dias")) {
      filas.push(h("div", { class: "campo" }, h("label", { for: "dias" }, "Días sin comprar (más de)"),
        h("input", { id: "dias", type: "number", min: "1", max: "3650", value: estado.dias, onchange: (e) => { estado.dias = Number(e.target.value) || 90; cargar(); } })));
    }
    // Filtros del informe (cliente, producto, categoría…) y comparación
    const filtros = def.filtros.filter((f) => opciones[f]).map((f) => h("div", { class: "campo" }, h("label", { for: `filtro-${f}` }, FILTROS[f]),
      h("select", { id: `filtro-${f}`, onchange: (e) => { estado.filtros[f] = e.target.value; cargar(); } },
        h("option", { value: "" }, "Todos"),
        opciones[f].map((o) => h("option", { value: o.id, selected: String(o.id) === String(estado.filtros[f] ?? "") }, formato.capital(String(o.nombre)))))));
    if (def.comparar) {
      filtros.push(h("div", { class: "campo" }, h("label", { for: "comparar" }, "Comparar con"),
        h("select", { id: "comparar", onchange: (e) => { estado.comparar = e.target.value; cargar(); } },
          h("option", { value: "" }, "Sin comparar"),
          Object.entries(comparaciones).map(([v, t]) => h("option", { value: v, selected: v === estado.comparar }, t)))));
    }
    controles.replaceChildren(h("p", { class: "nota" }, def.descripcion), h("div", { class: "informe-filtros" }, filas),
      filtros.length > 0 && h("div", { class: "informe-filtros informe-filtros--secundarios" }, filtros));
  }

  let turno = 0;
  async function cargar() {
    const p = parametros();
    if (estado.periodo === "libre" && actual().parametros.includes("periodo") && (!p.desde || !p.hasta)) return;
    enlaces.xlsx.href = urlApi("informes", { ...p, formato: "xlsx" });
    enlaces.csv.href = urlApi("informes", { ...p, formato: "csv" });
    enlaces.json.href = urlApi("informes", { ...p, formato: "json" });
    const miTurno = ++turno;
    zona.setAttribute("aria-busy", "true");
    try {
      const r = await api("informes", { parametros: p });
      if (miTurno === turno) zona.replaceChildren(pintarResultado(r));
    } catch (error) {
      if (miTurno === turno) zona.replaceChildren(h("div", { class: "tarjeta vidrio" }, estadoVacio("alerta", "No se pudo generar el informe", error.message)));
      if (!error.errores || !Object.keys(error.errores).length) aviso(error.message, "error");
    } finally {
      zona.removeAttribute("aria-busy");
    }
  }

  pintarControles();
  await cargar();

  return h("section", { class: "vista vista-informes" },
    cabeceraPagina({
      antetitulo: "Análisis",
      titulo: "Informes",
      descripcion: "Resúmenes de ventas, facturación, clientes, inventario y compras por periodo, listos para imprimir o pasar a Excel.",
      acciones: [imprimir, enlaces.xlsx, enlaces.csv, enlaces.json],
    }),
    controles,
    zona,
  );
}

function pintarResultado(r) {
  const g = r.grafico;
  const columna = (clave) => r.columnas.find((c) => c.clave === clave);
  const grafico = g && r.filas.length > 1 && (g.tipo === "columnas"
    ? graficoColumnas(r.filas, { etiqueta: g.etiqueta, valor: g.valor, titulo: r.titulo, formatear: (v) => valorCelda(columna(g.valor).tipo, v), etiquetaCorta: etiquetaPeriodo })
    : graficoBarras(r.filas, { etiqueta: g.etiqueta, valor: g.valor, maximo: g.maximo, titulo: r.titulo, formatear: (v) => valorCelda(columna(g.valor).tipo, v) }));
  const periodo = r.parametros.desde ? `Del ${formato.fecha(r.parametros.desde)} al ${formato.fecha(r.parametros.hasta)}` : "Situación actual";

  return h("div", { class: "tarjeta vidrio informe" },
    h("header", { class: "informe-cabecera" },
      h("div", {},
        h("h2", {}, r.titulo),
        h("p", { class: "nota" }, periodo, r.parametros.dias ? ` · más de ${r.parametros.dias} días` : "")),
      h("p", { class: "nota informe-meta" },
        `${formato.numero(r.filas.length)} ${r.filas.length === 1 ? "fila" : "filas"} · generado el ${new Date(r.generado).toLocaleString("es-ES")} en ${formato.numero(r.ms)} ms`)),
    r.comparacion && h("div", { class: "comparacion" },
      h("span", {}, "Total: ", h("strong", {}, valorCelda("moneda", r.comparacion.actual))),
      h("span", {}, `${r.comparacion.nombre} (${formato.fecha(r.comparacion.desde)} – ${formato.fecha(r.comparacion.hasta)}): `, h("strong", {}, valorCelda("moneda", r.comparacion.anterior))),
      r.comparacion.variacion != null && h("span", { class: `variacion ${r.comparacion.variacion >= 0 ? "sube" : "baja"}` },
        `${r.comparacion.variacion >= 0 ? "▲" : "▼"} ${valorCelda("porcentaje", Math.abs(r.comparacion.variacion))}`)),
    grafico && h("div", { class: "informe-grafico" }, grafico),
    h("div", { class: "tabla-contenedor" }, tablaResultado(r, { titulo: r.titulo, vacio: "No hay datos en este periodo." })),
  );
}

// «2026-10» → «oct», «2026-S41» → «S41», «2026-10-05» → «5»
function etiquetaPeriodo(valor) {
  if (/^\d{4}-\d{2}$/.test(valor)) return new Intl.DateTimeFormat("es-ES", { month: "short" }).format(new Date(`${valor}-01T00:00:00`)).replace(".", "");
  if (/^\d{4}-S\d+$/.test(valor)) return valor.slice(5);
  if (/^\d{4}-\d{2}-\d{2}$/.test(valor)) return String(Number(valor.slice(8)));
  return valor;
}
