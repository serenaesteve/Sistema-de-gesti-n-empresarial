// Diagnóstico del sistema (criterio f): verifica en directo el sistema operativo,
// el servidor web, PHP y la base de datos que necesita el ERP-CRM.

import { api, urlApi } from "../nucleo/js/api.js";
import { h, icono, insignia, cabeceraPagina, descargar, celdaMd, confirmar, aviso } from "../nucleo/js/dom.js";

const ESTADOS = {
  ok:    { icono: "ok",     texto: "Correcto" },
  aviso: { icono: "alerta", texto: "Aviso",    md: "⚠️" },
  error: { icono: "fallo",  texto: "Error",    md: "❌" },
};

export async function vista() {
  const [datos, seguridad] = await Promise.all([api("sistema"), comprobarDescarga()]);
  const comprobaciones = [...datos.comprobaciones, seguridad];
  const resumen = { ok: 0, aviso: 0, error: 0 };
  comprobaciones.forEach((c) => resumen[c.estado]++);

  const grupos = new Map();
  comprobaciones.forEach((c) => grupos.set(c.grupo, [...(grupos.get(c.grupo) ?? []), c]));

  const raiz = h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: "Administración",
      titulo: "Estado del sistema",
      descripcion: "Comprobación en directo del sistema operativo, el servidor web, PHP y la base de datos que necesita el ERP-CRM.",
      acciones: [
        h("button", { class: "boton", type: "button", onclick: async () => raiz.replaceWith(await vista()) }, icono("recargar"), "Volver a comprobar"),
        h("button", { class: "boton boton--principal", type: "button", onclick: () => exportarMarkdown(comprobaciones, resumen) }, icono("descargar"), "Exportar informe"),
      ],
    }),

    h("div", { class: "resumen", role: "status" },
      insignia(`${resumen.ok} ${resumen.ok === 1 ? "correcta" : "correctas"}`, "verde"),
      insignia(`${resumen.aviso} ${resumen.aviso === 1 ? "aviso" : "avisos"}`, resumen.aviso ? "ambar" : "gris"),
      insignia(`${resumen.error} ${resumen.error === 1 ? "error" : "errores"}`, resumen.error ? "rojo" : "gris"),
    ),

    tarjetaCopias(),

    [...grupos].map(([grupo, lista]) =>
      h("div", { class: "tarjeta vidrio" },
        h("h2", {}, grupo, h("small", {}, `${lista.filter((c) => c.estado === "ok").length}/${lista.length} correctas`)),
        h("ul", { class: "comprobaciones" }, lista.map((c) =>
          h("li", { class: c.estado },
            h("span", { class: "icono", role: "img", "aria-label": ESTADOS[c.estado].texto }, icono(ESTADOS[c.estado].icono)),
            h("span", { class: "nombre" }, c.nombre),
            h("span", { class: "valor" }, c.valor),
            c.nota && h("span", { class: "nota" }, c.nota),
          ))),
      )),
  );
  return raiz;
}

// Copias de seguridad: descarga en JSON y restauración (sirve para SQLite y MySQL)
function tarjetaCopias() {
  const archivo = h("input", { type: "file", accept: "application/json,.json", class: "oculto", id: "archivo-copia" });
  archivo.addEventListener("change", async () => {
    const elegido = archivo.files[0];
    archivo.value = "";
    if (!elegido) return;
    let copia;
    try {
      copia = JSON.parse(await elegido.text());
    } catch {
      return aviso("El archivo no es un JSON válido", "error");
    }
    const ok = await confirmar({
      titulo: "Restaurar copia de seguridad",
      mensaje: `Se sustituirán TODOS los datos actuales por los de la copia del ${new Date(copia.fecha).toLocaleString("es-ES")}. Descarga antes una copia de los datos actuales si los necesitas.`,
      boton: "Restaurar",
    });
    if (!ok) return;
    try {
      const resultado = await api("restaurar", { metodo: "POST", cuerpo: copia });
      aviso(`Copia restaurada (${resultado.filas} filas)`);
      setTimeout(() => location.reload(), 1200);
    } catch (error) {
      aviso(error.message, "error");
    }
  });

  return h("div", { class: "tarjeta vidrio copias" },
    h("div", {},
      h("h2", {}, "Copias de seguridad"),
      h("p", { class: "nota" }, "Exporta todos los datos a un archivo JSON. La misma copia se puede restaurar en SQLite o en MySQL."),
    ),
    h("div", { class: "pagina-acciones" },
      h("a", { class: "boton", href: urlApi("copia"), download: "" }, icono("copia"), "Descargar copia"),
      h("label", { class: "boton", for: "archivo-copia", tabindex: "0", onkeydown: (e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); archivo.click(); } } },
        icono("subir"), "Restaurar…"),
      archivo,
    ),
  );
}

// Esta prueba solo se puede hacer desde fuera: pedimos el archivo de la base de datos
// como lo haría cualquier visitante. Si responde 200, cualquiera puede descargarla.
async function comprobarDescarga() {
  const base = { grupo: "Seguridad", nombre: "Descarga directa de datos/erp.sqlite" };
  try {
    const respuesta = await fetch("datos/erp.sqlite", { method: "HEAD", cache: "no-store" });
    if (respuesta.ok) {
      return {
        ...base, valor: `HTTP ${respuesta.status} · se puede descargar`, estado: "error",
        nota: "Apache ignora datos/.htaccess (AllowOverride None). Solución: añadir <Directory …/datos> Require all denied </Directory> al sitio de Apache, o mover la base de datos fuera de /var/www/html.",
      };
    }
    return { ...base, valor: `HTTP ${respuesta.status} · bloqueada`, estado: "ok", nota: "La base de datos no es accesible desde el navegador." };
  } catch {
    return { ...base, valor: "no comprobado", estado: "aviso", nota: "No se pudo realizar la petición de prueba." };
  }
}

function exportarMarkdown(comprobaciones, resumen) {
  const lineas = [
    "# Informe de verificación del sistema · Blush ERP",
    "",
    `Generado el ${new Date().toLocaleString("es-ES")} desde ${location.host}.`,
    "",
    `**Resumen:** ${resumen.ok} correctas · ${resumen.aviso} avisos · ${resumen.error} errores`,
    "",
    "| Estado | Grupo | Comprobación | Valor | Nota |",
    "|---|---|---|---|---|",
    ...comprobaciones.map((c) =>
      `| ${ESTADOS[c.estado].texto} | ${c.grupo} | ${celdaMd(c.nombre)} | ${celdaMd(c.valor)} | ${celdaMd(c.nota)} |`),
    "",
  ];
  descargar(`verificacion-sistema-${new Date().toLocaleDateString("sv-SE")}.md`, lineas.join("\n"));
}
