// Módulos del ERP (RA2): qué incluye cada uno, sus dependencias y si está activo.
// Activar un módulo activa también los que necesita; desactivarlo conserva sus datos.

import { api } from "../nucleo/js/api.js";
import { h, icono, insignia, cabeceraPagina, aviso, confirmar } from "../nucleo/js/dom.js";

export async function vista() {
  const modulos = await api("modulos");
  const activos = modulos.filter((m) => m.activo).length;

  return h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: "Administración",
      titulo: "Módulos",
      descripcion: `${activos} de ${modulos.length} módulos activos. Al desactivar un módulo desaparece del menú, pero sus datos se conservan y vuelven al activarlo.`,
    }),
    h("div", { class: "rejilla rejilla--modulos" }, modulos.map(tarjeta)),
  );
}

function tarjeta(modulo) {
  const bloqueado = modulo.obligatorio || (modulo.activo && modulo.requerido_por.length > 0);
  const motivo = modulo.obligatorio
    ? "Módulo obligatorio"
    : modulo.activo && modulo.requerido_por.length ? `Lo necesita: ${modulo.requerido_por.join(", ")}` : "";

  const boton = h("button", {
    class: `boton ${modulo.activo ? "" : "boton--principal"}`, type: "button",
    disabled: bloqueado, title: motivo || null,
    onclick: () => cambiar(modulo),
  }, modulo.activo ? "Desactivar" : "Activar");

  return h("article", { class: `tarjeta vidrio modulo ${modulo.activo ? "" : "modulo--inactivo"}` },
    h("header", { class: "modulo-cabecera" },
      h("span", { class: "modulo-icono", "aria-hidden": "true" }, icono(modulo.icono)),
      h("div", {},
        h("h2", {}, modulo.nombre),
        h("small", {}, `versión ${modulo.version}`)),
      insignia(modulo.activo ? "Activo" : "Inactivo", modulo.activo ? "verde" : "gris"),
    ),
    h("p", { class: "modulo-descripcion" }, modulo.descripcion),
    h("dl", { class: "modulo-datos" },
      modulo.tablas.length > 0 && [h("dt", {}, "Pantallas"), h("dd", {}, modulo.tablas.join(", "))],
      h("dt", {}, "Necesita"), h("dd", {}, modulo.dependencias.length ? modulo.dependencias.join(", ") : "—"),
      modulo.requerido_por.length > 0 && [h("dt", {}, "Lo usa"), h("dd", {}, modulo.requerido_por.join(", "))],
    ),
    h("footer", { class: "modulo-pie" }, motivo && h("small", {}, motivo), boton),
  );
}

async function cambiar(modulo) {
  const activar = !modulo.activo;
  if (!activar) {
    const ok = await confirmar({
      titulo: `Desactivar ${modulo.nombre}`,
      mensaje: "Sus pantallas dejarán de estar disponibles para todos los usuarios. Los datos no se borran.",
      boton: "Desactivar",
    });
    if (!ok) return;
  }
  try {
    await api("modulos", { metodo: "POST", parametros: { modulo: modulo.id }, cuerpo: { activo: activar } });
    aviso(`${modulo.nombre} ${activar ? "activado" : "desactivado"}`);
    setTimeout(() => location.reload(), 700); // el menú y los permisos cambian
  } catch (error) {
    aviso(error.message, "error");
  }
}
