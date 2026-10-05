// Cliente de la API: una sola función para todas las peticiones.

const BASE = "api/api.php";

export class ErrorApi extends Error {
  constructor(mensaje, estado, errores = {}) {
    super(mensaje);
    this.estado = estado;
    this.errores = errores;
  }
}

// URL de la API con sus parámetros (también se usa para los enlaces de descarga CSV).
export function urlApi(recurso, parametros = {}) {
  const url = new URL(BASE, location.href);
  url.searchParams.set("recurso", recurso);
  for (const [clave, valor] of Object.entries(parametros)) {
    if (valor != null && valor !== "") url.searchParams.set(clave, valor);
  }
  return url;
}

export async function api(recurso, { metodo = "GET", id, cuerpo, parametros = {} } = {}) {
  let respuesta;
  try {
    respuesta = await fetch(urlApi(recurso, { ...parametros, id }), {
      method: metodo,
      // X-Blush: la API rechaza escrituras sin esta cabecera (protección CSRF)
      headers: metodo === "GET" ? {} : { "Content-Type": "application/json", "X-Blush": "1" },
      body: cuerpo ? JSON.stringify(cuerpo) : undefined,
      credentials: "same-origin",
    });
  } catch {
    throw new ErrorApi("No hay conexión con el servidor.", 0);
  }

  let json;
  try {
    json = await respuesta.json();
  } catch {
    throw new ErrorApi("El servidor no devolvió JSON. ¿Está PHP activo en Apache?", respuesta.status);
  }

  if (!json.ok) {
    // Sesión caducada: la aplicación vuelve a la pantalla de acceso
    if (respuesta.status === 401 && recurso !== "entrar") document.dispatchEvent(new CustomEvent("erp:sesion-caducada"));
    throw new ErrorApi(json.error || "Error desconocido", respuesta.status, json.errores || {});
  }
  return json.datos;
}
