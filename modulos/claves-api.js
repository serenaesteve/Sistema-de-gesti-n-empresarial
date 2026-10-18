// Servicios de acceso (RA2): claves de API para que otros programas usen el ERP sin sesión.
// La clave se muestra una sola vez al crearla; después solo se ve su prefijo.

import { api, urlApi } from "../nucleo/js/api.js";
import { h, icono, formato, insignia, cabeceraPagina, estadoVacio, aviso, confirmar } from "../nucleo/js/dom.js";

export async function vista({ esquema, info }) {
  const claves = await api("claves");
  // Recursos que se pueden dar a otro programa: los de los módulos activos, menos usuarios
  const recursos = Object.entries(esquema).filter(([id]) => id !== "usuarios");
  const titulo = (id) => esquema[id]?.titulo ?? id;

  const nueva = h("button", { class: "boton boton--principal", type: "button", onclick: () => crear(recursos) }, icono("crear"), "Nueva clave");

  const tabla = claves.length
    ? h("div", { class: "tarjeta vidrio tabla-contenedor" },
        h("table", {},
          h("thead", {}, h("tr", {},
            ["Programa", "Clave", "Acceso a", "Permiso", "Estado", "Último uso", "Creada"].map((t) => h("th", {}, t)),
            h("th", { class: "acciones" }, h("span", { class: "oculto" }, "Acciones")))),
          h("tbody", {}, claves.map((c) => h("tr", {},
            h("td", { class: "destacado" }, c.nombre),
            h("td", { class: "id" }, `${c.prefijo}…`),
            h("td", {}, c.recursos.map(titulo).join(", ")),
            h("td", {}, c.escritura ? "Lectura y escritura" : "Solo lectura"),
            h("td", {}, insignia(c.activa ? "activa" : "revocada", c.activa ? "verde" : "gris")),
            h("td", {}, c.ultimo_uso ? new Date(c.ultimo_uso).toLocaleString("es-ES") : "—"),
            h("td", {}, formato.fechaHora(c.creado)),
            h("td", { class: "acciones" }, c.activa && h("button", {
              class: "boton boton--pequeno", type: "button", onclick: () => revocar(c),
            }, "Revocar")))))))
    : h("div", { class: "tarjeta vidrio" }, estadoVacio("llave", "Sin claves todavía", "Crea una clave para que otro programa (una tienda online, una hoja de cálculo…) pueda consultar o dar de alta datos.", nueva.cloneNode(true)));
  tabla.querySelector(".vacio-estado .boton")?.addEventListener("click", () => crear(recursos));

  const ejemplo = `curl -H "X-API-Key: blush_…" "${urlApi("clientes")}"`;
  return h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: "Administración",
      titulo: "Acceso API",
      descripcion: "Claves para que otros programas accedan a los datos sin iniciar sesión. Pueden leer y, si se permite, dar de alta y editar; nunca borrar ni administrar.",
      acciones: [nueva],
    }),
    tabla,
    h("div", { class: "tarjeta vidrio" },
      h("h2", {}, "Cómo se usa"),
      h("p", { class: "nota" }, "Cada petición lleva la clave en la cabecera X-API-Key. Ejemplo para listar clientes:"),
      h("pre", { class: "codigo" }, ejemplo),
      h("p", { class: "nota" }, "Las respuestas son JSON: {\"ok\": true, \"datos\": …}. Cada alta o edición queda en el Registro con el nombre del programa."),
      info.entorno_erp === "explotacion" && location.protocol !== "https:" &&
        h("p", { class: "error" }, "Atención: sin HTTPS la clave viaja sin cifrar."),
    ),
  );
}

function crear(recursos) {
  const errores = { nombre: h("span", { class: "error" }), recursos: h("span", { class: "error" }) };
  const nombre = h("input", { id: "clave-nombre", maxlength: "100", placeholder: "Tienda online, hoja de pedidos…" });
  const escritura = h("input", { type: "checkbox", id: "clave-escritura" });
  const casillas = recursos.map(([id, m]) => h("label", { class: "check" }, h("input", { type: "checkbox", value: id }), h("span", {}, m.titulo)));
  const guardar = h("button", { class: "boton boton--principal", type: "submit" }, "Crear clave");

  const formulario = h("form", { class: "formulario formulario--una", novalidate: true },
    h("div", { class: "campo" }, h("label", { for: "clave-nombre" }, "Programa que la usará"), nombre, errores.nombre),
    h("fieldset", { class: "lista-modulos" }, h("legend", {}, "Puede acceder a"), h("div", { class: "rejilla-casillas" }, casillas), errores.recursos),
    h("label", { class: "check" }, escritura, h("span", {}, h("strong", {}, "Permitir altas y ediciones"), " (sin marcar: solo lectura)")),
    h("div", { class: "modal-pie" },
      h("button", { class: "boton", type: "button", onclick: () => dialogo.close() }, "Cancelar"), guardar));

  const dialogo = h("dialog", { class: "modal", "aria-labelledby": "clave-titulo" }, h("h2", { id: "clave-titulo" }, "Nueva clave de API"), formulario);

  formulario.addEventListener("submit", async (evento) => {
    evento.preventDefault();
    Object.values(errores).forEach((e) => (e.textContent = ""));
    guardar.disabled = true;
    try {
      const r = await api("claves", { metodo: "POST", cuerpo: {
        nombre: nombre.value,
        recursos: casillas.map((c) => c.querySelector("input")).filter((c) => c.checked).map((c) => c.value),
        escritura: escritura.checked,
      } });
      mostrarClave(dialogo, r.clave);
    } catch (error) {
      const campos = Object.keys(error.errores ?? {});
      campos.forEach((c) => { if (errores[c]) errores[c].textContent = error.errores[c]; });
      if (!campos.length) aviso(error.message, "error");
      guardar.disabled = false;
    }
  });

  dialogo.addEventListener("close", () => { dialogo.remove(); if (dialogo.dataset.creada) location.reload(); });
  document.body.append(dialogo);
  dialogo.showModal();
}

// La clave completa solo se ve ahora: después solo queda su prefijo
function mostrarClave(dialogo, clave) {
  dialogo.dataset.creada = "1";
  const campo = h("input", { class: "clave-generada", value: clave, readonly: true, "aria-label": "Clave de API", onfocus: (e) => e.target.select() });
  dialogo.replaceChildren(
    h("h2", {}, "Clave creada"),
    h("p", {}, "Cópiala ahora y guárdala en el programa que la va a usar. Por seguridad, no se volverá a mostrar."),
    campo,
    h("div", { class: "modal-pie" },
      h("button", { class: "boton", type: "button", onclick: async () => {
        try { await navigator.clipboard.writeText(clave); aviso("Clave copiada"); } catch { campo.select(); }
      } }, "Copiar"),
      h("button", { class: "boton boton--principal", type: "button", onclick: () => dialogo.close() }, "Hecho")));
}

async function revocar(clave) {
  const ok = await confirmar({ titulo: `Revocar la clave de «${clave.nombre}»`, mensaje: "El programa que la usa dejará de tener acceso inmediatamente. No se puede deshacer.", boton: "Revocar" });
  if (!ok) return;
  try {
    await api("claves", { metodo: "DELETE", id: clave.id });
    aviso("Clave revocada");
    setTimeout(() => location.reload(), 600);
  } catch (error) {
    aviso(error.message, "error");
  }
}
