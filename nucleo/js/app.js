// Arranque de la aplicación: sesión, componentes, menú, tema y navegación por #/ruta.

import { incluir } from "./incluir.js";
import { api } from "./api.js";
import { h, icono, vistaError, estadoVacio, aviso } from "./dom.js";
import { cambiarClave } from "../../modulos/mi-clave.js";
import * as acceso from "../../modulos/acceso.js";
import * as panel from "../../modulos/panel.js";
import * as crud from "../../modulos/crud.js";
import * as registro from "../../modulos/registro.js";
import * as sistema from "../../modulos/sistema.js";
import * as fichaCliente from "../../modulos/ficha-cliente.js";

// Vistas propias; el resto de módulos del esquema usan la vista genérica crud.
const especiales = { panel, registro, sistema };
// Vistas de detalle: #/clientes/3
const fichas = { clientes: fichaCliente };

const app = { esquema: {}, menu: [], info: null, usuario: null, permisos: {} };
let turno = 0;             // evita pintar una vista antigua si el usuario navega rápido
let controlador = null;    // permite a cada vista limpiar sus eventos al salir

await incluir();
prepararTema();

const sesion = await api("sesion").catch((error) => {
  document.getElementById("aplicacion").replaceChildren(vistaError(error));
  throw error;
});

if (!sesion.usuario) {
  mostrarAcceso();
} else {
  app.usuario = sesion.usuario;
  app.permisos = sesion.permisos;
  await iniciar();
}

async function iniciar() {
  prepararCabecera();
  try {
    [app.menu, app.esquema, app.info] = await Promise.all([api("menu"), api("esquema"), api("info")]);
  } catch (error) {
    document.getElementById("aplicacion").replaceChildren(vistaError(error));
    throw error;
  }
  pintarMenu();
  pintarUsuario();
  document.addEventListener("erp:sesion-caducada", () => {
    aviso("Tu sesión ha caducado", "error");
    setTimeout(() => location.reload(), 1200);
  });
  addEventListener("hashchange", navegar);
  navegar();
}

function mostrarAcceso() {
  document.body.classList.add("sin-sesion");
  document.title = "Acceso · Blush ERP";
  document.getElementById("aplicacion").replaceChildren(acceso.vista());
}

// ---------- Navegación ----------

async function navegar() {
  const [camino, consultaTexto = ""] = location.hash.replace(/^#\/?/, "").split("?");
  const [ruta, idTexto] = (camino || "panel").split("/");
  const consulta = new URLSearchParams(consultaTexto);
  const id = idTexto ? Number(idTexto) : null;
  const miTurno = ++turno;
  controlador?.abort();
  controlador = new AbortController();

  const main = document.getElementById("aplicacion");
  const entrada = app.menu.find((m) => m.id === ruta);
  const modulo = app.esquema[ruta];

  document.querySelectorAll("#menu-lista a").forEach((a) => {
    if (a.dataset.ruta === ruta) a.setAttribute("aria-current", "page");
    else a.removeAttribute("aria-current");
  });
  configurarHerramientas(id ? null : modulo, ruta);
  main.setAttribute("aria-busy", "true");

  let vista;
  try {
    const contexto = {
      señal: controlador.signal, esquema: app.esquema, info: app.info, usuario: app.usuario,
      permisos: app.permisos, puede: (accion, recurso = ruta) => puede(accion, recurso),
      recurso: ruta, modulo, id, consulta,
    };
    if (!entrada) vista = noEncontrada(ruta);
    else if (id && fichas[ruta]) vista = await fichas[ruta].vista(contexto);
    else if (especiales[ruta]) vista = await especiales[ruta].vista(contexto);
    else if (modulo) vista = await crud.vista(contexto);
    else vista = noEncontrada(ruta);
  } catch (error) {
    vista = vistaError(error);
  }

  if (miTurno !== turno) return;
  main.replaceChildren(vista);
  main.removeAttribute("aria-busy");
  main.scrollTop = 0;
  document.title = `${entrada?.titulo ?? "Blush"} · Blush ERP`;
}

function noEncontrada(ruta) {
  return estadoVacio("brujula", "Página no encontrada", `No existe «${ruta}» o tu rol no tiene acceso.`,
    h("a", { class: "boton boton--principal", href: "#/panel" }, "Volver al panel"));
}

function puede(accion, recurso) {
  return app.permisos[recurso]?.includes(accion) ?? false;
}

// ---------- Menú, cabecera y pie ----------

// Menú agrupado por secciones (Ventas, Compras, Administración)
function pintarMenu() {
  const elementos = [];
  let grupo;
  for (const m of app.menu) {
    if (m.grupo && m.grupo !== grupo) elementos.push(h("li", { class: "etiqueta", role: "presentation" }, m.grupo));
    grupo = m.grupo;
    elementos.push(h("li", {}, h("a", { href: `#/${m.id}`, "data-ruta": m.id }, icono(m.icono), m.titulo)));
  }
  document.getElementById("menu-lista").replaceChildren(...elementos);
}

function pintarUsuario() {
  const { app: aplicacion, entorno } = app.info;
  const usuario = app.usuario;
  const rol = usuario.rol === "admin" ? "Administración" : "Comercial";
  const iniciales = usuario.nombre.split(" ").map((p) => p[0]).slice(0, 2).join("");

  document.getElementById("usuario-nombre").textContent = usuario.nombre;
  document.getElementById("usuario-rol").textContent = rol;
  document.getElementById("usuario-iniciales").textContent = iniciales;

  document.getElementById("pie-app").textContent = `${aplicacion.nombre} v${aplicacion.version}`;
  document.getElementById("pie-entorno").replaceChildren(
    h("span", { class: "estado-punto", "aria-hidden": "true" }),
    `${entorno.so} · ${entorno.servidor.replace(/\s*\(.*\)$/, "")} · PHP ${entorno.php} · ${entorno.bd}`,
  );
  document.getElementById("pie-usuario").textContent = `${usuario.nombre} · ${rol}`;
  document.getElementById("menu-empresa").textContent = app.info.empresa.nombre;
  document.getElementById("menu-version").textContent = `ERP-CRM · versión ${aplicacion.version}`;

  const cuenta = document.getElementById("cuenta");
  cuenta.addEventListener("click", () => cambiarClave());

  const salir = document.getElementById("salir");
  salir.replaceChildren(icono("salir"));
  salir.addEventListener("click", async () => {
    await api("salir", { metodo: "POST" }).catch(() => {});
    location.hash = "";
    location.reload();
  });
}

// El buscador y el botón «Nuevo» solo funcionan en los módulos de datos.
// Se comunican con la vista activa mediante eventos (erp:buscar / erp:nuevo).
function prepararCabecera() {
  const buscar = document.getElementById("buscar");
  let espera;

  buscar.addEventListener("input", () => {
    clearTimeout(espera);
    espera = setTimeout(() => {
      document.dispatchEvent(new CustomEvent("erp:buscar", { detail: buscar.value.trim() }));
    }, 250);
  });

  document.getElementById("nuevo").addEventListener("click", () => {
    document.dispatchEvent(new CustomEvent("erp:nuevo"));
  });

  // Atajo: «/» para buscar
  document.addEventListener("keydown", (evento) => {
    const escribiendo = evento.target.closest?.("input, select, textarea, [contenteditable]");
    if (evento.key === "/" && !escribiendo && !buscar.disabled && !document.querySelector("dialog[open]")) {
      evento.preventDefault();
      buscar.focus();
    }
  });
}

function configurarHerramientas(modulo, ruta) {
  const buscar = document.getElementById("buscar");
  const nuevo = document.getElementById("nuevo");
  const puedeCrear = modulo && !modulo.solo_lectura && puede("crear", ruta);

  buscar.value = "";
  buscar.disabled = !modulo;
  buscar.placeholder = modulo ? `Buscar ${modulo.titulo.toLowerCase()}…` : "Buscar…";
  nuevo.disabled = !puedeCrear;
  nuevo.querySelector(".texto-largo").textContent = puedeCrear
    ? `${modulo.femenino ? "Nueva" : "Nuevo"} ${modulo.singular}`
    : "Nuevo";
  nuevo.hidden = !puedeCrear;
}

// ---------- Tema claro / oscuro ----------

function prepararTema() {
  const boton = document.getElementById("tema");
  const oscuroSistema = matchMedia("(prefers-color-scheme: dark)");
  const actual = () => document.documentElement.dataset.theme || (oscuroSistema.matches ? "dark" : "light");

  const pintar = () => {
    const oscuro = actual() === "dark";
    boton.replaceChildren(icono(oscuro ? "sol" : "luna"));
    boton.setAttribute("aria-label", oscuro ? "Cambiar a modo claro" : "Cambiar a modo oscuro");
  };

  boton.addEventListener("click", () => {
    const nuevo = actual() === "dark" ? "light" : "dark";
    document.documentElement.dataset.theme = nuevo;
    try { localStorage.setItem("blush-tema", nuevo); } catch {}
    pintar();
  });
  oscuroSistema.addEventListener("change", pintar);
  pintar();
}
