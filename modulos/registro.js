// Registro de operaciones (criterio g): cada alta, edición y borrado queda anotado
// automáticamente por la API y se puede exportar a Markdown para la documentación.

import { api } from "../nucleo/js/api.js";
import { h, icono, formato, cabeceraPagina, estadoVacio, descargar, celdaMd } from "../nucleo/js/dom.js";

const ACCIONES = {
  crear:    { marca: "crear",    texto: "Alta" },
  editar:   { marca: "editar",   texto: "Edición" },
  borrar:   { marca: "borrar",   texto: "Borrado" },
  facturar: { marca: "euro",     texto: "Facturación" },
  rectificar: { marca: "rectificar", texto: "Rectificación" },
  copia:    { marca: "copia",    texto: "Copia de seguridad" },
  restaurar: { marca: "subir",   texto: "Restauración" },
  entrar:   { marca: "entrar",   texto: "Inicio de sesión" },
  salir:    { marca: "salir",    texto: "Cierre de sesión" },
  instalar: { marca: "instalar", texto: "Instalación" },
};

export async function vista() {
  const filas = await api("registro");

  const exportar = h("button", { class: "boton", type: "button", disabled: !filas.length, onclick: () => exportarMarkdown(filas) },
    icono("descargar"), "Exportar informe");

  return h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: "Auditoría",
      titulo: "Registro de operaciones",
      descripcion: "Altas, ediciones, borrados, facturas y accesos, con el usuario que los realizó. Últimas 200 operaciones.",
      acciones: [exportar],
    }),
    h("div", { class: "tarjeta vidrio" },
      filas.length
        ? h("ol", { class: "linea-tiempo" }, filas.map((f) => {
            const accion = ACCIONES[f.accion] ?? { marca: "registro", texto: f.accion };
            return h("li", {},
              h("span", { class: `marca ${f.accion}`, "aria-hidden": "true" }, icono(accion.marca)),
              h("div", { class: "texto" },
                f.detalle,
                h("small", {}, `${accion.texto} · ${f.recurso} · ${f.usuario ?? "sistema"}`)),
              h("time", { datetime: formato.instante(f.fecha).toISOString(), title: formato.fechaHora(f.fecha) }, formato.haceCuanto(f.fecha)),
            );
          }))
        : estadoVacio("registro", "Sin operaciones todavía", "Cuando crees, edites o borres algo aparecerá aquí."),
    ),
  );
}

function exportarMarkdown(filas) {
  const lineas = [
    "# Registro de operaciones · Blush ERP",
    "",
    `Exportado el ${new Date().toLocaleString("es-ES")}.`,
    "",
    "| Nº | Fecha | Usuario | Acción | Módulo | Detalle |",
    "|---|---|---|---|---|---|",
    ...[...filas].reverse().map((f) =>
      `| ${f.id} | ${formato.fechaHora(f.fecha)} | ${celdaMd(f.usuario ?? "sistema")} | ${ACCIONES[f.accion]?.texto ?? f.accion} | ${f.recurso} | ${celdaMd(f.detalle)} |`),
    "",
  ];
  descargar(`registro-operaciones-${new Date().toLocaleDateString("sv-SE")}.md`, lineas.join("\n"));
}
