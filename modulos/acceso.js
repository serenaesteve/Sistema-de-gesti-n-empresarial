// Pantalla de inicio de sesión.

import { api } from "../nucleo/js/api.js";
import { h } from "../nucleo/js/dom.js";

const DEMO = [
  { rol: "Administración", email: "serena@blush.test" },
  { rol: "Comercial", email: "pablo@blush.test" },
];

export function vista() {
  const error = h("span", { class: "error", id: "acceso-error", role: "alert" });
  const email = h("input", { id: "acceso-email", name: "email", type: "email", autocomplete: "username", required: true, "aria-describedby": "acceso-error" });
  const clave = h("input", { id: "acceso-clave", name: "clave", type: "password", autocomplete: "current-password", required: true, "aria-describedby": "acceso-error" });
  const entrar = h("button", { class: "boton boton--principal", type: "submit" }, "Entrar");

  const formulario = h("form", { class: "acceso-formulario", novalidate: true },
    h("div", { class: "campo" }, h("label", { for: "acceso-email" }, "Email"), email),
    h("div", { class: "campo" }, h("label", { for: "acceso-clave" }, "Contraseña"), clave),
    error,
    entrar,
  );

  formulario.addEventListener("submit", async (evento) => {
    evento.preventDefault();
    error.textContent = "";
    entrar.disabled = true;
    try {
      await api("entrar", { metodo: "POST", cuerpo: { email: email.value, clave: clave.value } });
      location.reload();
    } catch (e) {
      error.textContent = e.message;
      clave.value = "";
      clave.focus();
      entrar.disabled = false;
    }
  });

  // Cuentas de demostración: un clic rellena el formulario
  const demo = h("div", { class: "acceso-demo" },
    h("p", {}, "Entorno de demostración. Contraseña: ", h("code", {}, "blush2026")),
    DEMO.map((d) => h("button", {
      class: "boton boton--pequeno", type: "button",
      onclick: () => { email.value = d.email; clave.value = "blush2026"; entrar.focus(); },
    }, d.rol)),
  );

  setTimeout(() => email.focus());

  return h("section", { class: "acceso" },
    h("div", { class: "tarjeta vidrio acceso-tarjeta" },
      h("p", { class: "logo" }, "serena", h("span", {}, "|"), h("em", {}, "blush")),
      h("h1", {}, "Iniciar sesión"),
      h("p", { class: "acceso-texto" }, "Accede con tu cuenta corporativa."),
      formulario,
      demo,
    ),
  );
}
