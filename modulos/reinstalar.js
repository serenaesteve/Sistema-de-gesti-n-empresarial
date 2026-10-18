// Reinstalar (RA2): vuelve al asistente de instalación, guardando antes una copia de seguridad.
// Exige escribir el nombre de la empresa y la contraseña: es una acción que no se deshace sola.

import { api } from "../nucleo/js/api.js";
import { h, aviso } from "../nucleo/js/dom.js";

export async function reinstalar() {
  const { empresa } = await api("info");
  const errores = { confirmacion: h("span", { class: "error" }), clave: h("span", { class: "error" }) };
  const confirmacion = h("input", { id: "reinstalar-confirmacion", autocomplete: "off" });
  const clave = h("input", { id: "reinstalar-clave", type: "password", autocomplete: "current-password" });
  const boton = h("button", { class: "boton boton--peligro", type: "submit" }, "Reinstalar");

  const formulario = h("form", { class: "formulario formulario--una", novalidate: true },
    h("div", { class: "campo" }, h("label", { for: "reinstalar-confirmacion" }, `Escribe «${empresa.nombre}» para confirmar`), confirmacion, errores.confirmacion),
    h("div", { class: "campo" }, h("label", { for: "reinstalar-clave" }, "Tu contraseña"), clave, errores.clave),
    h("div", { class: "modal-pie" }, h("button", { class: "boton", type: "button", onclick: () => dialogo.close() }, "Cancelar"), boton));

  const dialogo = h("dialog", { class: "modal", "aria-labelledby": "reinstalar-titulo" },
    h("h2", { id: "reinstalar-titulo" }, "Reinstalar el ERP"),
    h("p", {}, "Se guardará una copia completa en datos/copias/ y se abrirá el asistente para instalar de nuevo. Los datos actuales dejarán de estar en uso (se pueden recuperar restaurando la copia)."),
    formulario);

  formulario.addEventListener("submit", async (evento) => {
    evento.preventDefault();
    Object.values(errores).forEach((e) => (e.textContent = ""));
    boton.disabled = true;
    try {
      const r = await api("reinstalar", { metodo: "POST", cuerpo: { confirmacion: confirmacion.value, clave: clave.value } });
      aviso(`Copia guardada: datos/copias/${r.copia}`);
      setTimeout(() => location.replace("instalar.html"), 1200);
    } catch (error) {
      const campos = Object.keys(error.errores ?? {});
      campos.forEach((c) => { if (errores[c]) errores[c].textContent = error.errores[c]; });
      if (!campos.length) aviso(error.message, "error");
      boton.disabled = false;
    }
  });

  dialogo.addEventListener("close", () => dialogo.remove());
  document.body.append(dialogo);
  dialogo.showModal();
}
