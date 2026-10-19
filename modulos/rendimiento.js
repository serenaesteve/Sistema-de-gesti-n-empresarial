// Rendimiento (RA3, criterio f): estado del servidor y velocidad del ERP con los datos reales.
// Gráficos de CPU, memoria y disco, tiempos por recurso (media y percentil 95), errores,
// tamaño de las tablas y una prueba de velocidad bajo demanda.

import { api } from "../nucleo/js/api.js";
import { h, icono, formato, insignia, cabeceraPagina, aviso, descargar, celdaMd } from "../nucleo/js/dom.js";
import { graficoLineas } from "../nucleo/js/graficos.js";
import { pintarPlan } from "./consultas.js";

const RANGOS = [[1, "1 hora"], [24, "24 horas"], [168, "7 días"]];
let horasElegidas = 24;
const hora = new Intl.DateTimeFormat("es-ES", { hour: "2-digit", minute: "2-digit" });
const diaHora = new Intl.DateTimeFormat("es-ES", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });

export async function vista() {
  const d = await api("rendimiento", { parametros: { horas: horasElegidas } });
  const a = d.actual ?? {};
  const etiqueta = (v) => (horasElegidas > 24 ? diaHora : hora).format(formato.instante(v));
  const pruebas = h("div", {});

  const selector = h("div", { class: "segmentado", role: "group", "aria-label": "Intervalo" },
    RANGOS.map(([valor, texto]) => h("button", { type: "button", "aria-pressed": String(valor === horasElegidas), onclick: async () => {
      horasElegidas = valor;
      raiz.replaceWith(await vista());
    } }, texto)));

  const medir = h("button", { class: "boton boton--principal", type: "button", onclick: async () => {
    medir.disabled = true;
    medir.lastChild.textContent = "Midiendo…";
    try {
      const r = await api("rendimiento", { metodo: "POST", parametros: { accion: "medir" } });
      pruebas.replaceChildren(pintarPruebas(r));
      aviso(`${r.pruebas.length} pruebas en ${formato.numero(r.total)} ms`);
    } catch (error) {
      aviso(error.message, "error");
    }
    medir.disabled = false;
    medir.lastChild.textContent = "Medir velocidad";
  } }, icono("rendimiento"), h("span", {}, "Medir velocidad"));

  const indicador = (titulo, valor, nota, umbral) => h("div", { class: `tarjeta vidrio indicador ${umbral ? "alerta" : ""}` },
    h("span", { class: "titulo" }, titulo), h("span", { class: "valor" }, valor), h("span", { class: "nota" }, umbral && icono("alerta"), nota));
  const pct = (v) => (v == null ? "—" : `${String(v).replace(".", ",")} %`);

  const raiz = h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: "Análisis",
      titulo: "Rendimiento",
      descripcion: "Cómo responde el servidor y cuánto tarda cada parte del ERP, para detectar problemas antes de que se noten.",
      acciones: [selector, h("button", { class: "boton", type: "button", onclick: () => exportar(d) }, icono("descargar"), "Informe"), medir],
    }),

    d.avisos.length > 0 && h("div", { class: "tarjeta vidrio" }, h("ul", { class: "recomendaciones" },
      d.avisos.map((t) => h("li", { class: "aviso" }, h("span", { class: "icono" }, icono("alerta")), h("span", {}, t))))),

    h("div", { class: "rejilla rejilla--indicadores" },
      indicador("Procesador", pct(a.cpu), a.carga != null ? `Carga ${String(a.carga).replace(".", ",")}` : "", a.cpu >= d.umbrales.cpu),
      indicador("Memoria", pct(a.ram), "RAM en uso", a.ram >= d.umbrales.ram),
      indicador("Disco", pct(a.disco), `Base de datos: ${formato.numero(Math.round((a.bd_kb ?? 0) / 102.4) / 10)} MB`, a.disco >= d.umbrales.disco),
      indicador("Respuesta (p95)", `${formato.numero(d.resumen.p95)} ms`, `${formato.numero(d.resumen.peticiones)} peticiones · media ${formato.numero(d.resumen.media)} ms`, d.resumen.p95 >= d.umbrales.p95),
    ),

    h("div", { class: "rejilla rejilla--panel" },
      h("div", { class: "tarjeta vidrio" },
        h("h2", {}, "Servidor", h("small", {}, `${d.metricas.length} muestras`)),
        d.metricas.length > 1
          ? graficoLineas(d.metricas, { x: "fecha", maximo: 100, sufijo: " %", titulo: "Uso de CPU, memoria y disco (%)", etiquetaX: etiqueta,
              series: [{ clave: "cpu", nombre: "CPU", clase: "serie-1" }, { clave: "ram", nombre: "Memoria", clase: "serie-2" }, { clave: "disco", nombre: "Disco", clase: "serie-3" }] })
          : h("p", { class: "nota" }, "Hace falta más de una muestra. Activa el monitor con cron para tener el historial:"),
        h("pre", { class: "codigo" }, d.monitor)),
      h("div", { class: "tarjeta vidrio" },
        h("h2", {}, "Tiempo de respuesta", h("small", {}, "p95 por hora, en ms")),
        d.actividad.length > 1
          ? graficoLineas(d.actividad, { x: "hora", sufijo: " ms", titulo: "Tiempo de respuesta (p95) por hora", etiquetaX: (v) => diaHora.format(formato.instante(`${v}:00:00`)),
              series: [{ clave: "p95", nombre: "p95", clase: "serie-1" }] })
          : h("p", { class: "nota" }, "Se irá llenando con el uso: cada petición al ERP anota cuánto tarda."))),

    pruebas,

    tarjetaLentas(d, pruebas),

    h("div", { class: "rejilla rejilla--consultas" },
      h("div", { class: "tarjeta vidrio tabla-contenedor" },
        h("h2", {}, "Por recurso", h("small", {}, `Últimas ${RANGOS.find(([v]) => v === horasElegidas)[1]}`)),
        h("table", {},
          h("thead", {}, h("tr", {}, ["Recurso", "Peticiones", "Media", "p95", "Máximo", "Errores"].map((t, i) => h("th", { class: i ? "numero" : "" }, t)))),
          h("tbody", {}, d.recursos.length ? d.recursos.slice(0, 15).map((r) => h("tr", {},
            h("td", { class: "destacado" }, r.recurso),
            h("td", { class: "numero" }, formato.numero(r.peticiones)),
            h("td", { class: "numero" }, `${formato.numero(r.media)} ms`),
            h("td", { class: `numero ${r.p95 >= d.umbrales.p95 ? "negativo" : ""}` }, `${formato.numero(r.p95)} ms`),
            h("td", { class: "numero" }, `${formato.numero(r.maximo)} ms`),
            h("td", { class: "numero" }, r.errores ? insignia(String(r.errores), "rojo") : r.rechazos ? h("span", { class: "nota", title: "Respuestas 4xx: permisos, validación…" }, `${r.rechazos} 4xx`) : "0")))
            : h("tr", {}, h("td", { class: "vacio", colspan: "6" }, "Sin peticiones en este intervalo."))))),
      h("div", { class: "tarjeta vidrio tabla-contenedor" },
        h("h2", {}, "Tamaño de los datos"),
        h("table", {},
          h("thead", {}, h("tr", {}, h("th", {}, "Tabla"), h("th", { class: "numero" }, "Filas"))),
          h("tbody", {}, d.tablas.slice(0, 15).map((t) => h("tr", {}, h("td", {}, t.tabla), h("td", { class: "numero" }, formato.numero(t.filas)))))))),
  );
  return raiz;
}

// Consultas que pasaron del umbral, con su SQL y el botón «Explicar» (plan de la base de datos)
function tarjetaLentas(d, destino) {
  const origenes = { informe: "Informe", constructor: "Constructor", consola: "Consola SQL", listado: "Listado" };
  return h("div", { class: "tarjeta vidrio tabla-contenedor" },
    h("h2", {}, "Consultas lentas", h("small", {}, `Más de ${formato.numero(d.umbral_lenta)} ms · las 20 más lentas`)),
    d.lentas.length
      ? h("table", {},
          h("thead", {}, h("tr", {}, ["Origen", "Qué", "Consulta", "Tiempo", "Filas", ""].map((t, i) => h("th", { class: [3, 4].includes(i) ? "numero" : "" }, t)))),
          h("tbody", {}, d.lentas.map((l) => h("tr", {},
            h("td", {}, origenes[l.origen] ?? l.origen),
            h("td", { class: "destacado" }, l.detalle),
            h("td", { class: "sql-corto", title: l.sql }, l.sql.length > 90 ? `${l.sql.slice(0, 90)}…` : l.sql),
            h("td", { class: "numero" }, `${String(l.ms).replace(".", ",")} ms`),
            h("td", { class: "numero" }, formato.numero(l.filas)),
            h("td", { class: "acciones" }, h("button", { class: "boton boton--pequeno", type: "button", onclick: async () => {
              try {
                destino.replaceChildren(pintarPlan(await api("rendimiento", { parametros: { explicar: l.id } })));
                destino.scrollIntoView({ behavior: "smooth", block: "start" });
              } catch (error) { aviso(error.message, "error"); }
            } }, "Explicar")))))) 
      : h("p", { class: "nota" }, "Ninguna consulta ha pasado del umbral en este intervalo."));
}

function pintarPruebas(r) {
  const maximo = Math.max(...r.pruebas.map((p) => p.media), 1);
  return h("div", { class: "tarjeta vidrio" },
    h("h2", {}, "Prueba de velocidad", h("small", {}, `Cada consulta 5 veces · ${r.motor} · PHP ${r.php}`)),
    h("ul", { class: "barras" }, r.pruebas.map((p) => h("li", { class: "barra" },
      h("span", { class: "nombre" }, p.prueba),
      h("span", { class: "importe" }, `${String(p.media).replace(".", ",")} ms`),
      h("span", { class: "pista", style: { "--ancho": `${(p.media / maximo) * 100}%` }, "aria-hidden": "true" }, h("span", { class: "relleno" }))))),
    h("p", { class: "nota" }, `Total: ${formato.numero(r.total)} ms. Por debajo de 200 ms la espera no se nota. La prueba queda anotada en el Registro.`));
}

function exportar(d) {
  const a = d.actual ?? {};
  const lineas = [
    "# Informe de rendimiento · Blush ERP", "",
    `Generado el ${new Date().toLocaleString("es-ES")} · intervalo: últimas ${d.horas} horas.`, "",
    "## Servidor", "",
    `- CPU: ${a.cpu ?? "—"} % · memoria: ${a.ram ?? "—"} % · disco: ${a.disco ?? "—"} % · carga: ${a.carga ?? "—"}`,
    `- Base de datos: ${a.bd_kb ?? "—"} KB`, "",
    "## Peticiones", "",
    `- ${d.resumen.peticiones} peticiones · media ${d.resumen.media} ms · p95 ${d.resumen.p95} ms · ${d.resumen.errores} errores del servidor`, "",
    "| Recurso | Peticiones | Media (ms) | p95 (ms) | Máximo (ms) | Errores |", "|---|---:|---:|---:|---:|---:|",
    ...d.recursos.map((r) => `| ${celdaMd(r.recurso)} | ${r.peticiones} | ${r.media} | ${r.p95} | ${r.maximo} | ${r.errores} |`), "",
    "## Avisos", "", ...(d.avisos.length ? d.avisos.map((t) => `- ${t}`) : ["- Ninguno."]), "",
  ];
  descargar(`rendimiento-${new Date().toLocaleDateString("sv-SE")}.md`, lineas.join("\n"));
}
