// Formularios web (RA3, criterio b): formularios públicos que crean clientes, proveedores o productos.
// Se publican en formulario.html?f=<dirección> y se pueden incrustar en la web de la empresa.

import { api } from "../nucleo/js/api.js";
import { h, icono, formato, insignia, cabeceraPagina, estadoVacio, aviso, confirmar } from "../nucleo/js/dom.js";

export async function vista() {
  const { formularios, recursos } = await api("formularios");
  const titulo = (id) => recursos.find((r) => r.id === id)?.titulo ?? id;
  const enlace = (f) => new URL(`formulario.html?f=${encodeURIComponent(f.slug)}`, location.href).href;
  const nuevo = h("button", { class: "boton boton--principal", type: "button", disabled: !recursos.length, onclick: () => editar(recursos) }, icono("crear"), "Nuevo formulario");

  const recargar = async () => raiz.replaceWith(await vista());

  const lista = formularios.length
    ? h("div", { class: "rejilla rejilla--modulos" }, formularios.map((f) => h("article", { class: `tarjeta vidrio modulo ${f.activo ? "" : "modulo--inactivo"}` },
        h("header", { class: "modulo-cabecera" },
          h("span", { class: "modulo-icono" }, icono("formularios")),
          h("div", {}, h("h2", {}, f.titulo), h("small", {}, `Crea ${titulo(f.recurso).toLowerCase()} · ${f.campos.length} campos`)),
          insignia(f.activo ? "publicado" : "pausado", f.activo ? "verde" : "gris")),
        h("dl", { class: "modulo-datos" },
          h("dt", {}, "Envíos"), h("dd", {}, formato.numero(f.envios)),
          h("dt", {}, "Creado"), h("dd", {}, formato.fechaHora(f.creado))),
        h("p", { class: "nota" }, h("a", { href: enlace(f), target: "_blank", rel: "noopener" }, `formulario.html?f=${f.slug}`)),
        h("footer", { class: "modulo-pie" },
          h("button", { class: "boton boton--pequeno", type: "button", onclick: () => copiar(f, enlace(f)) }, icono("enlace"), "Copiar código"),
          h("button", { class: "boton boton--pequeno", type: "button", onclick: () => editar(recursos, f) }, icono("editar"), "Editar"),
          h("button", { class: "boton boton--pequeno", type: "button", onclick: async () => {
            await api("formularios", { metodo: "PUT", id: f.id, cuerpo: { activo: !f.activo } });
            aviso(f.activo ? "Formulario pausado" : "Formulario publicado");
            recargar();
          } }, f.activo ? "Pausar" : "Publicar"),
          h("button", { class: "boton-icono", type: "button", "aria-label": `Borrar «${f.titulo}»`, onclick: async () => {
            if (!(await confirmar({ titulo: `Borrar «${f.titulo}»`, mensaje: "La dirección dejará de funcionar. Los registros que ya llegaron por el formulario se conservan." }))) return;
            await api("formularios", { metodo: "DELETE", id: f.id });
            aviso("Formulario borrado");
            recargar();
          } }, icono("borrar"))))))
    : h("div", { class: "tarjeta vidrio" }, estadoVacio("formularios", "Sin formularios todavía",
        "Crea un formulario «Pide presupuesto» para la web: cada envío entra como cliente potencial y aparece en el Registro."));

  const raiz = h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: "Datos",
      titulo: "Formularios web",
      descripcion: "Formularios públicos para que los datos reales entren solos en el ERP, con la misma validación que un alta a mano.",
      acciones: [nuevo],
    }),
    lista,
    h("div", { class: "tarjeta vidrio" },
      h("h2", {}, "Protección"),
      h("ul", { class: "lista-pasos" },
        h("li", {}, "Solo se guardan los campos elegidos; el resto toma su valor por defecto (un cliente entra como «potencial»)."),
        h("li", {}, "Cada dato se valida igual que en el ERP: email, teléfono, NIF/CIF con su letra de control…"),
        h("li", {}, "Un campo invisible detecta a los robots, y desde un mismo sitio no se puede enviar el mismo formulario más de 5 veces por hora."),
        h("li", {}, "No se guarda la dirección IP de quien envía, solo una huella para el límite."))),
  );
  return raiz;
}

function copiar(f, url) {
  const codigo = `<iframe src="${url}&incrustado" title="${f.titulo.replaceAll('"', "&quot;")}" style="width:100%;min-height:640px;border:0"></iframe>`;
  const campo = h("textarea", { class: "codigo", rows: "4", readonly: true, onfocus: (e) => e.target.select() }, codigo);
  const dialogo = h("dialog", { class: "modal modal--ancha", "aria-labelledby": "codigo-titulo" },
    h("h2", { id: "codigo-titulo" }, "Insertar en tu web"),
    h("p", {}, "Pega este código en la página de tu web donde quieras el formulario, o comparte directamente el enlace:"),
    h("p", {}, h("a", { href: url, target: "_blank", rel: "noopener" }, url)),
    campo,
    h("div", { class: "modal-pie" },
      h("button", { class: "boton", type: "button", onclick: async () => { try { await navigator.clipboard.writeText(codigo); aviso("Código copiado"); } catch { campo.select(); } } }, "Copiar código"),
      h("button", { class: "boton boton--principal", type: "button", onclick: () => dialogo.close() }, "Hecho")));
  dialogo.addEventListener("close", () => dialogo.remove());
  document.body.append(dialogo);
  dialogo.showModal();
}

function editar(recursos, actual = null) {
  const datos = actual ? structuredClone(actual) : { titulo: "", slug: "", recurso: recursos[0].id, campos: [], mensaje: "" };
  const errores = Object.fromEntries(["titulo", "slug", "recurso", "campos", "mensaje"].map((k) => [k, h("span", { class: "error" })]));
  const casillas = h("div", { class: "rejilla-casillas" });
  const pintarCasillas = () => {
    const r = recursos.find((x) => x.id === datos.recurso);
    if (!actual && !datos.campos.length) datos.campos = r.campos.filter((c) => c.obligatorio || ["email", "telefono", "empresa"].includes(c.clave)).map((c) => c.clave);
    casillas.replaceChildren(...r.campos.map((c) => h("label", { class: "check" },
      h("input", { type: "checkbox", value: c.clave, checked: datos.campos.includes(c.clave), disabled: c.obligatorio, onchange: (e) => {
        datos.campos = e.target.checked ? [...datos.campos, c.clave] : datos.campos.filter((x) => x !== c.clave);
      } }),
      h("span", {}, c.etiqueta, c.obligatorio && h("small", {}, " (obligatorio)")))));
  };
  pintarCasillas();

  const campo = (clave, etiqueta, control, ayuda) => h("div", { class: "campo" }, h("label", { for: `fw-${clave}` }, etiqueta), control, ayuda && h("span", { class: "ayuda" }, ayuda), errores[clave]);
  const guardar = h("button", { class: "boton boton--principal", type: "submit" }, actual ? "Guardar" : "Publicar formulario");
  const formulario = h("form", { class: "formulario formulario--una", novalidate: true },
    campo("titulo", "Título", h("input", { id: "fw-titulo", maxlength: "120", value: datos.titulo, placeholder: "Pide presupuesto", oninput: (e) => { datos.titulo = e.target.value; } })),
    campo("slug", "Dirección", h("input", { id: "fw-slug", maxlength: "60", value: datos.slug, placeholder: "Se crea a partir del título", oninput: (e) => { datos.slug = e.target.value; } }), "formulario.html?f=…"),
    campo("recurso", "Al enviarlo se crea un…", h("select", { id: "fw-recurso", disabled: !!actual, onchange: (e) => { datos.recurso = e.target.value; datos.campos = []; pintarCasillas(); } },
      recursos.map((r) => h("option", { value: r.id, selected: r.id === datos.recurso }, r.titulo)))),
    h("fieldset", { class: "lista-modulos" }, h("legend", {}, "Campos del formulario"), casillas, errores.campos),
    campo("mensaje", "Mensaje de agradecimiento", h("input", { id: "fw-mensaje", maxlength: "255", value: datos.mensaje ?? "", placeholder: "¡Gracias! Te contactaremos pronto.", oninput: (e) => { datos.mensaje = e.target.value; } })),
    h("div", { class: "modal-pie" }, h("button", { class: "boton", type: "button", onclick: () => dialogo.close() }, "Cancelar"), guardar));

  const dialogo = h("dialog", { class: "modal", "aria-labelledby": "fw-titulo-modal" },
    h("h2", { id: "fw-titulo-modal" }, actual ? `Editar «${actual.titulo}»` : "Nuevo formulario web"), formulario);

  formulario.addEventListener("submit", async (e) => {
    e.preventDefault();
    Object.values(errores).forEach((x) => (x.textContent = ""));
    guardar.disabled = true;
    try {
      const r = recursos.find((x) => x.id === datos.recurso);
      const campos = [...new Set([...r.campos.filter((c) => c.obligatorio).map((c) => c.clave), ...datos.campos])];
      const cuerpo = { titulo: datos.titulo, slug: datos.slug, recurso: datos.recurso, campos, mensaje: datos.mensaje };
      await api("formularios", actual ? { metodo: "PUT", id: actual.id, cuerpo } : { metodo: "POST", cuerpo });
      aviso(actual ? "Formulario guardado" : "Formulario publicado");
      dialogo.close();
      document.querySelector(".vista")?.replaceWith(await vista());
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
