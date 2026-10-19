// Análisis (RA3, criterio h): información relevante para decidir, sacada de los datos del ERP.
// Recomendaciones en texto, tendencia del mes, concentración de clientes, clientes en riesgo,
// cobertura de stock con cantidad a reponer, márgenes y mejor día de la semana.

import { api } from "../nucleo/js/api.js";
import { h, icono, formato, insignia, cabeceraPagina, aviso, confirmar } from "../nucleo/js/dom.js";
import { graficoColumnas, graficoBarras } from "../nucleo/js/graficos.js";

const TIPOS = { accion: ["ejecutar", "Acción"], aviso: ["alerta", "Aviso"], bien: ["ok", "Bien"] };

export async function vista(contexto) {
  const d = await api("analisis");
  const puedeComprar = contexto.puede("crear", "compras");
  const porcentaje = (v) => (v == null ? "—" : `${v > 0 ? "+" : ""}${String(v).replace(".", ",")} %`);

  const raiz = h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: "Análisis",
      titulo: "Análisis del negocio",
      descripcion: "Qué está pasando y qué conviene hacer, calculado con los datos reales del ERP.",
      acciones: [h("button", { class: "boton", type: "button", onclick: async () => raiz.replaceWith(await vista(contexto)) }, icono("recargar"), "Actualizar")],
    }),

    h("div", { class: "tarjeta vidrio" },
      h("h2", {}, "Recomendaciones", h("small", {}, `Calculado el ${new Date(d.generado).toLocaleString("es-ES")}`)),
      h("ul", { class: "recomendaciones" }, d.recomendaciones.map((r) =>
        h("li", { class: r.tipo }, h("span", { class: "icono", role: "img", "aria-label": TIPOS[r.tipo][1] }, icono(TIPOS[r.tipo][0])), h("span", {}, r.texto))))),

    d.tendencia && h("div", { class: "rejilla rejilla--indicadores" },
      indicador("Ventas del mes", formato.moneda(d.tendencia.actual), `Día ${d.tendencia.dia} de ${d.tendencia.dias_mes} · sin IVA`),
      indicador("Frente al mes pasado", porcentaje(d.tendencia.variacion), `Mismo día: ${formato.moneda(d.tendencia.anterior_mismo_dia)}`, d.tendencia.variacion != null && d.tendencia.variacion < 0),
      indicador("Previsión a fin de mes", formato.moneda(d.tendencia.prevision), `Mes pasado completo: ${formato.moneda(d.tendencia.anterior_completo)}`),
      d.sin_facturar && indicador("Pendiente de facturar", formato.moneda(d.sin_facturar.importe), `${d.sin_facturar.pedidos} pedidos entregados`, d.sin_facturar.pedidos > 0, "#/pedidos"),
    ),

    (d.prevision || d.rfm) && h("div", { class: "rejilla rejilla--panel" },
      d.prevision && tarjetaPrevision(d.prevision),
      d.rfm && tarjetaRfm(d.rfm)),

    h("div", { class: "rejilla rejilla--panel" },
      d.pareto && h("div", { class: "tarjeta vidrio" },
        h("h2", {}, "Concentración de ventas", h("small", {}, "Últimos 12 meses")),
        d.pareto.clientes
          ? [h("p", { class: "dato-destacado" }, h("strong", {}, d.pareto.clientes_80), ` de ${d.pareto.clientes} clientes hacen el 80 % de las ventas (${formato.moneda(d.pareto.total)}).`),
             graficoBarras(d.pareto.top, { etiqueta: "nombre", valor: "importe", formatear: formato.moneda, titulo: "Clientes que más compran" })]
          : h("p", { class: "nota" }, "Todavía no hay ventas en los últimos 12 meses.")),

      d.dias_semana && h("div", { class: "tarjeta vidrio" },
        h("h2", {}, "Ventas por día de la semana", h("small", {}, "Últimos 12 meses")),
        graficoColumnas(d.dias_semana, { etiqueta: "dia", valor: "importe", formatear: formato.moneda, titulo: "Ventas por día de la semana", etiquetaCorta: (x) => x.slice(0, 3) })),

      d.riesgo && h("div", { class: "tarjeta vidrio" },
        h("h2", {}, "Clientes en riesgo", h("small", {}, "Llevan más de lo normal sin comprar")),
        d.riesgo.length
          ? h("ul", { class: "lista" }, d.riesgo.map((c) => h("li", {},
              h("div", { class: "principal" },
                h("strong", {}, h("a", { href: `#/clientes/${c.id}` }, c.nombre)),
                h("small", {}, `Compraba cada ${c.intervalo} días · último pedido ${formato.fecha(c.ultimo)}${c.telefono ? ` · ${c.telefono}` : ""}`)),
              insignia(`${c.dias} días`, c.dias > 3 * c.intervalo ? "rojo literal" : "ambar literal"),
              h("span", { class: "cifra" }, formato.moneda(c.importe)))))
          : h("p", { class: "nota" }, "Ningún cliente habitual ha dejado de comprar.")),

      d.cobertura && h("div", { class: "tarjeta vidrio" },
        h("h2", {}, "Cobertura de stock", h("small", {}, "Al ritmo de los últimos 90 días")),
        h("ul", { class: "lista" }, d.cobertura.filter((p) => p.dias !== null).slice(0, 8).map((p) => h("li", {},
          h("div", { class: "principal" },
            h("strong", {}, p.nombre),
            h("small", {}, `${p.stock} uds. · vende ${String(p.por_semana).replace(".", ",")} por semana${p.en_camino ? ` · ${p.en_camino} en camino` : ""}`)),
          insignia(`${p.dias} días`, `${p.dias < 15 ? "rojo" : p.dias < 45 ? "ambar" : "verde"} literal`),
          p.reponer > 0 && puedeComprar && h("a", { class: "boton boton--pequeno", href: `#/compras?reponer=${p.id}&cantidad=${p.reponer}`, title: `Pedir ${p.reponer} uds. (stock para 45 días)` }, `Pedir ${p.reponer}`))))),
    ),

    d.calidad && h("div", { class: "tarjeta vidrio tabla-contenedor" },
      h("h2", {}, "Calidad de los datos", h("small", {}, "Lo que conviene revisar al cargar datos reales")),
      h("table", {},
        h("thead", {}, h("tr", {}, ["Datos", "Registros", "NIF/CIF no válido", "Sin email ni teléfono", "Posibles duplicados"].map((t, i) => h("th", { class: i ? "numero" : "" }, t)))),
        h("tbody", {}, d.calidad.map((c) => h("tr", {},
          h("td", { class: "destacado" }, h("a", { href: `#/${c.recurso}` }, c.titulo)),
          h("td", { class: "numero" }, formato.numero(c.total)),
          h("td", { class: "numero", title: c.ejemplos_nif.map((e) => `${e.nombre}: ${e.nif}`).join("\n") || null }, c.nif_no_valido ? insignia(String(c.nif_no_valido), "rojo") : "0"),
          h("td", { class: "numero" }, c.sin_contacto ? insignia(String(c.sin_contacto), "ambar") : "0"),
          h("td", { class: "numero" }, c.duplicados
            ? [insignia(String(c.duplicados), "ambar"), c.puede_fusionar && h("button", { class: "boton boton--pequeno boton-en-celda", type: "button",
                onclick: () => revisarDuplicados(c, async () => raiz.replaceWith(await vista(contexto))) }, "Revisar")]
            : "0")))))),

    d.margenes && h("div", { class: "tarjeta vidrio tabla-contenedor" },
      h("h2", {}, "Margen por producto", h("small", {}, "Precio medio de venta (12 meses) frente al coste medio de compra")),
      d.margenes.length
        ? h("table", {},
            h("thead", {}, h("tr", {}, ["Producto", "Venta media", "Coste medio", "Margen", "Beneficio"].map((t, i) => h("th", { class: i ? "numero" : "" }, t)))),
            h("tbody", {}, d.margenes.map((m) => h("tr", {},
              h("td", { class: "destacado" }, m.nombre),
              h("td", { class: "numero" }, formato.moneda(m.venta)),
              h("td", { class: "numero" }, formato.moneda(m.coste)),
              h("td", { class: `numero ${m.margen < 15 ? "negativo" : ""}` }, `${String(m.margen).replace(".", ",")} %`),
              h("td", { class: "numero" }, formato.moneda(m.beneficio))))))
        : h("p", { class: "nota" }, "Hace falta haber recibido compras de los productos vendidos para calcular el margen.")),
  );
  return raiz;
}

function indicador(titulo, valor, nota, alerta = false, enlace = null) {
  return h(enlace ? "a" : "div", { class: `tarjeta vidrio indicador ${alerta ? "alerta" : ""}`, href: enlace },
    h("span", { class: "titulo" }, titulo),
    h("span", { class: "valor" }, valor),
    h("span", { class: "nota" }, alerta && icono("alerta"), nota));
}

const mesCorto = new Intl.DateTimeFormat("es-ES", { month: "short" });
const mesLargo = new Intl.DateTimeFormat("es-ES", { month: "long", year: "numeric" });

// 12 meses reales y 3 previstos (columnas rayadas); la tendencia en € por mes
function tarjetaPrevision(p) {
  const filas = [...p.historico, ...p.prevision].map((m) => ({ ...m, etiqueta: formato.capital(mesLargo.format(new Date(`${m.mes}-01T00:00:00`))) + (m.prevision ? " (previsión)" : "") }));
  const siguiente = p.prevision[1];
  return h("div", { class: "tarjeta vidrio" },
    h("h2", {}, "Previsión de ventas", h("small", {}, p.fiable ? "Tendencia de los 12 últimos meses" : "Orientativa: aún hay pocos datos")),
    h("p", { class: "dato-destacado" },
      h("strong", {}, formato.moneda(siguiente.total)), ` previstos para ${mesLargo.format(new Date(`${siguiente.mes}-01T00:00:00`))}`,
      p.tendencia != null && ` · ${p.tendencia >= 0 ? "+" : "−"}${formato.moneda(Math.abs(p.tendencia))} al mes`),
    graficoColumnas(filas, { etiqueta: "etiqueta", valor: "total", formatear: formato.moneda, titulo: "Ventas reales y previstas",
      etiquetaCorta: (e) => e.split(" ")[0].slice(0, 3), clase: (f) => (f.prevision ? "columna--prevision" : "") }),
    h("p", { class: "nota-grafico" }, `Columnas rayadas: previsión. Calculada con una línea de tendencia sobre ${p.meses_con_ventas} meses con ventas (ajuste R² = ${String(p.r2).replace(".", ",")}).`));
}

// Clientes por segmentos RFM: cuándo compraron por última vez, cada cuánto compran y cuánto gastan
function tarjetaRfm(segmentos) {
  const colores = { campeones: "verde", leales: "azul", nuevos: "rosa", riesgo: "rojo", dormidos: "gris", otros: "gris" };
  return h("div", { class: "tarjeta vidrio" },
    h("h2", {}, "Clientes por segmentos", h("small", {}, "Cuándo, cada cuánto y cuánto compran")),
    h("ul", { class: "segmentos" }, segmentos.filter((g) => g.clientes.length).map((g) => h("li", {},
      h("details", {},
        h("summary", {},
          insignia(`${g.clientes.length}`, `${colores[g.id]} literal`),
          h("span", { class: "principal" }, h("strong", {}, g.nombre), h("small", {}, g.descripcion)),
          h("span", { class: "cifra" }, formato.moneda(g.importe))),
        h("ul", { class: "lista" }, g.clientes.slice(0, 8).map((c) => h("li", {},
          h("div", { class: "principal" }, h("strong", {}, h("a", { href: `#/clientes/${c.id}` }, c.nombre)),
            h("small", {}, `${c.pedidos} ${c.pedidos === 1 ? "pedido" : "pedidos"} · último hace ${c.dias} días`)),
          h("span", { class: "cifra" }, formato.moneda(c.importe))))))))),
    !segmentos.some((g) => g.clientes.length) && h("p", { class: "nota" }, "Todavía no hay clientes con compras."));
}

// Revisar y fusionar duplicados: en cada grupo se elige cuál se queda; los demás se fusionan en él
async function revisarDuplicados(calidad, alTerminar) {
  const grupos = await api("intercambio", { parametros: { duplicados: calidad.recurso } });
  const singular = calidad.recurso === "clientes" ? "cliente" : "proveedor";
  const dialogo = h("dialog", { class: "modal modal--ancha", "aria-labelledby": "dup-titulo" },
    h("h2", { id: "dup-titulo" }, `${calidad.titulo} repetidos`),
    h("p", {}, `Elige en cada grupo qué ${singular} se queda. Los demás se fusionan en él: sus pedidos y compras pasan al que se queda y sus datos completan los que falten. No se puede deshacer.`),
    grupos.length ? grupos.map((grupo, n) => {
      const conservar = grupo.reduce((a, b) => (b.usos > a.usos ? b : a), grupo[0]).id;
      const nombre = `conservar-${n}`;
      const boton = h("button", { class: "boton boton--principal boton--pequeno", type: "button", onclick: async () => {
        const elegido = Number(dialogo.querySelector(`input[name="${nombre}"]:checked`).value);
        const otros = grupo.filter((m) => m.id !== elegido);
        if (!(await confirmar({ titulo: "Fusionar", boton: "Fusionar", mensaje: `${otros.map((o) => `«${o.nombre}»`).join(", ")} se fusionará en «${grupo.find((m) => m.id === elegido).nombre}».` }))) return;
        try {
          for (const o of otros) await api("intercambio", { metodo: "POST", parametros: { accion: "fusionar" }, cuerpo: { recurso: calidad.recurso, conservar: elegido, eliminar: o.id } });
          aviso("Fusionados");
          boton.closest(".grupo-duplicados").remove();
        } catch (error) { aviso(error.message, "error"); }
      } }, "Fusionar");
      return h("fieldset", { class: "grupo-duplicados" },
        h("legend", {}, `Grupo ${n + 1}`),
        h("table", {},
          h("thead", {}, h("tr", {}, ["Se queda", "Nombre", "NIF", "Email", "Teléfono", "Usado en"].map((t) => h("th", {}, t)))),
          h("tbody", {}, grupo.map((m) => h("tr", {},
            h("td", {}, h("input", { type: "radio", name: nombre, value: m.id, checked: m.id === conservar, "aria-label": `Conservar ${m.nombre}` })),
            h("td", { class: "destacado" }, m.nombre), h("td", {}, m.nif ?? "—"), h("td", {}, m.email ?? "—"), h("td", {}, m.telefono ?? "—"),
            h("td", {}, `${m.usos} ${m.usos === 1 ? "registro" : "registros"}`))))),
        h("div", { class: "acciones-formulario" }, boton));
    }) : h("p", { class: "nota" }, "Ya no quedan repetidos."),
    h("div", { class: "modal-pie" }, h("button", { class: "boton", type: "button", onclick: () => dialogo.close() }, "Cerrar")));
  dialogo.addEventListener("close", () => { dialogo.remove(); alTerminar(); });
  document.body.append(dialogo);
  dialogo.showModal();
}
