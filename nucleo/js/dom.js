// Utilidades de interfaz compartidas por todos los módulos.

// Crea un elemento. Los textos se insertan siempre como texto (nunca como HTML),
// así un nombre como "<script>" que venga de la base de datos no se ejecuta.
export function h(etiqueta, atributos = {}, ...hijos) {
  const elemento = document.createElement(etiqueta);

  for (const [clave, valor] of Object.entries(atributos ?? {})) {
    if (valor == null || valor === false) continue;
    if (clave.startsWith("on") && typeof valor === "function") {
      elemento.addEventListener(clave.slice(2), valor);
    } else if (clave === "class") {
      elemento.className = valor;
    } else if (clave === "style") {
      for (const [propiedad, v] of Object.entries(valor)) elemento.style.setProperty(propiedad, v);
    } else {
      elemento.setAttribute(clave, valor === true ? "" : valor);
    }
  }

  elemento.append(...hijos.flat(Infinity)
    .filter((hijo) => hijo != null && hijo !== false)
    .map((hijo) => (hijo instanceof Node ? hijo : document.createTextNode(String(hijo)))));

  return elemento;
}

// Iconos SVG (contenido fijo, no proviene de datos)
const ICONOS = {
  editar: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
  borrar: '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/>',
  descargar: '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
  recargar: '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
  luna: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
  sol: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
  factura: '<path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h4"/>',
  ver: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  tabla: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 4v16"/>',
  salir: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
  anterior: '<path d="m15 18-6-6 6-6"/>',
  siguiente: '<path d="m9 18 6-6-6-6"/>',
  volver: '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
  // Menú
  panel: '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
  clientes: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
  productos: '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>',
  pedidos: '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/>',
  facturas: '<path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h4"/>',
  usuarios: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
  registro: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  sistema: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
  // Estados y acciones
  ok: '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
  alerta: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
  fallo: '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
  candado: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  buscar: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  crear: '<path d="M12 5v14M5 12h14"/>',
  entrar: '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
  instalar: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/>',
  euro: '<path d="M18 7a6 6 0 1 0 0 10"/><path d="M4 10h9M4 14h9"/>',
  proveedores: '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.7a1 1 0 0 0-.2-.6l-3.5-4.3A1 1 0 0 0 17.5 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
  compras: '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 2h3l2.7 12.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>',
  rectificar: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
  llave: '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
  subir: '<path d="M12 21V9"/><path d="m7 14 5-5 5 5"/><path d="M5 3h14"/>',
  copia: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
  quitar: '<path d="M18 6 6 18M6 6l12 12"/>',
  brujula: '<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5-5 2 2-5z"/>',
};

export function icono(nombre) {
  if (!ICONOS[nombre]) nombre = "panel";
  const plantilla = document.createElement("template");
  plantilla.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONOS[nombre]}</svg>`;
  return plantilla.content.firstChild;
}

// ---------- Formatos ----------

const euros = new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR" });
const numeros = new Intl.NumberFormat("es-ES");
const fechas = new Intl.DateTimeFormat("es-ES", { day: "2-digit", month: "short", year: "numeric" });
const fechasHoras = new Intl.DateTimeFormat("es-ES", { dateStyle: "medium", timeStyle: "short" });
const relativo = new Intl.RelativeTimeFormat("es", { numeric: "auto" });

export const formato = {
  moneda: (v) => (v == null ? "—" : euros.format(v)),
  numero: (v) => (v == null ? "—" : numeros.format(v)),
  fecha: (v) => (v ? fechas.format(new Date(`${v}T00:00:00`)) : "—"),
  // SQLite guarda CURRENT_TIMESTAMP en UTC ("2026-10-05 10:33:13")
  instante: (v) => new Date(`${v.replace(" ", "T")}Z`),
  fechaHora: (v) => fechasHoras.format(formato.instante(v)),
  haceCuanto(v) {
    const segundos = (formato.instante(v) - Date.now()) / 1000;
    const pasos = [[60, "second"], [60, "minute"], [24, "hour"], [7, "day"], [4.35, "week"], [12, "month"], [Infinity, "year"]];
    let valor = segundos;
    for (const [tope, unidad] of pasos) {
      if (Math.abs(valor) < tope) return relativo.format(Math.round(valor), unidad);
      valor /= tope;
    }
  },
  capital: (texto) => (texto ? texto[0].toUpperCase() + texto.slice(1) : texto),
};

// Concordancia de género para los textos: «creado» / «creada», «Nuevo» / «Nueva»
export function genero(modulo, masculino, femenino) {
  return modulo.femenino ? femenino : masculino;
}

export function hoyIso() {
  return new Date().toLocaleDateString("sv-SE"); // aaaa-mm-dd en hora local
}

// ---------- Piezas de interfaz ----------

export function insignia(texto, color) {
  return h("span", { class: `insignia ${color ?? ""}` }, texto);
}

// Cabecera de página: antetítulo (sección), título, descripción y acciones.
export function cabeceraPagina({ antetitulo, titulo, descripcion, acciones = [] }) {
  return h("div", { class: "pagina-cabecera" },
    h("div", {},
      antetitulo && h("p", { class: "antetitulo" }, antetitulo),
      h("h1", {}, titulo),
      descripcion && h("p", { class: "descripcion" }, descripcion),
    ),
    h("div", { class: "pagina-acciones" }, acciones),
  );
}

export function estadoVacio(nombreIcono, titulo, texto, accion) {
  return h("div", { class: "vacio-estado" },
    h("span", { class: "vacio-icono", "aria-hidden": "true" }, icono(nombreIcono)),
    h("h3", {}, titulo),
    texto && h("p", {}, texto),
    accion,
  );
}

export function vistaError(error) {
  return h("section", { class: "vista" },
    h("div", { class: "tarjeta vidrio" },
      estadoVacio("fallo", "No se ha podido cargar esta página", error.message,
        h("a", { class: "boton", href: "#/sistema" }, "Revisar el sistema")),
    ),
  );
}

// Aviso flotante que desaparece solo.
export function aviso(mensaje, tipo = "ok") {
  const contenedor = document.getElementById("avisos");
  const elemento = h("div", { class: `aviso ${tipo}`, role: tipo === "error" ? "alert" : "status" },
    icono(tipo === "error" ? "alerta" : "ok"), mensaje);
  contenedor.append(elemento);
  setTimeout(() => {
    elemento.classList.add("saliendo");
    setTimeout(() => elemento.remove(), 300);
  }, tipo === "error" ? 5000 : 3000);
}

// Ventana de confirmación. Devuelve una promesa con true / false.
export function confirmar({ titulo, mensaje, boton = "Borrar", peligro = true }) {
  return new Promise((resolver) => {
    const dialogo = h("dialog", { class: "modal vidrio", "aria-labelledby": "confirmar-titulo" },
      h("h2", { id: "confirmar-titulo" }, titulo),
      h("p", {}, mensaje),
      h("form", { method: "dialog", class: "modal-pie" },
        h("button", { class: "boton", value: "no" }, "Cancelar"),
        h("button", { class: `boton ${peligro ? "boton--peligro" : "boton--principal"}`, value: "si" }, boton),
      ),
    );
    dialogo.addEventListener("close", () => {
      resolver(dialogo.returnValue === "si");
      dialogo.remove();
    });
    document.body.append(dialogo);
    dialogo.showModal();
  });
}

// Descarga un texto como archivo (exportar informes a Markdown).
export function descargar(nombre, contenido, tipo = "text/markdown") {
  const url = URL.createObjectURL(new Blob([contenido], { type: `${tipo};charset=utf-8` }));
  const enlace = h("a", { href: url, download: nombre });
  document.body.append(enlace);
  enlace.click();
  enlace.remove();
  URL.revokeObjectURL(url);
}

// Escapa el texto para meterlo en una celda de tabla Markdown.
export function celdaMd(valor) {
  return String(valor ?? "").replaceAll("|", "\\|").replaceAll("\n", " ");
}
