// Vista genérica de un módulo de datos (clientes, pedidos, facturas, productos, compras…).
// Todo se genera a partir del esquema que envía la API: columnas, formatos, formularios,
// editor de líneas y acciones. Para añadir un módulo nuevo basta con definirlo en api/esquema.php.

import { api, urlApi } from "../nucleo/js/api.js";
import { h, icono, formato, genero, hoyIso, insignia, cabeceraPagina, estadoVacio, aviso, confirmar } from "../nucleo/js/dom.js";

export async function vista({ recurso, modulo, señal, puede, consulta }) {
  const columnas = columnasDe(modulo);
  const campoFecha = Object.keys(modulo.campos).find((c) => modulo.campos[c].tipo === "fecha");
  const estado = { filas: [], total: 0, pagina: 1, paginas: 1, por: 15, q: "", desde: "", hasta: "", orden: { clave: "id", asc: false } };
  const plural = modulo.titulo.toLowerCase();
  const permite = {
    crear: !modulo.solo_lectura && puede("crear"),
    editar: !modulo.solo_lectura && puede("editar"),
    borrar: !modulo.solo_lectura && puede("borrar"),
    facturar: recurso === "pedidos" && puede("crear", "facturas"),
    rectificar: recurso === "facturas" && puede("editar", "facturas"),
  };

  const contador = h("span", { class: "contador" });
  const csv = h("a", { class: "boton", download: "" }, icono("descargar"), "Exportar CSV");
  const cuerpoTabla = h("tbody");
  const cabeceras = columnas.map((c) =>
    h("th", { scope: "col", class: c.alinear },
      c.ordenable === false
        ? c.etiqueta
        : h("button", { class: "ordenar", type: "button", onclick: () => ordenar(c.clave) },
            c.etiqueta, h("span", { class: "flecha", "aria-hidden": "true" }, "↑"))));
  const tabla = h("table", {},
    h("caption", { class: "oculto" }, `Listado de ${plural}`),
    h("thead", {}, h("tr", {}, cabeceras, h("th", { class: "acciones" }, h("span", { class: "oculto" }, "Acciones")))),
    cuerpoTabla);
  const paginador = h("nav", { class: "paginador", "aria-label": "Páginas" });
  const zona = h("div", { class: "tarjeta vidrio tabla-contenedor" });

  // Filtro por fechas (solo en módulos con fecha: pedidos, facturas, compras)
  const filtroFechas = campoFecha && h("div", { class: "filtro-fechas", role: "group", "aria-label": "Filtrar por fecha" },
    ["desde", "hasta"].map((limite) =>
      h("label", {}, formato.capital(limite),
        h("input", { type: "date", onchange: (e) => { estado[limite] = e.target.value; estado.pagina = 1; cargar().catch(mostrarError); } }))));

  const raiz = h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: modulo.grupo,
      titulo: modulo.titulo,
      acciones: [contador, filtroFechas, csv],
    }),
    zona,
  );

  // ---------- Datos ----------

  async function cargar() {
    const parametros = {
      q: estado.q, desde: estado.desde, hasta: estado.hasta,
      orden: estado.orden.clave, dir: estado.orden.asc ? "asc" : "desc",
    };
    const datos = await api(recurso, { parametros: { ...parametros, pagina: estado.pagina } });
    Object.assign(estado, datos);
    csv.href = urlApi(recurso, { ...parametros, formato: "csv" });
    pintar();
  }

  function pintar() {
    const total = estado.total;
    contador.textContent = `${total} ${total === 1 ? modulo.singular : plural}`;

    if (!total) {
      const filtrando = estado.q || estado.desde || estado.hasta;
      zona.replaceChildren(filtrando
        ? estadoVacio("buscar", "Sin resultados", "Ningún registro coincide con la búsqueda o las fechas indicadas.")
        : estadoVacio(modulo.icono, `Todavía no hay ${plural}`,
            permite.crear ? `Crea ${genero(modulo, "el primer", "la primera")} ${modulo.singular} para empezar.` : "",
            permite.crear && h("button", { class: "boton boton--principal", type: "button", onclick: () => abrirFormulario() },
              icono("crear"), `${genero(modulo, "Nuevo", "Nueva")} ${modulo.singular}`)));
      return;
    }

    cuerpoTabla.replaceChildren(...estado.filas.map((fila) =>
      h("tr", {}, columnas.map((c) => celda(c, fila, modulo, recurso)), h("td", { class: "acciones" }, acciones(fila)))));

    cabeceras.forEach((th, i) => {
      if (columnas[i].clave === estado.orden.clave) th.setAttribute("aria-sort", estado.orden.asc ? "ascending" : "descending");
      else th.removeAttribute("aria-sort");
    });

    pintarPaginador();
    if (zona.firstChild !== tabla) zona.replaceChildren(tabla, paginador);
  }

  function pintarPaginador() {
    const desde = (estado.pagina - 1) * estado.por + 1;
    const hasta = Math.min(estado.pagina * estado.por, estado.total);
    const ir = (pagina) => { estado.pagina = pagina; cargar().catch(mostrarError); };
    paginador.hidden = estado.paginas <= 1;
    paginador.replaceChildren(
      h("span", {}, `${desde}–${hasta} de ${estado.total}`),
      h("div", { class: "paginador-botones" },
        h("button", { class: "boton-icono", type: "button", "aria-label": "Página anterior", disabled: estado.pagina <= 1, onclick: () => ir(estado.pagina - 1) }, icono("anterior")),
        h("span", { "aria-current": "page" }, `Página ${estado.pagina} de ${estado.paginas}`),
        h("button", { class: "boton-icono", type: "button", "aria-label": "Página siguiente", disabled: estado.pagina >= estado.paginas, onclick: () => ir(estado.pagina + 1) }, icono("siguiente")),
      ),
    );
  }

  function acciones(fila) {
    const bloqueada = modulo.bloqueo && fila[modulo.bloqueo];
    const botones = [];
    const boton = (nombreIcono, etiqueta, accion, destacado = false) =>
      h("button", { class: `boton-icono ${destacado ? "boton-icono--destacado" : ""}`, type: "button", "aria-label": `${etiqueta} ${nombreDe(fila)}`, title: etiqueta, onclick: accion }, icono(nombreIcono));

    if (permite.facturar && fila.estado === "entregado" && !fila.factura) botones.push(boton("factura", "Facturar", () => facturar(fila), true));
    if (permite.rectificar && fila.tipo === "ordinaria" && !fila.rectificada) botones.push(boton("rectificar", "Rectificar", () => rectificar(fila)));
    if (modulo.ver) {
      botones.push(h("a", { class: "boton-icono", href: plantilla(modulo.ver, fila), target: "_blank", rel: "noopener", "aria-label": `Ver ${nombreDe(fila)}`, title: "Ver e imprimir" }, icono("ver")));
    }
    if (permite.editar && !bloqueada) botones.push(boton("editar", "Editar", () => abrirFormulario(fila)));
    if (permite.borrar && !bloqueada) botones.push(boton("borrar", "Borrar", () => borrar(fila)));
    if (bloqueada && (permite.editar || permite.borrar)) {
      botones.push(h("span", { class: "candado", title: `Bloqueado: facturado en ${fila[modulo.bloqueo]}`, role: "img", "aria-label": "Bloqueado por factura" }, icono("candado")));
    }
    return h("div", { class: "acciones-fila" }, botones);
  }

  function ordenar(clave) {
    estado.orden = { clave, asc: estado.orden.clave === clave ? !estado.orden.asc : true };
    estado.pagina = 1;
    cargar().catch(mostrarError);
  }

  function nombreDe(fila) {
    return modulo.principal === "id" ? `${modulo.singular} #${fila.id}` : `«${fila[modulo.principal]}»`;
  }

  // ---------- Alta y edición ----------

  async function abrirFormulario(fila = null, lineasIniciales = null) {
    if (!(fila ? permite.editar : permite.crear)) return;

    // Opciones de los desplegables: relaciones del registro y de sus líneas
    const relaciones = [
      ...Object.entries(modulo.campos),
      ...Object.entries(modulo.lineas?.campos ?? {}),
    ].filter(([, def]) => def.tipo === "relacion");
    let opciones;
    try {
      opciones = Object.fromEntries(await Promise.all(
        relaciones.map(async ([campo, def]) => [campo, await api(def.tabla, { parametros: { orden: def.mostrar, dir: "asc" } })])));
    } catch (error) {
      mostrarError(error);
      return;
    }

    const errores = {};
    const campos = Object.entries(modulo.campos).filter(([, def]) => !def.auto);
    const controles = campos.map(([campo, def]) => {
      const inicial = fila ? fila[campo] : (def.defecto === "hoy" ? hoyIso() : def.defecto);
      const control = crearControl(campo, def, inicial, opciones[campo], !!fila);
      errores[campo] = h("span", { class: "error", id: `error-${campo}` });
      control.setAttribute("aria-describedby", `error-${campo}`);
      const obligatorio = def.obligatorio && !(def.tipo === "clave" && fila);
      return h("div", { class: "campo" },
        h("label", { for: control.id }, def.etiqueta, obligatorio && h("span", { class: "obligatorio", "aria-hidden": "true" }, " *")),
        control,
        errores[campo]);
    });

    const editor = modulo.lineas && editorLineas(modulo.lineas, fila?.lineas ?? lineasIniciales ?? [{}], opciones);

    const enviar = h("button", { class: "boton boton--principal", type: "submit" },
      fila ? "Guardar cambios" : `Crear ${modulo.singular}`);
    const formulario = h("form", { class: "formulario", novalidate: true },
      controles,
      editor?.elemento,
      h("div", { class: "modal-pie" },
        h("button", { class: "boton", type: "button", onclick: () => dialogo.close() }, "Cancelar"),
        enviar));

    const dialogo = h("dialog", { class: `modal ${editor ? "modal--ancha" : ""}`, "aria-labelledby": "formulario-titulo" },
      h("h2", { id: "formulario-titulo" },
        fila ? `Editar ${nombreDe(fila)}` : `${genero(modulo, "Nuevo", "Nueva")} ${modulo.singular}`),
      formulario);

    formulario.addEventListener("submit", async (evento) => {
      evento.preventDefault();
      Object.values(errores).forEach((e) => (e.textContent = ""));
      editor?.limpiarErrores();
      formulario.querySelectorAll("[aria-invalid]").forEach((c) => c.removeAttribute("aria-invalid"));
      enviar.disabled = true;

      const cuerpo = Object.fromEntries(campos.map(([campo]) => [campo, formulario.elements[campo].value]));
      if (editor) cuerpo.lineas = editor.valores();

      try {
        await api(recurso, { metodo: fila ? "PUT" : "POST", id: fila?.id, cuerpo });
        dialogo.close();
        aviso(`${formato.capital(modulo.singular)} ${fila ? genero(modulo, "actualizado", "actualizada") : genero(modulo, "creado", "creada")}`);
        if (!fila) estado.pagina = 1;
        await cargar();
      } catch (error) {
        const conError = Object.keys(error.errores ?? {});
        if (!conError.length) return mostrarError(error);
        conError.forEach((clave) => {
          if (clave.startsWith("lineas")) return editor?.mostrarError(clave, error.errores[clave]);
          if (errores[clave]) errores[clave].textContent = error.errores[clave];
          formulario.elements[clave]?.setAttribute("aria-invalid", "true");
        });
        formulario.querySelector("[aria-invalid]")?.focus();
      } finally {
        enviar.disabled = false;
      }
    });

    dialogo.addEventListener("close", () => dialogo.remove());
    document.body.append(dialogo);
    dialogo.showModal();
  }

  // ---------- Borrado ----------

  async function borrar(fila) {
    const ok = await confirmar({
      titulo: `Borrar ${nombreDe(fila)}`,
      mensaje: "Esta acción no se puede deshacer. Quedará anotada en el registro de operaciones.",
    });
    if (!ok) return;
    try {
      await api(recurso, { metodo: "DELETE", id: fila.id });
      aviso(`${formato.capital(modulo.singular)} ${genero(modulo, "borrado", "borrada")}`);
      if (estado.filas.length === 1 && estado.pagina > 1) estado.pagina--;
      await cargar();
    } catch (error) {
      mostrarError(error);
    }
  }

  // ---------- Facturar y rectificar ----------

  async function facturar(fila) {
    const ok = await confirmar({
      titulo: `Facturar el pedido #${fila.id}`,
      mensaje: `Se emitirá la factura de ${fila.cliente} por ${formato.moneda(fila.total)} + IVA. Después el pedido ya no se podrá modificar.`,
      boton: "Emitir factura",
      peligro: false,
    });
    if (!ok) return;
    try {
      const factura = await api("facturar", { metodo: "POST", id: fila.id });
      aviso(`Factura ${factura.numero} emitida`);
      window.open(`factura.html?id=${factura.id}`, "_blank", "noopener");
      await cargar();
    } catch (error) {
      mostrarError(error);
    }
  }

  async function rectificar(fila) {
    const motivo = await pedirMotivo(fila);
    if (motivo == null) return;
    try {
      const factura = await api("rectificar", { metodo: "POST", id: fila.id, cuerpo: { motivo } });
      aviso(`Factura rectificativa ${factura.numero} emitida`);
      window.open(`factura.html?id=${factura.id}`, "_blank", "noopener");
      await cargar();
    } catch (error) {
      mostrarError(error);
    }
  }

  function mostrarError(error) {
    aviso(error.message, "error");
  }

  // ---------- Eventos de la cabecera (se eliminan al cambiar de vista) ----------

  document.addEventListener("erp:buscar", (evento) => {
    estado.q = evento.detail;
    estado.pagina = 1;
    cargar().catch(mostrarError);
  }, { signal: señal });

  document.addEventListener("erp:nuevo", () => abrirFormulario(), { signal: señal });

  await cargar();

  // #/compras?reponer=10&cantidad=25 abre una compra con ese producto (desde «Stock bajo» del panel)
  if (consulta?.get("reponer") && permite.crear) {
    setTimeout(() => abrirFormulario(null, [{ producto_id: Number(consulta.get("reponer")), cantidad: Number(consulta.get("cantidad")) || 1 }]));
  }
  return raiz;
}

// ---------- Editor de líneas (productos de un pedido o una compra) ----------

function editorLineas(definicion, lineasIniciales, opciones) {
  const campos = Object.entries(definicion.campos);
  const cuerpo = h("tbody");
  const totalCelda = h("td", { class: "numero total-lineas" });
  const errorGeneral = h("span", { class: "error", role: "alert" });
  const [campoRelacion] = campos.find(([, def]) => def.tipo === "relacion");
  const [campoPrecio, defPrecio] = campos.find(([, def]) => def.tipo === "moneda");

  // Precio de una línea: el escrito (coste de compra), el guardado o el del catálogo (venta)
  const precioDe = (fila) => {
    if (!defPrecio.auto) return Number(fila.querySelector(`[data-campo=${campoPrecio}]`).value) || 0;
    const opcion = fila.querySelector(`[data-campo=${campoRelacion}]`).selectedOptions[0];
    return Number(fila.dataset.precioGuardado ?? opcion?.dataset.precio) || 0;
  };

  const recalcular = () => {
    let total = 0;
    cuerpo.querySelectorAll("tr").forEach((fila) => {
      const precio = precioDe(fila);
      const importe = (Number(fila.querySelector("[data-campo=cantidad]").value) || 0) * precio;
      total += importe;
      fila.querySelector(".importe").textContent = formato.moneda(importe);
      const automatico = fila.querySelector(".precio-auto");
      if (automatico) automatico.textContent = formato.moneda(precio);
    });
    totalCelda.textContent = formato.moneda(total);
  };

  const anadir = (linea = {}) => {
    const fila = h("tr", {});
    if (linea.precio != null && defPrecio.auto) fila.dataset.precioGuardado = linea.precio;
    const celdas = campos.map(([campo, def]) => {
      if (def.auto) return h("td", { class: "numero precio-auto" });
      const control = def.tipo === "relacion"
        ? h("select", { "data-campo": campo, "aria-label": def.etiqueta },
            h("option", { value: "" }, `Selecciona ${def.etiqueta.toLowerCase()}…`),
            (opciones[campo] ?? []).map((o) => h("option", { value: o.id, selected: o.id === linea[campo], "data-precio": o.precio },
              def.detalle ? `${o[def.mostrar]} · ${o[def.detalle[0]]} ${def.detalle[1]}` : o[def.mostrar])))
        : h("input", {
            "data-campo": campo, "aria-label": def.etiqueta, type: "number", inputmode: "decimal",
            step: def.tipo === "moneda" ? "0.01" : "1", min: def.minimo ?? "0",
            value: linea[campo] ?? def.defecto ?? "",
          });
      control.addEventListener("input", () => {
        if (def.tipo === "relacion") delete fila.dataset.precioGuardado; // producto nuevo: precio del catálogo
        recalcular();
      });
      return h("td", { class: def.tipo === "relacion" ? "" : "numero" }, control, h("span", { class: "error" }));
    });
    fila.append(...celdas,
      h("td", { class: "numero importe" }),
      h("td", {}, h("button", {
        class: "boton-icono", type: "button", "aria-label": "Quitar línea",
        onclick: () => { fila.remove(); recalcular(); },
      }, icono("quitar"))));
    cuerpo.append(fila);
    recalcular();
  };

  lineasIniciales.forEach(anadir);

  const elemento = h("fieldset", { class: "lineas" },
    h("legend", {}, definicion.etiqueta),
    h("div", { class: "lineas-tabla" },
      h("table", {},
        h("thead", {}, h("tr", {},
          campos.map(([, def]) => h("th", { class: def.tipo === "relacion" ? "" : "numero" }, def.etiqueta)),
          h("th", { class: "numero" }, "Importe"), h("th", {}, h("span", { class: "oculto" }, "Quitar")))),
        cuerpo,
        h("tfoot", {}, h("tr", {},
          h("td", { colspan: campos.length }, h("button", { class: "boton boton--pequeno", type: "button", onclick: () => anadir({}) }, icono("crear"), "Añadir línea")),
          totalCelda, h("td")))),
    ),
    errorGeneral,
  );

  return {
    elemento,
    valores: () => [...cuerpo.querySelectorAll("tr")].map((fila) =>
      Object.fromEntries([...fila.querySelectorAll("[data-campo]")].map((c) => [c.dataset.campo, c.value]))),
    limpiarErrores: () => {
      errorGeneral.textContent = "";
      elemento.querySelectorAll("tbody .error").forEach((e) => (e.textContent = ""));
    },
    // «lineas.1.cantidad» -> error junto a la cantidad de la segunda línea
    mostrarError: (clave, mensaje) => {
      const [, indice, campo] = clave.split(".");
      const control = indice != null ? cuerpo.querySelectorAll("tr")[indice]?.querySelector(`[data-campo=${campo}]`) : null;
      if (!control) { errorGeneral.textContent = mensaje; return; }
      control.setAttribute("aria-invalid", "true");
      control.nextElementSibling.textContent = mensaje;
    },
  };
}

// Ventana para el motivo de una factura rectificativa
function pedirMotivo(fila) {
  return new Promise((resolver) => {
    const motivo = h("input", { id: "motivo", name: "motivo", type: "text", maxlength: "255", required: true, placeholder: "Devolución, error en el precio…" });
    const dialogo = h("dialog", { class: "modal", "aria-labelledby": "rectificar-titulo" },
      h("h2", { id: "rectificar-titulo" }, `Rectificar la factura ${fila.numero}`),
      h("p", {}, `Se emitirá una factura rectificativa por ${formato.moneda(-fila.total)} que anula la original. La factura ${fila.numero} no se borra.`),
      h("form", { method: "dialog", class: "formulario formulario--una" },
        h("div", { class: "campo" }, h("label", { for: "motivo" }, "Motivo"), motivo),
        h("div", { class: "modal-pie" },
          h("button", { class: "boton", value: "no", formnovalidate: true }, "Cancelar"),
          h("button", { class: "boton boton--peligro", value: "si" }, "Emitir rectificativa"))));
    dialogo.addEventListener("close", () => {
      resolver(dialogo.returnValue === "si" ? motivo.value : null);
      dialogo.remove();
    });
    document.body.append(dialogo);
    dialogo.showModal();
  });
}

// ---------- Columnas y celdas ----------

function columnasDe(modulo) {
  const columnas = [{ clave: "id", etiqueta: "#", tipo: "id" }];
  const visibles = modulo.lista ?? Object.keys(modulo.campos);
  for (const campo of visibles) {
    if (campo === "lineas") {
      columnas.push({ clave: "lineas", etiqueta: modulo.lineas.etiqueta, tipo: "lineas", ordenable: false });
      continue;
    }
    const def = modulo.campos[campo];
    if (def.tipo === "clave") continue;
    // En las relaciones se muestra el nombre (cliente) en lugar del número (cliente_id)
    if (def.tipo === "relacion") columnas.push({ ...def, clave: campo.replace(/_id$/, ""), tipo: "texto" });
    else columnas.push({ ...def, clave: campo });
  }
  for (const [campo, def] of Object.entries(modulo.calculados ?? {})) {
    if (!def.oculto) columnas.push({ ...def, clave: campo });
  }
  return columnas.map((c) => ({ ...c, alinear: ["moneda", "entero"].includes(c.tipo) ? "numero" : "" }));
}

// «factura.html?id={id}» -> «factura.html?id=7»
function plantilla(texto, fila) {
  return texto.replace(/\{(\w+)\}/g, (_, campo) => encodeURIComponent(fila[campo] ?? ""));
}

// «2 × Portátil ProBook 15 y 1 más» (el detalle completo, en el title)
function resumenLineas(lineas) {
  if (!lineas?.length) return null;
  const nombre = (l) => l.producto ?? l.concepto;
  return h("span", { title: lineas.map((l) => `${l.cantidad} × ${nombre(l)}`).join("\n") },
    `${lineas[0].cantidad} × ${nombre(lineas[0])}`,
    lineas.length > 1 && h("span", { class: "mas" }, ` y ${lineas.length - 1} más`));
}

function celda(columna, fila, modulo, recurso) {
  const valor = columna.tipo === "lineas" ? resumenLineas(fila.lineas) : fila[columna.clave];
  const clases = [columna.alinear, columna.clave === modulo.principal ? "destacado" : ""];
  let contenido;

  if (valor == null || valor === "") {
    clases.push("vacio");
    contenido = "—";
  } else {
    switch (columna.tipo) {
      case "id":
        clases.push("id");
        contenido = `#${valor}`;
        break;
      case "moneda":
        contenido = formato.moneda(valor);
        if (valor < 0) clases.push("negativo");
        break;
      case "entero":
        contenido = columna.alerta != null && valor < columna.alerta
          ? insignia(`${formato.numero(valor)} · bajo`, "rojo literal")
          : formato.numero(valor);
        break;
      case "opcion":
        contenido = insignia(valor, columna.colores?.[valor]);
        break;
      case "fecha":
        contenido = h("time", { datetime: valor }, formato.fecha(valor));
        break;
      case "email":
        contenido = h("a", { href: `mailto:${valor}` }, valor);
        break;
      case "tel":
        contenido = h("a", { href: `tel:${String(valor).replace(/\s/g, "")}` }, valor);
        break;
      default:
        contenido = valor;
    }
    if (columna.enlace) {
      contenido = h("a", { href: plantilla(columna.enlace, fila), target: "_blank", rel: "noopener" }, contenido);
    } else if (modulo.ficha && columna.clave === modulo.principal) {
      contenido = h("a", { href: `#/${recurso}/${fila.id}`, class: "enlace-ficha" }, contenido);
    }
  }
  return h("td", { class: clases.filter(Boolean).join(" ") }, contenido);
}

// ---------- Controles del formulario ----------

function crearControl(campo, def, valor, opciones = [], editando = false) {
  const comunes = { id: `campo-${campo}`, name: campo, required: !!def.obligatorio && !(def.tipo === "clave" && editando) };

  if (def.tipo === "opcion") {
    return h("select", comunes, def.opciones.map((o) =>
      h("option", { value: o, selected: o === valor }, formato.capital(o))));
  }

  if (def.tipo === "relacion") {
    const [campoDetalle, sufijo] = def.detalle ?? [];
    return h("select", comunes,
      h("option", { value: "" }, `Selecciona ${def.etiqueta.toLowerCase()}…`),
      opciones.map((o) => h("option", { value: o.id, selected: o.id === valor },
        campoDetalle ? `${o[def.mostrar]} · ${o[campoDetalle]} ${sufijo}` : o[def.mostrar])));
  }

  if (def.tipo === "clave") {
    return h("input", {
      ...comunes, type: "password", autocomplete: "new-password", minlength: "8",
      placeholder: editando ? "Déjala vacía para no cambiarla" : "Mínimo 8 caracteres",
    });
  }

  const tipos = {
    email: { type: "email", autocomplete: "off" },
    tel: { type: "tel", autocomplete: "off" },
    moneda: { type: "number", step: "0.01", min: "0", inputmode: "decimal" },
    entero: { type: "number", step: "1", min: def.minimo ?? null, inputmode: "numeric" },
    fecha: { type: "date" },
  };
  return h("input", { ...comunes, ...(tipos[def.tipo] ?? { type: "text", maxlength: "255" }), value: valor ?? "" });
}
