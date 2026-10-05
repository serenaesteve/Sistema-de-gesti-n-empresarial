// Ficha de un cliente (#/clientes/3): datos, indicadores e historial de pedidos y facturas.

import { api } from "../nucleo/js/api.js";
import { h, icono, formato, insignia, cabeceraPagina, estadoVacio } from "../nucleo/js/dom.js";

export async function vista({ id, esquema }) {
  const [cliente, pedidos] = await Promise.all([
    api("clientes", { id }),
    api("pedidos", { parametros: { cliente_id: id, orden: "fecha", dir: "desc" } }),
  ]);

  const coloresCliente = esquema.clientes.campos.estado.colores;
  const coloresPedido = esquema.pedidos.campos.estado.colores;
  const validos = pedidos.filter((p) => p.estado !== "cancelado");
  const gastado = validos.reduce((suma, p) => suma + p.total, 0);
  const facturas = pedidos.filter((p) => p.factura).length;

  const indicadores = [
    { titulo: "Total comprado", valor: formato.moneda(gastado), nota: "Sin IVA, pedidos no cancelados" },
    { titulo: "Pedidos", valor: pedidos.length, nota: `${facturas} facturados` },
    { titulo: "Ticket medio", valor: validos.length ? formato.moneda(gastado / validos.length) : "—", nota: "Importe medio por pedido" },
    { titulo: "Último pedido", valor: pedidos[0] ? formato.fecha(pedidos[0].fecha) : "—", nota: pedidos[0] ? `Pedido #${pedidos[0].id}` : "Todavía no ha comprado", pequeno: true },
  ];

  const datos = [
    ["Empresa", cliente.empresa],
    ["NIF / CIF", cliente.nif],
    ["Email", cliente.email && h("a", { href: `mailto:${cliente.email}` }, cliente.email)],
    ["Teléfono", cliente.telefono && h("a", { href: `tel:${cliente.telefono.replace(/\s/g, "")}` }, cliente.telefono)],
    ["Dirección", [cliente.direccion, cliente.ciudad].filter(Boolean).join(", ")],
    ["Estado", insignia(cliente.estado, coloresCliente[cliente.estado])],
    ["Cliente desde", formato.fecha(cliente.creado.slice(0, 10))],
  ];

  return h("section", { class: "vista" },
    cabeceraPagina({
      antetitulo: "Clientes · Ficha",
      titulo: cliente.nombre,
      descripcion: cliente.empresa ? `${cliente.empresa}${cliente.ciudad ? ` · ${cliente.ciudad}` : ""}` : null,
      acciones: [h("a", { class: "boton", href: "#/clientes" }, icono("volver"), "Volver a clientes")],
    }),

    h("div", { class: "rejilla rejilla--indicadores" },
      indicadores.map((d) =>
        h("div", { class: "tarjeta vidrio indicador" },
          h("span", { class: "titulo" }, d.titulo),
          h("span", { class: `valor ${d.pequeno ? "valor--pequeno" : ""}` }, d.valor),
          h("span", { class: "nota" }, d.nota),
        ))),

    h("div", { class: "rejilla rejilla--ficha" },
      h("div", { class: "tarjeta vidrio" },
        h("h2", {}, "Datos"),
        h("dl", { class: "datos" }, datos.map(([etiqueta, valor]) => [
          h("dt", {}, etiqueta),
          h("dd", {}, valor || h("span", { class: "vacio" }, "—")),
        ])),
      ),
      h("div", { class: "tarjeta vidrio" },
        h("h2", {}, "Historial de pedidos", h("small", {}, `${pedidos.length} en total`)),
        pedidos.length
          ? h("ul", { class: "lista" }, pedidos.map((p) =>
              h("li", {},
                h("div", { class: "principal" },
                  h("strong", {}, `Pedido #${p.id} · ${formato.fecha(p.fecha)}`),
                  h("small", {}, p.lineas.map((l) => `${l.cantidad} × ${l.producto}`).join(", "),
                    p.factura && [" · ", h("a", { href: `factura.html?id=${p.factura_id}`, target: "_blank", rel: "noopener" }, p.factura)])),
                insignia(p.estado, coloresPedido[p.estado]),
                h("span", { class: "cifra" }, formato.moneda(p.total)),
              )))
          : estadoVacio("pedidos", "Sin pedidos", "Este cliente todavía no ha hecho ningún pedido."),
      ),
    ),
  );
}
