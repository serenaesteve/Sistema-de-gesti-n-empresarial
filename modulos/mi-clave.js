// Ventana para que cada usuario cambie su propia contraseña.

import { api } from "../nucleo/js/api.js";
import { h, aviso } from "../nucleo/js/dom.js";

export function cambiarClave() {
  const errores = { actual: h("span", { class: "error", id: "error-actual" }), nueva: h("span", { class: "error", id: "error-nueva" }) };
  const campo = (nombre, etiqueta, autocompletar) => h("div", { class: "campo campo--ancho" },
    h("label", { for: `clave-${nombre}` }, etiqueta),
    h("input", { id: `clave-${nombre}`, name: nombre, type: "password", autocomplete: autocompletar, required: true, "aria-describedby": `error-${nombre}` }),
    errores[nombre]);

  const guardar = h("button", { class: "boton boton--principal", type: "submit" }, "Cambiar contraseña");
  const formulario = h("form", { class: "formulario formulario--una", novalidate: true },
    campo("actual", "Contraseña actual", "current-password"),
    campo("nueva", "Contraseña nueva (mínimo 8 caracteres)", "new-password"),
    h("div", { class: "modal-pie" },
      h("button", { class: "boton", type: "button", onclick: () => dialogo.close() }, "Cancelar"),
      guardar));

  const dialogo = h("dialog", { class: "modal", "aria-labelledby": "clave-titulo" },
    h("h2", { id: "clave-titulo" }, "Cambiar mi contraseña"),
    formulario);

  formulario.addEventListener("submit", async (evento) => {
    evento.preventDefault();
    Object.values(errores).forEach((e) => (e.textContent = ""));
    guardar.disabled = true;
    try {
      await api("mi-clave", { metodo: "POST", cuerpo: Object.fromEntries(new FormData(formulario)) });
      dialogo.close();
      aviso("Contraseña actualizada");
    } catch (error) {
      const campos = Object.keys(error.errores ?? {});
      campos.forEach((c) => { if (errores[c]) errores[c].textContent = error.errores[c]; });
      if (!campos.length) aviso(error.message, "error");
    } finally {
      guardar.disabled = false;
    }
  });

  dialogo.addEventListener("close", () => dialogo.remove());
  document.body.append(dialogo);
  dialogo.showModal();
}
