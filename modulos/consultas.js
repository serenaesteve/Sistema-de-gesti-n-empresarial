// Consultas (RA3, criterio a): herramientas para consultar y extraer datos.
//
// · Constructor: elegir datos, columnas, filtros, agrupación y orden sin escribir SQL;
//   se muestra la sentencia SQL que genera el ERP para aprender a escribirla.
// · Consola SQL (solo administración): SELECT de solo lectura sobre las tablas del ERP.
// · Consultas guardadas: se repiten con un clic y se exportan a Excel, CSV o JSON.
// · Tabla cruzada (agrupar por dos campos) y «Explicar»: cómo ejecuta la base de datos la consulta.

import { api, urlApi } from "../nucleo/js/api.js";
import { h, icono, formato, cabeceraPagina, estadoVacio, aviso, confirmar } from "../nucleo/js/dom.js";
import { tablaResultado } from "../nucleo/js/resultados.js";

const OPERADORES = {
  igual: "es igual a", distinto: "es distinto de", mayor: "es mayor que", menor: "es menor que",
  mayor_igual: "es mayor o igual que", menor_igual: "es menor o igual que", contiene: "contiene", empieza: "empieza por",
  vacio: "está vacío", no_vacio: "no está vacío",
};
const AGREGACIONES = { contar: "Contar registros", suma: "Sumar", media: "Media de", minimo: "Mínimo de", maximo: "Máximo de" };
const NUMERICOS = ["moneda", "entero", "decimal"];
const EJEMPLOS_SQL = [
  ["Clientes por ciudad", "SELECT ciudad, COUNT(*) AS clientes\nFROM clientes\nGROUP BY ciudad\nORDER BY clientes DESC"],
  ["Ventas por mes", "SELECT SUBSTR(p.fecha, 1, 7) AS mes, ROUND(SUM(l.cantidad * l.precio), 2) AS importe\nFROM pedidos p JOIN pedido_lineas l ON l.pedido_id = p.id\nWHERE p.estado <> 'cancelado'\nGROUP BY mes\nORDER BY mes"],
  ["Productos más vendidos", "SELECT pr.nombre, SUM(l.cantidad) AS unidades\nFROM pedido_lineas l JOIN productos pr ON pr.id = l.producto_id\nGROUP BY pr.id, pr.nombre\nORDER BY unidades DESC\nLIMIT 10"],
];

// Se conserva la consulta que se estaba construyendo al cambiar de pantalla
const borrador = { modo: "constructor", definicion: null, sql: EJEMPLOS_SQL[0][1] };

export async function vista() {
  const datos = await api("consultas");
  if (!datos.recursos.length) {
    return h("section", { class: "vista" }, cabeceraPagina({ antetitulo: "Análisis", titulo: "Consultas" }),
      h("div", { class: "tarjeta vidrio" }, estadoVacio("consultas", "No hay datos que consultar", "Tu rol no puede ver ningún módulo activo.")));
  }
  if (!datos.sql) borrador.modo = "constructor";
  const recursos = Object.fromEntries(datos.recursos.map((r) => [r.id, r]));
  const def = borrador.definicion ??= { recurso: datos.recursos[0].id, columnas: [], filtros: [], agrupar: "", agregacion: "contar", sobre: "id", orden: "", dir: "desc", limite: 100 };
  if (!recursos[def.recurso]) Object.assign(def, { recurso: datos.recursos[0].id, columnas: [], filtros: [], agrupar: "", orden: "" });

  const editor = h("div", { class: "tarjeta vidrio consulta-editor" });
  const resultado = h("div", {});
  let ultima = null;   // {tipo, definicion|sql}

  // ---------- Constructor ----------

  function pintarConstructor() {
    const r = recursos[def.recurso];
    const campos = r.campos;
    if (!def.columnas.length) def.columnas = campos.slice(0, 6).map((c) => c.clave);
    const opcionesCampo = (seleccion, filtro = () => true, vacio = null) => [
      vacio && h("option", { value: "" }, vacio),
      ...campos.filter(filtro).map((c) => h("option", { value: c.clave, selected: c.clave === seleccion }, c.etiqueta))];

    const filtros = h("div", { class: "consulta-filtros" }, def.filtros.map((f, i) => h("div", { class: "consulta-filtro" },
      h("select", { "aria-label": "Campo", onchange: (e) => { f.campo = e.target.value; } }, opcionesCampo(f.campo)),
      h("select", { "aria-label": "Condición", onchange: (e) => { f.operador = e.target.value; pintarConstructor(); } },
        Object.entries(OPERADORES).map(([v, t]) => h("option", { value: v, selected: v === f.operador }, t))),
      !["vacio", "no_vacio"].includes(f.operador) && h("input", { "aria-label": "Valor", value: f.valor ?? "", placeholder: "Valor", oninput: (e) => { f.valor = e.target.value; } }),
      h("button", { class: "boton-icono", type: "button", "aria-label": "Quitar filtro", onclick: () => { def.filtros.splice(i, 1); pintarConstructor(); } }, icono("quitar")))));

    const agrupando = def.agrupar !== "";
    editor.replaceChildren(
      h("form", { class: "formulario", onsubmit: (e) => { e.preventDefault(); ejecutar({ tipo: "constructor", definicion: structuredClone(def) }); } },
        h("div", { class: "campo" }, h("label", { for: "c-recurso" }, "Datos"),
          h("select", { id: "c-recurso", onchange: (e) => { Object.assign(def, { recurso: e.target.value, columnas: [], filtros: [], agrupar: "", sobre: "id", orden: "" }); pintarConstructor(); } },
            datos.recursos.map((x) => h("option", { value: x.id, selected: x.id === def.recurso }, x.titulo)))),
        h("div", { class: "campo" }, h("label", { for: "c-limite" }, "Máximo de filas"),
          h("input", { id: "c-limite", type: "number", min: "1", max: String(datos.limite), value: def.limite, onchange: (e) => { def.limite = Number(e.target.value) || 100; } })),

        !agrupando && h("fieldset", { class: "campo campo--ancho" }, h("legend", {}, "Columnas"),
          h("div", { class: "rejilla-casillas" }, campos.map((c) => h("label", { class: "check" },
            h("input", { type: "checkbox", checked: def.columnas.includes(c.clave), onchange: (e) => {
              def.columnas = e.target.checked ? [...def.columnas, c.clave] : def.columnas.filter((x) => x !== c.clave);
            } }), h("span", {}, c.etiqueta))))),

        h("fieldset", { class: "campo campo--ancho" }, h("legend", {}, "Filtros ", h("small", {}, "(se cumplen todos)")),
          filtros,
          h("button", { class: "boton boton--pequeno", type: "button", onclick: () => { def.filtros.push({ campo: campos[1]?.clave ?? "id", operador: "contiene", valor: "" }); pintarConstructor(); } },
            icono("crear"), "Añadir filtro")),

        h("div", { class: "campo" }, h("label", { for: "c-agrupar" }, "Agrupar por"),
          h("select", { id: "c-agrupar", onchange: (e) => { def.agrupar = e.target.value; def.orden = ""; if (!def.agrupar || def.cruzar === def.agrupar) def.cruzar = ""; pintarConstructor(); } },
            opcionesCampo(def.agrupar, (c) => c.clave !== "id", "Sin agrupar (ver registros)"))),
        agrupando && h("div", { class: "campo" }, h("label", { for: "c-cruzar" }, "En columnas (tabla cruzada)"),
          h("select", { id: "c-cruzar", onchange: (e) => { def.cruzar = e.target.value; pintarConstructor(); } },
            opcionesCampo(def.cruzar ?? "", (c) => c.clave !== "id" && c.clave !== def.agrupar, "Sin tabla cruzada"))),
        agrupando && h("div", { class: "campo" }, h("label", { for: "c-agregacion" }, "Calcular"),
          h("div", { class: "consulta-agregacion" },
            h("select", { id: "c-agregacion", onchange: (e) => { def.agregacion = e.target.value; pintarConstructor(); } },
              Object.entries(AGREGACIONES).map(([v, t]) => h("option", { value: v, selected: v === def.agregacion }, t))),
            def.agregacion !== "contar" && h("select", { "aria-label": "Campo a calcular", onchange: (e) => { def.sobre = e.target.value; } },
              opcionesCampo(def.sobre, (c) => NUMERICOS.includes(c.tipo) && c.clave !== "id")))),

        !def.cruzar && h("div", { class: "campo" }, h("label", { for: "c-orden" }, "Ordenar por"),
          h("div", { class: "consulta-agregacion" },
            h("select", { id: "c-orden", onchange: (e) => { def.orden = e.target.value; } },
              agrupando ? [h("option", { value: "" }, "Resultado"), ...opcionesCampo(def.orden, (c) => c.clave === def.agrupar)] : opcionesCampo(def.orden, (c) => def.columnas.includes(c.clave), "Más recientes primero")),
            h("select", { "aria-label": "Dirección", onchange: (e) => { def.dir = e.target.value; } },
              h("option", { value: "desc", selected: def.dir === "desc" }, "Descendente"), h("option", { value: "asc", selected: def.dir === "asc" }, "Ascendente")))),

        h("div", { class: "acciones-formulario" },
          h("button", { class: "boton", type: "button", title: "Cómo la ejecuta la base de datos (si usa índices)", onclick: () => ejecutar({ tipo: "constructor", definicion: structuredClone(def) }, true) }, icono("buscar"), "Explicar"),
          h("button", { class: "boton boton--principal", type: "submit" }, icono("ejecutar"), "Ejecutar consulta"))));
  }

  // ---------- Consola SQL ----------

  function pintarSql() {
    const area = h("textarea", { id: "c-sql", class: "codigo editor-sql", rows: "8", spellcheck: "false", oninput: (e) => { borrador.sql = e.target.value; } }, borrador.sql);
    editor.replaceChildren(
      h("form", { class: "formulario formulario--una", onsubmit: (e) => { e.preventDefault(); ejecutar({ tipo: "sql", sql: area.value }); } },
        h("div", { class: "campo" }, h("label", { for: "c-sql" }, "Consulta SQL (solo lectura)"), area,
          h("span", { class: "ayuda" }, `Solo SELECT, una sentencia y como mucho ${formato.numero(datos.limite)} filas. Las tablas de usuarios y claves están protegidas. Se ejecuta en una conexión de solo lectura: no puede cambiar nada.`)),
        h("div", { class: "consulta-ejemplos" }, h("span", { class: "nota" }, "Ejemplos:"), EJEMPLOS_SQL.map(([t, sql]) =>
          h("button", { class: "boton boton--pequeno", type: "button", onclick: () => { borrador.sql = sql; area.value = sql; } }, t))),
        h("div", { class: "acciones-formulario" },
          h("button", { class: "boton", type: "button", title: "Cómo la ejecuta la base de datos (si usa índices)", onclick: () => ejecutar({ tipo: "sql", sql: area.value }, true) }, icono("buscar"), "Explicar"),
          h("button", { class: "boton boton--principal", type: "submit" }, icono("ejecutar"), "Ejecutar"))));
  }

  function pintarEditor() { (borrador.modo === "sql" ? pintarSql : pintarConstructor)(); }

  // ---------- Ejecutar y mostrar ----------

  function parametrosDe(consulta) {
    if (consulta.guardada) return { guardada: consulta.guardada };
    return consulta.tipo === "sql" ? { sql: consulta.sql } : { definicion: JSON.stringify(consulta.definicion) };
  }

  async function ejecutar(consulta, explicar = false) {
    ultima = consulta;
    resultado.setAttribute("aria-busy", "true");
    try {
      const r = await api("consultas", { parametros: { ...parametrosDe(consulta), explicar: explicar ? 1 : null } });
      resultado.replaceChildren(explicar ? pintarPlan(r) : pintarResultado(r, consulta));
    } catch (error) {
      const detalle = Object.values(error.errores ?? {})[0];
      resultado.replaceChildren(h("div", { class: "tarjeta vidrio" }, estadoVacio("alerta", error.message, detalle)));
    } finally {
      resultado.removeAttribute("aria-busy");
    }
  }

  function pintarResultado(r, consulta) {
    const p = parametrosDe(consulta);
    return h("div", { class: "tarjeta vidrio" },
      h("h2", {}, "Resultado", h("small", {}, `${formato.numero(r.filas.length)} filas · ${formato.numero(r.ms)} ms${r.recortado ? ` · recortado a ${formato.numero(r.limite)}` : ""}`)),
      h("details", { class: "sql-generado", open: consulta.tipo === "constructor" },
        h("summary", {}, consulta.tipo === "sql" ? "Consulta ejecutada" : "SQL generado por el constructor"),
        h("pre", { class: "codigo" }, r.sql)),
      r.cruce && h("p", { class: "nota" }, `Columnas: ${r.cruce.columna.toLowerCase()}${r.cruce.recortada ? " (solo las 24 primeras)" : ""}.`),
      h("div", { class: "pagina-acciones" },
        h("a", { class: "boton", download: "", href: urlApi("consultas", { ...p, formato: "xlsx" }) }, icono("descargar"), "Excel"),
        h("a", { class: "boton", download: "", href: urlApi("consultas", { ...p, formato: "csv" }) }, icono("descargar"), "CSV"),
        h("a", { class: "boton", download: "", href: urlApi("consultas", { ...p, formato: "json" }) }, icono("descargar"), "JSON"),
        !consulta.guardada && h("button", { class: "boton", type: "button", onclick: () => guardar(consulta) }, icono("guardar"), "Guardar consulta")),
      h("div", { class: "tabla-contenedor" }, tablaResultado(r, { titulo: "Resultado de la consulta" })));
  }

  async function guardar(consulta) {
    const nombre = await pedirNombre();
    if (!nombre) return;
    try {
      await api("consultas", { metodo: "POST", cuerpo: { nombre, tipo: consulta.tipo, definicion: consulta.tipo === "sql" ? { sql: consulta.sql } : consulta.definicion } });
      aviso("Consulta guardada");
      raiz.replaceWith(await vista());
    } catch (error) {
      aviso(Object.values(error.errores ?? {})[0] ?? error.message, "error");
    }
  }

  // ---------- Guardadas ----------

  const guardadas = h("div", { class: "tarjeta vidrio" },
    h("h2", {}, "Consultas guardadas", h("small", {}, `${datos.guardadas.length}`)),
    datos.guardadas.length
      ? h("ul", { class: "lista" }, datos.guardadas.map((c) => h("li", {},
          h("div", { class: "principal" },
            h("strong", {}, c.nombre),
            h("small", {}, `${c.tipo === "sql" ? "SQL" : recursos[c.definicion.recurso]?.titulo ?? c.definicion.recurso} · ${c.usuario ?? "—"} · ${formato.fechaHora(c.creado)}`)),
          h("button", { class: "boton boton--pequeno", type: "button", onclick: () => {
            if (c.tipo === "sql") { borrador.modo = "sql"; borrador.sql = c.definicion.sql; }
            else { borrador.modo = "constructor"; Object.assign(def, structuredClone(c.definicion)); }
            pintarModos(); pintarEditor();
            ejecutar({ tipo: c.tipo, guardada: c.id });
          } }, icono("ejecutar"), "Ejecutar"),
          h("a", { class: "boton boton--pequeno", download: "", href: urlApi("consultas", { guardada: c.id, formato: "csv" }) }, "CSV"),
          h("button", { class: "boton-icono", type: "button", "aria-label": `Borrar «${c.nombre}»`, onclick: async () => {
            if (!(await confirmar({ titulo: `Borrar «${c.nombre}»`, mensaje: "La consulta guardada se borrará. Los datos no cambian." }))) return;
            await api("consultas", { metodo: "DELETE", id: c.id });
            aviso("Consulta borrada");
            raiz.replaceWith(await vista());
          } }, icono("borrar")))))
      : h("p", { class: "nota" }, "Guarda una consulta para repetirla o exportarla cuando quieras."));

  // ---------- Modo ----------

  const modos = h("div", { class: "segmentado", role: "group", "aria-label": "Herramienta" });
  function pintarModos() {
    modos.replaceChildren(...[["constructor", "Constructor"], datos.sql && ["sql", "Consola SQL"]].filter(Boolean).map(([v, t]) =>
      h("button", { type: "button", "aria-pressed": String(borrador.modo === v), onclick: () => { borrador.modo = v; pintarModos(); pintarEditor(); } }, t)));
  }
  pintarModos();
  pintarEditor();

  const raiz = h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: "Análisis",
      titulo: "Consultas",
      descripcion: datos.sql
        ? "Saca cualquier dato del ERP: con el constructor, sin escribir código, o con SQL de solo lectura."
        : "Saca cualquier dato del ERP con el constructor, sin escribir código.",
      acciones: [modos],
    }),
    h("div", { class: "rejilla rejilla--consultas" }, editor, guardadas),
    resultado,
  );
  return raiz;
}

function pedirNombre() {
  return new Promise((resolver) => {
    const campo = h("input", { id: "consulta-nombre", maxlength: "100", required: true, placeholder: "Clientes de Valencia, ventas por mes…" });
    const dialogo = h("dialog", { class: "modal", "aria-labelledby": "consulta-titulo" },
      h("h2", { id: "consulta-titulo" }, "Guardar consulta"),
      h("form", { method: "dialog", class: "formulario formulario--una" },
        h("div", { class: "campo" }, h("label", { for: "consulta-nombre" }, "Nombre"), campo),
        h("div", { class: "modal-pie" },
          h("button", { class: "boton", value: "", formnovalidate: true }, "Cancelar"),
          h("button", { class: "boton boton--principal", value: "si" }, "Guardar"))));
    dialogo.addEventListener("close", () => { resolver(dialogo.returnValue === "si" ? campo.value.trim() : null); dialogo.remove(); });
    document.body.append(dialogo);
    dialogo.showModal();
  });
}

// Plan de ejecución (EXPLAIN): explica en palabras si usa índices o recorre tablas enteras
export function pintarPlan(r) {
  return h("div", { class: "tarjeta vidrio" },
    h("h2", {}, "Cómo la ejecuta la base de datos", h("small", {}, "EXPLAIN")),
    h("ul", { class: "recomendaciones" },
      h("li", { class: r.usa_indice ? "bien" : "aviso" }, h("span", { class: "icono" }, icono(r.usa_indice ? "ok" : "alerta")),
        h("span", {}, r.usa_indice ? "Usa índices para encontrar las filas: irá rápido aunque crezcan los datos." : "No usa ningún índice.")),
      r.recorre.length > 0 && h("li", { class: "aviso" }, h("span", { class: "icono" }, icono("alerta")),
        h("span", {}, `Recorre entera la tabla ${r.recorre.join(", ")}. Con pocos datos no importa; con miles de filas, un índice en la columna del filtro la haría más rápida.`))),
    h("details", { class: "sql-generado" }, h("summary", {}, "Consulta"), h("pre", { class: "codigo" }, r.sql)),
    h("div", { class: "tabla-contenedor" }, tablaResultado(r, { titulo: "Plan de ejecución" })));
}
