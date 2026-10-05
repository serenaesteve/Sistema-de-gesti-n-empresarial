// Carga los componentes HTML indicados con data-include="ruta.html".
// Mejoras respecto a la versión de clase: carga en paralelo, admite includes anidados,
// devuelve una promesa (la app espera a que todo esté cargado) y muestra el error en pantalla.
// Los componentes son HTML puro: la lógica vive en módulos JS, no en <script> dentro del HTML.

export async function incluir(raiz = document) {
  const elementos = [...raiz.querySelectorAll("[data-include]")];

  await Promise.all(elementos.map(async (elemento) => {
    const archivo = elemento.dataset.include;
    elemento.removeAttribute("data-include");

    try {
      const respuesta = await fetch(archivo);
      if (!respuesta.ok) throw new Error(`HTTP ${respuesta.status}`);
      elemento.innerHTML = await respuesta.text();
      await incluir(elemento);
    } catch (error) {
      console.error("No se puede cargar:", archivo, error);
      elemento.textContent = `No se pudo cargar ${archivo}`;
      elemento.classList.add("error-include");
    }
  }));
}
