// Tareas programadas (RA3, criterio e): extracciones automáticas de informes a CSV o JSON.
// Las lanza cron con scripts/tareas.php; también se pueden ejecutar a mano. Cada ejecución,
// correcta o con error, queda anotada (criterio g: documentar operaciones e incidencias).

import { api, urlApi } from "../nucleo/js/api.js";
import { h, icono, formato, insignia, cabeceraPagina, estadoVacio, aviso, confirmar } from "../nucleo/js/dom.js";

export async function vista() {
  const d = await api("tareas");
  const recargar = async () => document.querySelector(".vista")?.replaceWith(await vista());
  const nueva = h("button", { class: "boton boton--principal", type: "button", disabled: !d.informes.length && !d.consultas.length, onclick: () => editar(d, null, recargar) }, icono("crear"), "Nueva tarea");

  const tablaTareas = d.tareas.length
    ? h("div", { class: "tarjeta vidrio tabla-contenedor" }, h("table", {},
        h("thead", {}, h("tr", {}, ["Tarea", "Informe", "Datos de", "Cuándo", "Próxima", "Estado"].map((t) => h("th", {}, t)), h("th", { class: "acciones" }, h("span", { class: "oculto" }, "Acciones")))),
        h("tbody", {}, d.tareas.map((t) => h("tr", {},
          h("td", { class: "destacado" }, t.nombre),
          h("td", {}, t.informe_titulo, h("small", { class: "nota" }, ` · ${t.formato.toUpperCase()}`)),
          h("td", {}, t.informe.startsWith("consulta:") ? "Todo (consulta)" : h("span", { title: `Si se ejecutara hoy: del ${formato.fecha(t.fechas[0])} al ${formato.fecha(t.fechas[1])}` }, d.periodos[t.periodo])),
          h("td", {}, { diaria: "Cada día", semanal: "Los lunes", mensual: "Día 1 de cada mes" }[t.frecuencia]),
          h("td", {}, t.activa && t.proxima ? new Date(t.proxima.replace(" ", "T")).toLocaleString("es-ES", { dateStyle: "medium", timeStyle: "short" }) : "—"),
          h("td", {}, insignia(t.activa ? "activa" : "pausada", t.activa ? "verde" : "gris")),
          h("td", { class: "acciones" }, h("div", { class: "acciones-fila" },
            h("button", { class: "boton boton--pequeno", type: "button", onclick: async (e) => {
              e.currentTarget.disabled = true;
              try {
                const r = await api("tareas", { metodo: "POST", id: t.id, parametros: { accion: "ejecutar" } });
                aviso(r.estado === "ok" ? `${r.filas} filas → ${r.archivo}` : r.mensaje, r.estado === "ok" ? "ok" : "error");
              } catch (error) { aviso(error.message, "error"); }
              recargar();
            } }, icono("ejecutar"), "Ejecutar"),
            h("button", { class: "boton-icono", type: "button", "aria-label": `Editar «${t.nombre}»`, onclick: () => editar(d, t, recargar) }, icono("editar")),
            h("button", { class: "boton-icono", type: "button", "aria-label": `Borrar «${t.nombre}»`, onclick: async () => {
              if (!(await confirmar({ titulo: `Borrar «${t.nombre}»`, mensaje: "La tarea dejará de ejecutarse. Los archivos ya generados se conservan." }))) return;
              await api("tareas", { metodo: "DELETE", id: t.id });
              aviso("Tarea borrada");
              recargar();
            } }, icono("borrar")))))))))
    : h("div", { class: "tarjeta vidrio" }, estadoVacio("tareas", "Sin tareas programadas",
        "Por ejemplo: el libro de facturas del mes anterior, cada día 1, en CSV para la gestoría.", d.informes.length ? nueva.cloneNode(true) : null));
  tablaTareas.querySelector(".vacio-estado .boton")?.addEventListener("click", () => editar(d, null, recargar));

  const historial = h("div", { class: "tarjeta vidrio" },
    h("h2", {}, "Últimas ejecuciones", h("small", {}, d.ultima_cron ? `cron: ${formato.haceCuanto(d.ultima_cron)}` : "cron todavía no ha ejecutado ninguna")),
    d.ejecuciones.length
      ? h("ol", { class: "linea-tiempo" }, d.ejecuciones.map((e) => h("li", {},
          h("span", { class: `marca ${e.estado === "ok" ? "crear" : "borrar"}`, "aria-hidden": "true" }, icono(e.estado === "ok" ? "ok" : "alerta")),
          h("div", { class: "texto" },
            h("strong", {}, e.tarea ?? `Tarea #${e.tarea_id}`), " · ", e.estado === "ok" ? `${e.filas} filas en ${formatoMs(e.ms)}` : h("span", { class: "error-texto" }, "Error"),
            h("small", {}, e.mensaje, " · ", e.origen === "cron" ? "automática" : "manual",
              e.disponible && [" · ", h("a", { href: urlApi("tareas", { descarga: e.id }), download: "" }, e.archivo)])),
          h("time", { datetime: e.creado }, formato.fechaHora(e.creado)))))
      : h("p", { class: "nota" }, "Aún no se ha ejecutado ninguna tarea."));

  return h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: "Datos",
      titulo: "Tareas programadas",
      descripcion: "Extracciones automáticas: el ERP genera un informe solo cada día, semana o mes y lo guarda como archivo, listo para enviar.",
      acciones: [nueva],
    }),
    tablaTareas,
    h("div", { class: "rejilla rejilla--consultas" },
      historial,
      h("div", { class: "tarjeta vidrio" },
        h("h2", {}, "Activar la automatización"),
        h("p", { class: "nota" }, "El programador de tareas del servidor (cron) revisa cada 15 minutos si a alguna tarea le toca. Se activa una sola vez con «crontab -e» y esta línea:"),
        h("pre", { class: "codigo" }, d.cron),
        h("p", { class: "nota" }, `Los archivos se guardan en datos/extracciones/ (se conservan los 30 últimos de cada tarea). Para probar sin esperar: php scripts/tareas.php --todas`))),
  );
}

function formatoMs(ms) {
  return ms == null ? "—" : ms < 1 ? "menos de 1 ms" : ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1).replace(".", ",")} s`;
}

function editar(d, actual, recargar) {
  const datos = actual ? { ...actual } : { nombre: "", informe: (d.informes[0] ?? d.consultas[0]).id, periodo: "mes_anterior", frecuencia: "mensual", formato: "csv" };
  const errores = Object.fromEntries(["nombre", "informe", "periodo", "frecuencia", "formato"].map((k) => [k, h("span", { class: "error" })]));
  const select = (clave, opciones) => h("select", { id: `t-${clave}`, onchange: (e) => { datos[clave] = e.target.value; } },
    Object.entries(opciones).map(([v, t]) => h("option", { value: v, selected: v === datos[clave] }, t)));
  // Informes y consultas guardadas en el mismo desplegable; las consultas no tienen periodo
  const campoPeriodo = h("div", {});
  const pintarPeriodo = () => campoPeriodo.replaceChildren(datos.informe.startsWith("consulta:")
    ? h("p", { class: "nota" }, "Las consultas guardadas se extraen enteras, sin periodo.")
    : campo("periodo", "Datos de", select("periodo", d.periodos)));
  const origen = h("select", { id: "t-informe", onchange: (e) => { datos.informe = e.target.value; pintarPeriodo(); } },
    d.informes.length > 0 && h("optgroup", { label: "Informes" }, d.informes.map((i) => h("option", { value: i.id, selected: i.id === datos.informe }, i.titulo))),
    d.consultas.length > 0 && h("optgroup", { label: "Consultas guardadas" }, d.consultas.map((c) => h("option", { value: c.id, selected: c.id === datos.informe }, c.titulo))));
  const campo = (clave, etiqueta, control) => h("div", { class: "campo" }, h("label", { for: `t-${clave}` }, etiqueta), control, errores[clave]);
  const guardar = h("button", { class: "boton boton--principal", type: "submit" }, actual ? "Guardar" : "Programar");

  const formulario = h("form", { class: "formulario formulario--una", novalidate: true },
    campo("nombre", "Nombre", h("input", { id: "t-nombre", maxlength: "100", value: datos.nombre, placeholder: "Libro de facturas para la gestoría", oninput: (e) => { datos.nombre = e.target.value; } })),
    campo("informe", "Qué extraer", origen),
    campoPeriodo,
    campo("frecuencia", "Cuándo", select("frecuencia", Object.fromEntries(Object.entries(d.frecuencias).map(([k, v]) => [k, `${v} a las ${d.hora}`])))),
    campo("formato", "Formato", select("formato", d.formatos)),
    actual && h("label", { class: "check" }, h("input", { type: "checkbox", checked: datos.activa, onchange: (e) => { datos.activa = e.target.checked; } }), h("span", {}, "Activa")),
    h("div", { class: "modal-pie" }, h("button", { class: "boton", type: "button", onclick: () => dialogo.close() }, "Cancelar"), guardar));
  pintarPeriodo();
  const dialogo = h("dialog", { class: "modal", "aria-labelledby": "t-titulo" }, h("h2", { id: "t-titulo" }, actual ? "Editar tarea" : "Nueva tarea programada"), formulario);

  formulario.addEventListener("submit", async (e) => {
    e.preventDefault();
    Object.values(errores).forEach((x) => (x.textContent = ""));
    guardar.disabled = true;
    try {
      const cuerpo = { nombre: datos.nombre, informe: datos.informe, periodo: datos.periodo, frecuencia: datos.frecuencia, formato: datos.formato, ...(actual ? { activa: datos.activa } : {}) };
      await api("tareas", actual ? { metodo: "PUT", id: actual.id, cuerpo } : { metodo: "POST", cuerpo });
      aviso(actual ? "Tarea guardada" : "Tarea programada");
      dialogo.close();
      recargar();
    } catch (error) {
      const claves = Object.keys(error.errores ?? {});
      claves.forEach((k) => { if (errores[k]) errores[k].textContent = error.errores[k]; });
      if (!claves.length) aviso(error.message, "error");
      guardar.disabled = false;
    }
  });
  dialogo.addEventListener("close", () => dialogo.remove());
  document.body.append(dialogo);
  dialogo.showModal();
}
