// Panel de inicio: indicadores, ventas por mes y por producto, últimos pedidos y stock bajo.
// Solo muestra lo de los módulos activos: con solo el CRM, el resumen es de clientes.

import { api } from "../nucleo/js/api.js";
import { h, icono, formato, insignia, cabeceraPagina } from "../nucleo/js/dom.js";

const meses = new Intl.DateTimeFormat("es-ES", { month: "short" });
const mesesLargos = new Intl.DateTimeFormat("es-ES", { month: "long", year: "numeric" });

const PERIODOS = [["mes", "Este mes"], ["90d", "90 días"], ["ano", "Este año"], ["todo", "Todo"]];
let periodoElegido = "todo";

export async function vista(contexto) {
  const { esquema, puede } = contexto;
  const datos = await api("panel", { parametros: { periodo: periodoElegido } });
  const i = datos.indicadores;
  const activo = (m) => datos.modulos.includes(m);
  const colores = esquema.pedidos.campos.estado.colores;
  const hoy = new Intl.DateTimeFormat("es-ES", { weekday: "long", day: "numeric", month: "long" }).format(new Date());

  const indicadores = [
    activo("ventas") && { titulo: "Ventas", valor: formato.moneda(i.facturacion), nota: `${i.pedidos_periodo} pedidos · sin IVA`, enlace: "#/pedidos" },
    activo("ventas") && { titulo: "Pedidos pendientes", valor: i.pedidos_pendientes, nota: "Pendientes de envío", enlace: "#/pedidos" },
    activo("facturacion") && { titulo: "Por facturar", valor: i.sin_facturar, nota: "Pedidos entregados sin factura", enlace: "#/pedidos", aviso: i.sin_facturar > 0 },
    activo("compras") && { titulo: "Compras en curso", valor: i.compras_pendientes, nota: "Pedidas a proveedores, sin recibir", enlace: "#/compras" },
    activo("crm") && { titulo: "Clientes activos", valor: i.clientes_activos, nota: `y ${i.clientes_potencial} potenciales`, enlace: "#/clientes" },
  ].filter(Boolean).slice(0, 4);

  // Selector de periodo: afecta a «Ventas» y a «Ventas por producto»
  const selector = h("div", { class: "segmentado", role: "group", "aria-label": "Periodo" },
    PERIODOS.map(([valor, texto]) => h("button", {
      type: "button", "aria-pressed": String(valor === datos.periodo),
      onclick: async (e) => {
        periodoElegido = valor;
        e.currentTarget.closest(".vista").replaceWith(await vista(contexto));
      },
    }, texto)));

  return h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: formato.capital(hoy),
      titulo: "Resumen",
      descripcion: activo("ventas")
        ? "Ventas, pedidos pendientes de gestión y productos por debajo del stock mínimo."
        : "Resumen de los módulos instalados.",
      acciones: [activo("ventas") && selector],
    }),

    // Avisos: lo que pide atención hoy (stock que se agota, tareas que fallan, incidencias, datos mal…)
    datos.alertas?.length > 0 && h("div", { class: "tarjeta vidrio avisos-panel" },
      h("h2", {}, "Avisos", h("small", {}, `${datos.alertas.length}`)),
      h("ul", { class: "recomendaciones" }, datos.alertas.map((a) => h("li", { class: { error: "error", aviso: "aviso", info: "accion" }[a.tipo] },
        h("span", { class: "icono", "aria-hidden": "true" }, icono(a.icono)),
        h("span", { class: "texto-aviso" }, a.texto),
        h("a", { class: "boton boton--pequeno", href: a.enlace }, "Ver"))))),

    h("div", { class: "rejilla rejilla--indicadores" },
      indicadores.map((d) =>
        h("a", { class: `tarjeta vidrio indicador ${d.aviso ? "alerta" : ""}`, href: d.enlace },
          h("span", { class: "titulo" }, d.titulo),
          h("span", { class: "valor" }, d.valor),
          h("span", { class: "nota" }, d.aviso && icono("alerta"), d.nota),
        ))),

    h("div", { class: "rejilla rejilla--panel" },
      activo("ventas") && tarjetaMeses(datos.ventas_mes),
      activo("ventas") && tarjetaVentas(datos.ventas_producto),
      activo("ventas") && tarjetaPedidos(datos.ultimos_pedidos, colores),
      activo("crm") && !activo("ventas") && tarjetaClientes(datos.clientes_recientes, esquema.clientes.campos.estado.colores),
      activo("inventario") && tarjetaStock(datos.stock_bajo, datos.alerta_stock, puede("crear", "compras")),
    ),
  );
}

// Columnas verticales, una sola serie: un color, sin leyenda (el título dice qué se mide).
// Cada columna muestra su importe al pasar el ratón o con el foco; hay una tabla oculta para lectores de pantalla.
function tarjetaMeses(serie) {
  const maximo = Math.max(...serie.map((m) => m.total), 1);
  const mayor = serie.reduce((a, b) => (b.total > a.total ? b : a), serie[0]);
  const fechaDe = (mes) => new Date(`${mes}-01T00:00:00`);

  return h("div", { class: "tarjeta vidrio" },
    h("h2", {}, "Ventas por mes", h("small", {}, "Últimos 6 meses")),
    h("div", { class: "columnas", "aria-hidden": "true" },
      serie.map((m, indice) => {
        const actual = indice === serie.length - 1;
        return h("div", { class: `columna ${m === mayor ? "columna--mayor" : ""}`, tabindex: "0",
                          "data-tip": `${formato.capital(mesesLargos.format(fechaDe(m.mes)))}: ${formato.moneda(m.total)} · ${m.pedidos} pedidos${actual ? " (en curso)" : ""}` },
          h("span", { class: "columna-pista", style: { "--alto": `${(m.total / maximo) * 100}%` } },
            (m === mayor || actual) && h("span", { class: "columna-valor" }, formato.moneda(Math.round(m.total)).replace(/,00/, "")),
            h("span", { class: "columna-relleno" })),
          h("span", { class: "columna-mes" }, meses.format(fechaDe(m.mes)).replace(".", "") + (actual ? "*" : "")),
        );
      })),
    h("p", { class: "nota-grafico" }, "* Mes en curso. Importes sin IVA de pedidos no cancelados."),
    h("table", { class: "oculto" },
      h("caption", {}, "Ventas por mes"),
      h("thead", {}, h("tr", {}, h("th", {}, "Mes"), h("th", {}, "Ventas"), h("th", {}, "Pedidos"))),
      h("tbody", {}, serie.map((m) => h("tr", {},
        h("td", {}, mesesLargos.format(fechaDe(m.mes))), h("td", {}, formato.moneda(m.total)), h("td", {}, m.pedidos))))),
  );
}

function tarjetaVentas(ventas) {
  const maximo = Math.max(...ventas.map((v) => v.total), 1);
  return h("div", { class: "tarjeta vidrio" },
    h("h2", {}, "Ventas por producto", h("small", {}, "5 principales")),
    ventas.length
      ? h("ul", { class: "barras" }, ventas.map((v) => {
          const ancho = `${(v.total / maximo) * 100}%`;
          return h("li", { class: "barra" },
            h("span", { class: "nombre" }, v.nombre),
            h("span", { class: "importe" }, formato.moneda(v.total)),
            h("span", { class: "pista", style: { "--ancho": ancho }, "aria-hidden": "true" },
              h("span", { class: "relleno" })),
          );
        }))
      : h("p", { class: "nota" }, "Todavía no hay ventas."),
  );
}

function tarjetaPedidos(pedidos, colores) {
  return h("div", { class: "tarjeta vidrio" },
    h("h2", {}, "Últimos pedidos", h("a", { href: "#/pedidos" }, "Ver todos")),
    h("ul", { class: "lista" }, pedidos.map((p) =>
      h("li", {},
        h("div", { class: "principal" },
          h("strong", {}, `#${p.id} · ${p.cliente}`),
          h("small", {}, `${p.productos} ${p.productos === 1 ? "producto" : "productos"} · ${formato.fecha(p.fecha)}`)),
        insignia(p.estado, colores[p.estado]),
        h("span", { class: "cifra" }, formato.moneda(p.total)),
      ))),
  );
}

// Cada producto por debajo del mínimo, con lo ya pedido al proveedor y un acceso para reponerlo
function tarjetaStock(productos, alerta, puedeComprar) {
  return h("div", { class: "tarjeta vidrio" },
    h("h2", {}, "Stock bajo", h("a", { href: "#/productos" }, "Ver productos")),
    productos.length
      ? h("ul", { class: "lista" }, productos.map((p) => {
          const sugerido = Math.max(alerta * 2 - p.stock - p.en_camino, 1);
          return h("li", {},
            h("div", { class: "principal" },
              h("strong", {}, p.nombre),
              h("small", {}, p.en_camino ? `${p.en_camino} uds. ya pedidas al proveedor` : `Mínimo recomendado: ${alerta} uds.`)),
            insignia(`${p.stock} uds.`, `${p.stock < 10 ? "rojo" : "ambar"} literal`),
            puedeComprar && h("a", { class: "boton boton--pequeno", href: `#/compras?reponer=${p.id}&cantidad=${sugerido}`, title: `Pedir ${sugerido} uds. al proveedor` }, "Reponer"),
          );
        }))
      : h("p", { class: "nota" }, "Ningún producto por debajo del stock mínimo."),
  );
}

// Con solo el CRM: los últimos clientes dados de alta
function tarjetaClientes(clientes, colores) {
  return h("div", { class: "tarjeta vidrio" },
    h("h2", {}, "Últimos clientes", h("a", { href: "#/clientes" }, "Ver todos")),
    clientes.length
      ? h("ul", { class: "lista" }, clientes.map((c) =>
          h("li", {},
            h("div", { class: "principal" },
              h("strong", {}, h("a", { href: `#/clientes/${c.id}` }, c.nombre)),
              h("small", {}, [c.empresa, c.ciudad].filter(Boolean).join(" · ") || "—")),
            insignia(c.estado, colores[c.estado]),
          )))
      : h("p", { class: "nota" }, "Todavía no hay clientes."),
  );
}
