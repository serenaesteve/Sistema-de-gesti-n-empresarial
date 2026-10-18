// Asistente de instalación (RA2). Se abre solo la primera vez: index.html redirige aquí
// mientras el ERP no está instalado. Pasos: requisitos → perfil → base de datos →
// empresa y administrador → confirmar. La API vuelve a validar todo al instalar.

import { api } from "./api.js";
import { h, icono, aviso, descargar } from "./dom.js";

const PASOS = ["Requisitos", "Perfil", "Base de datos", "Empresa", "Confirmar"];
const main = document.getElementById("aplicacion");

const estado = await api("estado");
if (estado.instalado) {
  location.replace("./");
  await new Promise(() => {});
}

const info = await api("requisitos");
const modulos = Object.fromEntries(info.modulos.map((m) => [m.id, m]));
const perfiles = info.perfiles;

// Lo que se enviará a la API al instalar
const datos = {
  perfil: perfiles[0]?.id ?? "personalizado",
  modulos: [],
  motor: info.motor_por_defecto === "mysql" ? "mysql" : "sqlite",
  mysql: { ...info.mysql_por_defecto, clave: "" },
  empresa: { nombre: "", nif: "", direccion: "", email: "" },
  admin: { nombre: "", email: "", clave: "" },
  entorno: "explotacion",
  demo: true,
};
let paso = 0;
let erroresServidor = {};

pintar();

// ---------- Lógica ----------

// Módulos que se instalarán: los del perfil (o los marcados) más sus dependencias y el núcleo
function modulosFinales() {
  const elegidos = datos.perfil === "personalizado" ? datos.modulos : perfiles.find((p) => p.id === datos.perfil).modulos;
  const resultado = new Set(["nucleo"]);
  const pendientes = [...elegidos];
  while (pendientes.length) {
    const id = pendientes.shift();
    if (resultado.has(id)) continue;
    resultado.add(id);
    pendientes.push(...modulos[id].dependencias_ids);
  }
  return Object.keys(modulos).filter((id) => resultado.has(id));
}

// Comprobaciones mínimas antes de avanzar (el servidor valida de verdad al instalar)
function erroresDelPaso(n) {
  const e = {};
  if (n === 0 && !info.ok) e.requisitos = "El servidor no cumple los requisitos obligatorios.";
  if (n === 1 && datos.perfil === "personalizado" && !datos.modulos.length) e.modulos = "Elige al menos un módulo.";
  if (n === 2 && datos.motor === "mysql") {
    for (const c of ["host", "bd", "usuario"]) if (!datos.mysql[c].trim()) e[`mysql.${c}`] = "Este campo es obligatorio";
  }
  if (n === 3) {
    if (!datos.empresa.nombre.trim()) e["empresa.nombre"] = "Este campo es obligatorio";
    if (!datos.admin.nombre.trim()) e["admin.nombre"] = "Este campo es obligatorio";
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(datos.admin.email)) e["admin.email"] = "Email no válido";
    if (datos.admin.clave.length < 8) e["admin.clave"] = "Mínimo 8 caracteres";
  }
  return e;
}

// Paso en el que está cada campo, para volver a él si el servidor devuelve un error
function pasoDeCampo(campo) {
  if (campo.startsWith("mysql") || campo === "motor") return 2;
  if (campo.startsWith("empresa") || campo.startsWith("admin")) return 3;
  if (campo === "perfil" || campo === "modulos") return 1;
  return 4;
}

async function instalar(boton) {
  boton.disabled = true;
  boton.textContent = "Instalando…";
  try {
    const resultado = await api("instalar", { metodo: "POST", cuerpo: { ...datos, modulos: modulosFinales() } });
    pintarTerminado(resultado);
  } catch (error) {
    erroresServidor = error.errores ?? {};
    const campos = Object.keys(erroresServidor);
    aviso(error.message, "error");
    if (campos.length) paso = Math.min(...campos.map(pasoDeCampo));
    pintar();
  }
}

// ---------- Interfaz ----------

function pintar() {
  const errores = { ...erroresDelPaso(paso), ...erroresServidor };
  const contenido = [vistaRequisitos, vistaPerfil, vistaBaseDatos, vistaEmpresa, vistaConfirmar][paso](errores);

  const atras = paso > 0 && h("button", { class: "boton", type: "button", onclick: () => { paso--; erroresServidor = {}; pintar(); } }, icono("anterior"), "Atrás");
  const siguiente = paso < PASOS.length - 1
    ? h("button", { class: "boton boton--principal", type: "submit" }, "Siguiente", icono("siguiente"))
    : h("button", { class: "boton boton--principal", type: "submit" }, "Instalar");

  const formulario = h("form", { class: "instalador-cuerpo", novalidate: true }, contenido,
    h("div", { class: "instalador-pie" }, atras || h("span"), siguiente));

  formulario.addEventListener("submit", (evento) => {
    evento.preventDefault();
    const errores = erroresDelPaso(paso);
    if (Object.keys(errores).length) {
      erroresServidor = {};
      pintar();
      main.querySelector("[aria-invalid], .error:not(:empty)")?.scrollIntoView({ block: "center" });
      return;
    }
    if (paso === PASOS.length - 1) return instalar(siguiente);
    erroresServidor = Object.fromEntries(Object.entries(erroresServidor).filter(([c]) => pasoDeCampo(c) > paso));
    paso++;
    pintar();
  });

  main.replaceChildren(h("section", { class: "instalador tarjeta vidrio" },
    h("p", { class: "logo" }, "serena", h("span", {}, "|"), h("em", {}, "blush")),
    h("h1", {}, "Instalación"),
    h("ol", { class: "pasos", "aria-label": "Pasos de la instalación" }, PASOS.map((nombre, i) =>
      h("li", { class: i === paso ? "actual" : i < paso ? "hecho" : "", "aria-current": i === paso ? "step" : null },
        h("span", { class: "numero" }, i < paso ? icono("ok") : String(i + 1)), h("span", { class: "nombre" }, nombre)))),
    formulario,
  ));
  main.querySelector(".instalador-cuerpo input:not([type=radio]):not([type=checkbox])")?.focus();
}

function campo(ruta, etiqueta, atributos, errores, ayuda) {
  const [grupo, clave] = ruta.split(".");
  const id = `instalar-${grupo}-${clave}`;
  const control = h("input", {
    id, value: datos[grupo][clave] ?? "", "aria-invalid": errores[ruta] ? "true" : null,
    "aria-describedby": `${id}-error`, ...atributos,
    oninput: (e) => { datos[grupo][clave] = e.target.value; },
  });
  return h("div", { class: "campo" },
    h("label", { for: id }, etiqueta, atributos.required && h("span", { class: "obligatorio", "aria-hidden": "true" }, " *")),
    control, ayuda && h("small", { class: "ayuda" }, ayuda),
    h("span", { class: "error", id: `${id}-error` }, errores[ruta] ?? ""));
}

function vistaRequisitos(errores) {
  return [
    h("h2", {}, "1. Requisitos del servidor"),
    h("p", { class: "nota" }, "Se comprueba que PHP y la carpeta de datos permiten instalar el ERP."),
    h("ul", { class: "comprobaciones" }, info.comprobaciones.map((c) =>
      h("li", { class: c.ok ? "ok" : c.obligatorio ? "error" : "aviso" },
        h("span", { class: "icono", role: "img", "aria-label": c.ok ? "Correcto" : "No cumple" }, icono(c.ok ? "ok" : c.obligatorio ? "fallo" : "alerta")),
        h("span", { class: "nombre" }, c.nombre),
        h("span", { class: "valor" }, c.valor),
        !c.ok && h("span", { class: "nota" }, c.obligatorio ? "Obligatorio para instalar." : "Opcional: solo hace falta para ese motor de base de datos.")))),
    errores.requisitos && h("p", { class: "error", role: "alert" }, errores.requisitos),
  ];
}

function vistaPerfil(errores) {
  const opcion = (id, nombre, descripcion, lista) =>
    h("label", { class: `perfil ${datos.perfil === id ? "elegido" : ""}` },
      h("input", { type: "radio", name: "perfil", value: id, checked: datos.perfil === id, onchange: () => { datos.perfil = id; pintar(); } }),
      h("span", { class: "perfil-texto" },
        h("strong", {}, nombre), h("small", {}, descripcion),
        lista && h("span", { class: "perfil-modulos" }, lista.map((m) => modulos[m].nombre).join(" · "))));

  const marcados = new Set(modulosFinales());
  const personalizado = datos.perfil === "personalizado" && h("fieldset", { class: "lista-modulos" },
    h("legend", {}, "Módulos"),
    Object.values(modulos).filter((m) => !m.obligatorio).map((m) => {
      const elegido = datos.modulos.includes(m.id);
      const porDependencia = marcados.has(m.id) && !elegido;
      return h("label", { class: "check" },
        h("input", {
          type: "checkbox", checked: marcados.has(m.id), disabled: porDependencia,
          onchange: (e) => {
            datos.modulos = e.target.checked ? [...datos.modulos, m.id] : datos.modulos.filter((x) => x !== m.id);
            pintar();
          },
        }),
        h("span", {}, h("strong", {}, m.nombre), " — ", m.descripcion,
          porDependencia && h("em", { class: "nota" }, " (lo necesita otro módulo elegido)")));
    }),
    errores.modulos && h("p", { class: "error", role: "alert" }, errores.modulos));

  return [
    h("h2", {}, "2. Perfil de instalación"),
    h("p", { class: "nota" }, "Elige el tipo de empresa: se instalan solo los módulos que necesita. Después se pueden activar o desactivar en Administración → Módulos."),
    h("div", { class: "perfiles", role: "radiogroup" },
      perfiles.map((p) => opcion(p.id, p.nombre, p.descripcion, p.modulos)),
      opcion("personalizado", "Personalizada", "Elige tú los módulos uno a uno.", null)),
    personalizado,
  ];
}

function vistaBaseDatos(errores) {
  const perfil = perfiles.find((p) => p.id === datos.perfil);
  const opcion = (motor, nombre, descripcion) =>
    h("label", { class: `perfil ${datos.motor === motor ? "elegido" : ""}` },
      h("input", { type: "radio", name: "motor", value: motor, checked: datos.motor === motor, onchange: () => { datos.motor = motor; erroresServidor = {}; pintar(); } }),
      h("span", { class: "perfil-texto" }, h("strong", {}, nombre), h("small", {}, descripcion),
        perfil?.motor === motor && h("span", { class: "perfil-modulos" }, `Recomendado para «${perfil.nombre}»`)));

  const resultado = h("p", { class: "resultado-prueba", role: "status" });
  const probar = h("button", { class: "boton", type: "button", onclick: async () => {
    resultado.textContent = "Probando…";
    resultado.className = "resultado-prueba";
    try {
      const r = await api("probar-bd", { metodo: "POST", cuerpo: { mysql: datos.mysql } });
      resultado.textContent = `Conexión correcta · MySQL ${r.version}`;
      resultado.classList.add("ok");
    } catch (error) {
      resultado.textContent = error.errores?.mysql ?? error.message;
      resultado.classList.add("fallo");
    }
  } }, "Probar conexión");

  return [
    h("h2", {}, "3. Base de datos"),
    h("div", { class: "perfiles perfiles--dos", role: "radiogroup" },
      opcion("sqlite", "SQLite", "Un archivo en datos/. No necesita servidor: ideal para una persona o pocas."),
      opcion("mysql", "MySQL / MariaDB", "Servidor de base de datos: mejor con varios usuarios a la vez. La base de datos debe existir y estar vacía.")),
    datos.motor === "mysql" && h("div", { class: "formulario" },
      campo("mysql.host", "Servidor", { required: true }, errores),
      campo("mysql.bd", "Base de datos", { required: true }, errores),
      campo("mysql.usuario", "Usuario", { required: true, autocomplete: "off" }, errores),
      campo("mysql.clave", "Contraseña", { type: "password", autocomplete: "new-password" }, errores),
      h("div", { class: "campo campo--ancho" }, h("div", { class: "fila-prueba" }, probar, resultado),
        errores.mysql && h("span", { class: "error" }, errores.mysql))),
  ];
}

function vistaEmpresa(errores) {
  return [
    h("h2", {}, "4. Empresa y administrador"),
    h("div", { class: "formulario" },
      h("p", { class: "subtitulo campo--ancho" }, "Datos de la empresa (aparecen en las facturas)"),
      campo("empresa.nombre", "Razón social", { required: true, maxlength: "255" }, errores),
      campo("empresa.nif", "NIF / CIF", { maxlength: "20" }, errores),
      campo("empresa.direccion", "Dirección", { maxlength: "255" }, errores),
      campo("empresa.email", "Email", { type: "email" }, errores),
      h("p", { class: "subtitulo campo--ancho" }, "Primer usuario con rol de administración"),
      campo("admin.nombre", "Nombre", { required: true, autocomplete: "name" }, errores),
      campo("admin.email", "Email (para entrar)", { required: true, type: "email", autocomplete: "username" }, errores),
      campo("admin.clave", "Contraseña", { required: true, type: "password", autocomplete: "new-password", minlength: "8" }, errores, "Mínimo 8 caracteres"),
    ),
  ];
}

function vistaConfirmar() {
  const perfil = perfiles.find((p) => p.id === datos.perfil)?.nombre ?? "Personalizada";
  const filas = [
    ["Perfil", perfil],
    ["Módulos", modulosFinales().map((m) => modulos[m].nombre).join(", ")],
    ["Entorno", datos.entorno],
    ["Base de datos", datos.motor === "mysql" ? `MySQL · ${datos.mysql.usuario}@${datos.mysql.host}/${datos.mysql.bd}` : "SQLite · datos/erp.sqlite"],
    ["Empresa", [datos.empresa.nombre, datos.empresa.nif].filter(Boolean).join(" · ")],
    ["Administrador", `${datos.admin.nombre} · ${datos.admin.email}`],
  ];
  return [
    h("h2", {}, "5. Confirmar"),
    h("dl", { class: "datos resumen-instalacion" }, filas.map(([k, v]) => [h("dt", {}, k), h("dd", {}, v)])),
    h("fieldset", { class: "lista-modulos" }, h("legend", {}, "Entorno"),
      Object.entries(info.entornos).map(([valor, texto]) => h("label", { class: "check" },
        h("input", { type: "radio", name: "entorno", value: valor, checked: datos.entorno === valor, onchange: () => { datos.entorno = valor; } }),
        h("span", {}, h("strong", {}, texto.split(": ")[0]), " — ", texto.split(": ")[1])))),
    h("label", { class: "check" },
      h("input", { type: "checkbox", checked: datos.demo, onchange: (e) => { datos.demo = e.target.checked; } }),
      h("span", {}, h("strong", {}, "Cargar datos de demostración"), " — clientes, productos, pedidos y facturas de ejemplo de los módulos elegidos, y un usuario comercial (pablo@blush.test / blush2026). Desmárcalo para empezar vacío.")),
  ];
}

function pintarTerminado(resultado) {
  main.replaceChildren(h("section", { class: "instalador tarjeta vidrio instalador--fin" },
    h("span", { class: "vacio-icono icono-ok", "aria-hidden": "true" }, icono("ok")),
    h("h1", {}, "Blush ERP está instalado"),
    h("p", { class: "nota" }, `Perfil «${perfiles.find((p) => p.id === resultado.perfil)?.nombre ?? "Personalizada"}» con ${resultado.motor === "mysql" ? "MySQL" : "SQLite"}. Módulos: ${resultado.modulos.map((m) => modulos[m].nombre).join(", ")}.`),
    h("p", { class: "nota" }, `Entra con ${datos.admin.email} y la contraseña que has elegido.`),
    h("div", { class: "pagina-acciones" },
      h("button", { class: "boton", type: "button", onclick: () => descargar(`informe-instalacion-${new Date().toLocaleDateString("sv-SE")}.md`, resultado.informe) },
        icono("descargar"), "Descargar informe de instalación"),
      h("a", { class: "boton boton--principal", href: "./" }, "Ir al ERP")),
  ));
}
