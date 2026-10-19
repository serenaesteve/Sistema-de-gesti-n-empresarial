// Importar y exportar (RA3, criterios b y d): cargar datos reales desde CSV o JSON y sacarlos del ERP.
//
// Importar en dos pasos: 1) se elige el archivo y se ve una vista previa con los errores de cada fila;
// 2) se importan solo las filas correctas, todas a la vez. Primero conviene descargar la plantilla.
// Pedidos y compras: una fila por línea, agrupadas por «Referencia». Cada importación se puede deshacer.

import { api, urlApi } from "../nucleo/js/api.js";
import { h, icono, formato, insignia, cabeceraPagina, estadoVacio, aviso, confirmar } from "../nucleo/js/dom.js";

const estado = { destino: null };

export async function vista() {
  const datos = await api("intercambio");
  const importables = datos.importables;
  if (!importables.some((r) => r.id === estado.destino)) estado.destino = importables[0]?.id ?? null;
  const recargar = async () => document.querySelector(".vista")?.replaceWith(await vista());

  const previa = h("div", {});
  const archivo = h("input", { type: "file", id: "archivo-importar", accept: ".csv,.json,.txt,text/csv,application/json", class: "oculto" });
  let leido = null;   // {contenido, nombre, formato}

  archivo.addEventListener("change", async () => {
    const elegido = archivo.files[0];
    archivo.value = "";
    if (!elegido) return;
    if (elegido.size > 5 * 1024 * 1024) return aviso("El archivo pesa más de 5 MB", "error");
    leido = { contenido: await elegido.text(), nombre: elegido.name, formato: /\.json$/i.test(elegido.name) ? "json" : "csv" };
    analizar();
  });

  async function analizar() {
    previa.setAttribute("aria-busy", "true");
    try {
      const r = await api("intercambio", { metodo: "POST", parametros: { accion: "analizar", destino: estado.destino }, cuerpo: leido });
      previa.replaceChildren(pintarPrevia(r));
    } catch (error) {
      previa.replaceChildren(h("div", { class: "tarjeta vidrio" }, estadoVacio("alerta", error.message, Object.values(error.errores ?? {})[0])));
    } finally {
      previa.removeAttribute("aria-busy");
    }
  }

  function pintarPrevia(r) {
    // Qué hacer con los repetidos: no importarlos, actualizar los que ya existen o importarlos igualmente
    const modo = h("select", { id: "modo-importar", "aria-label": "Repetidos" },
      Object.entries(r.modos).map(([v, t]) => h("option", { value: v, selected: v === (r.existentes ? "actualizar" : "omitir") }, t)));
    const textoBoton = r.lineas
      ? `Importar ${formato.numero(r.registros)} ${r.registros === 1 ? "registro" : "registros"} (${formato.numero(r.validas)} líneas)`
      : `Importar ${formato.numero(r.validas)} ${r.validas === 1 ? "fila" : "filas"}`;
    const importar = h("button", { class: "boton boton--principal", type: "button", disabled: !r.validas, onclick: async () => {
      importar.disabled = true;
      try {
        const res = await api("intercambio", { metodo: "POST", parametros: { accion: "importar", destino: estado.destino }, cuerpo: { ...leido, modo: modo.value } });
        const partes = [`${res.importadas} importados`, res.actualizadas && `${res.actualizadas} actualizados`, res.omitidas && `${res.omitidas} omitidos`].filter(Boolean);
        aviso(partes.join(" · "));
        leido = null;
        previa.replaceChildren(h("div", { class: "tarjeta vidrio" }, estadoVacio("ok", partes.join(" · "),
          `${res.lineas ? `${res.lineas} líneas. Son historial: el stock no cambia. ` : ""}La importación queda en el Registro y se puede deshacer desde el historial.`,
          h("div", { class: "pagina-acciones" },
            h("a", { class: "boton boton--principal", href: `#/${estado.destino}` }, "Ver los datos"),
            h("button", { class: "boton", type: "button", onclick: recargar }, "Ver el historial")))));
      } catch (error) {
        aviso(error.message, "error");
        importar.disabled = false;
      }
    } }, icono("subir"), textoBoton);

    const muestra = r.filas.slice(0, 200);
    return h("div", { class: "tarjeta vidrio" },
      h("h2", {}, `Vista previa · ${leido.nombre}`, h("small", {}, `${formato.numero(r.filas.length)} filas`)),
      h("div", { class: "resumen" },
        insignia(`${r.validas} ${r.validas === 1 ? "correcta" : "correctas"}`, "verde"),
        insignia(`${r.con_errores} con errores`, r.con_errores ? "rojo" : "gris"),
        !r.lineas && insignia(`${r.duplicadas} ${r.duplicadas === 1 ? "posible repetida" : "posibles repetidas"}`, r.duplicadas ? "ambar" : "gris"),
        r.lineas && insignia(`${r.registros} ${r.registros === 1 ? "registro" : "registros"}`, "azul")),
      r.lineas && h("p", { class: "nota" }, "Las filas con la misma referencia forman un solo registro; las siguientes pueden dejar vacíos el cliente, la fecha y el estado. Son historial: no cambian el stock."),
      r.ignoradas.length > 0 && h("p", { class: "nota" }, `Columnas que no se usan (no coinciden con ningún campo): ${r.ignoradas.join(", ")}.`),
      h("div", { class: "tabla-contenedor" }, h("table", { class: "tabla-previa" },
        h("thead", {}, h("tr", {}, h("th", {}, "Fila"), r.columnas.map((c) => h("th", {}, c.etiqueta)), h("th", {}, "Estado"))),
        h("tbody", {}, muestra.map((f) => {
          const errores = f.errores ?? {};
          const conError = Object.keys(errores).length > 0;
          return h("tr", { class: conError ? "fila-error" : f.avisos.length ? "fila-aviso" : "" },
            h("td", { class: "id" }, f.fila),
            r.columnas.map((c) => h("td", { class: errores[c.clave] ? "celda-error" : "", title: errores[c.clave] ?? null }, f.datos[c.clave] || "—")),
            h("td", {}, conError
              ? h("span", { class: "error-texto" }, Object.entries(errores).map(([k, m]) => `${r.columnas.find((c) => c.clave === k)?.etiqueta ?? k}: ${m}`).join(" · "))
              : f.avisos.length ? h("span", { class: "aviso-texto" }, formato.capital(f.avisos.join(" · "))) : insignia("correcta", "verde")));
        })))),
      r.filas.length > muestra.length && h("p", { class: "nota" }, `Se muestran las 200 primeras filas de ${formato.numero(r.filas.length)}.`),
      h("div", { class: "acciones-formulario" },
        !r.lineas && r.duplicadas > 0 && h("label", { class: "campo-en-linea" }, "Repetidos:", modo),
        h("button", { class: "boton", type: "button", onclick: () => { leido = null; previa.replaceChildren(); } }, "Cancelar"),
        importar));
  }

  const destino = importables.length && h("select", { id: "destino", onchange: (e) => { estado.destino = e.target.value; pintarCampos(); if (leido) analizar(); } },
    importables.map((r) => h("option", { value: r.id, selected: r.id === estado.destino }, r.titulo)));
  const plantilla = h("a", { class: "boton", download: "" }, icono("descargar"), "Descargar plantilla");
  const camposDestino = h("p", { class: "nota" });
  function pintarCampos() {
    const r = importables.find((x) => x.id === estado.destino);
    plantilla.href = urlApi("intercambio", { plantilla: estado.destino });
    camposDestino.replaceChildren(h("span", {}, "Columnas: ", r.campos.map((c, i) => [i ? ", " : "", h(c.obligatorio ? "strong" : "span", {}, c.etiqueta + (c.obligatorio ? " *" : "")),
      c.opciones ? ` (${c.opciones.join(" / ")})` : ""]), ". Los campos con * son obligatorios.",
      r.lineas ? " Una fila por línea: las filas con la misma referencia forman un solo registro." : ""));
  }
  if (importables.length) pintarCampos();

  const historial = h("div", { class: "tarjeta vidrio" },
    h("h2", {}, "Historial de importaciones", h("small", {}, "Se pueden deshacer")),
    datos.historial.length
      ? h("ol", { class: "linea-tiempo" }, datos.historial.map((i) => h("li", {},
          h("span", { class: `marca ${i.deshecha ? "borrar" : "importar"}`, "aria-hidden": "true" }, icono(i.deshecha ? "rectificar" : "subir")),
          h("div", { class: "texto" },
            h("strong", {}, `${i.titulo} · ${i.archivo ?? "archivo"}`),
            h("small", {}, `${i.creados} creados${i.actualizados ? ` · ${i.actualizados} actualizados` : ""} · ${i.usuario ?? "—"} · ${formato.fechaHora(i.creado)}${i.deshecha ? " · deshecha" : ""}`)),
          i.deshecha ? insignia("deshecha", "gris") : h("button", { class: "boton boton--pequeno", type: "button", onclick: async () => {
            const ok = await confirmar({ titulo: "Deshacer la importación", boton: "Deshacer",
              mensaje: `Se borrarán los ${i.creados} registros creados${i.actualizados ? ` y los ${i.actualizados} actualizados volverán a como estaban` : ""}. Si alguno ya se usa (por ejemplo, tiene pedidos), no se deshará nada.` });
            if (!ok) return;
            try {
              const r = await api("intercambio", { metodo: "POST", id: i.id, parametros: { accion: "deshacer" } });
              aviso(`Importación deshecha: ${r.borrados} borrados${r.recuperados ? `, ${r.recuperados} recuperados` : ""}`);
              recargar();
            } catch (error) { aviso(error.message, "error"); }
          } }, icono("rectificar"), "Deshacer"))))
      : h("p", { class: "nota" }, "Aún no se ha importado nada."));

  return h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: "Datos",
      titulo: "Importar y exportar",
      descripcion: "Carga los datos reales de la empresa desde una hoja de cálculo y saca cualquier listado en Excel, CSV o JSON.",
    }),
    h("div", { class: "rejilla rejilla--consultas" },
      h("div", { class: "tarjeta vidrio" },
        h("h2", {}, "Importar"),
        importables.length
          ? [
              h("ol", { class: "lista-pasos" },
                h("li", {}, "Elige qué vas a importar y descarga la plantilla (o usa tu propia hoja: las columnas se reconocen por su nombre)."),
                h("li", {}, "Guarda la hoja como CSV (separado por «;» o «,») o JSON."),
                h("li", {}, "Súbela: verás qué filas están bien y cuáles tienen errores antes de importar nada.")),
              h("div", { class: "fila-importar" },
                h("div", { class: "campo" }, h("label", { for: "destino" }, "Datos"), destino),
                h("div", { class: "acciones-importar" }, plantilla,
                  h("label", { class: "boton boton--principal", for: "archivo-importar", tabindex: "0",
                    onkeydown: (e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); archivo.click(); } } }, icono("subir"), "Elegir archivo…"),
                  archivo)),
              camposDestino,
              h("p", { class: "nota" }, `Hasta ${formato.numero(datos.maximo)} filas por archivo. Cada dato se comprueba igual que en el alta a mano (NIF, IBAN, código postal, teléfono, importes…). Los repetidos se pueden omitir, actualizar o importar igualmente.`),
            ]
          : h("p", { class: "nota" }, "Tu rol no puede dar de alta datos.")),
      h("div", { class: "tarjeta vidrio" },
        h("h2", {}, "Exportar"),
        h("p", { class: "nota" }, "Todos los registros en Excel, en CSV (con «;» y decimales con coma) o en JSON (para otros programas o para volver a importarlos)."),
        h("ul", { class: "lista" }, datos.exportables.map((r) => h("li", {},
          h("div", { class: "principal" }, h("strong", {}, r.titulo)),
          h("a", { class: "boton boton--pequeno", download: "", href: urlApi(r.id, { formato: "xlsx" }) }, "Excel"),
          h("a", { class: "boton boton--pequeno", download: "", href: urlApi(r.id, { formato: "csv" }) }, "CSV"),
          h("a", { class: "boton boton--pequeno", download: "", href: urlApi(r.id, { formato: "json" }) }, "JSON")))),
        h("p", { class: "nota" }, "Los informes y las consultas también se exportan desde su propia pantalla."))),
    previa,
    historial,
  );
}
