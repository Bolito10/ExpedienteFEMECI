/* ==========================================================================
   Expedientes Clínicos — portal del paciente (solo lectura)
   Barra lateral, historial desplegable con filtros, gráfica de signos e impresión.
   No hace ninguna petición al servidor: todo llega ya en la página.
   ========================================================================== */

(() => {
  const raiz = document.documentElement;
  const PANTALLA_CHICA = window.matchMedia('(max-width: 900px)');

  /* Barra lateral: en celular y tableta flota sobre el contenido; en escritorio se oculta o se muestra */
  function alternarLateral() {
    if (PANTALLA_CHICA.matches) {
      raiz.classList.toggle('lateral-abierta');
      return;
    }
    const oculta = raiz.classList.toggle('lateral-oculta');
    try { localStorage.setItem('lateral-oculta', oculta ? '1' : '0'); } catch (e) { /* sin almacenamiento */ }
  }

  document.addEventListener('click', (ev) => {
    const accion = ev.target.closest('[data-accion]')?.dataset.accion;
    if (accion === 'alternar-lateral') alternarLateral();
    if (accion === 'cerrar-lateral') raiz.classList.remove('lateral-abierta');
    if (ev.target.closest('[data-imprimir]')) window.print();
  });
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') raiz.classList.remove('lateral-abierta');
  });
  PANTALLA_CHICA.addEventListener('change', () => raiz.classList.remove('lateral-abierta'));

  document.addEventListener('DOMContentLoaded', () => {
    /* Historial: cada registro se despliega para ver su detalle */
    document.querySelectorAll('.acordeon').forEach((lista) => {
      lista.addEventListener('click', (ev) => {
        const cabecera = ev.target.closest('.acordeon__cabecera');
        if (!cabecera) return;
        const abierto = cabecera.getAttribute('aria-expanded') === 'true';
        cabecera.setAttribute('aria-expanded', String(!abierto));
        cabecera.closest('.acordeon__item').classList.toggle('abierto', !abierto);
      });
    });

    /* Filtro por tipo de registro */
    document.querySelectorAll('[data-historial]').forEach((seccion) => {
      const filtros = seccion.querySelectorAll('[data-filtro]');
      filtros.forEach((b) => b.addEventListener('click', () => {
        filtros.forEach((x) => { x.classList.toggle('activo', x === b); x.setAttribute('aria-selected', String(x === b)); });
        seccion.querySelectorAll('.historial__evento').forEach((ev) => { ev.hidden = !!b.dataset.filtro && ev.dataset.tipo !== b.dataset.filtro; });
      }));
    });

    /* Evolución de signos vitales (la misma gráfica del perfil) */
    const fuente = document.getElementById('grafica-signos');
    const caja = document.querySelector('[data-grafica="signos"]');
    if (fuente && caja && typeof Graficas !== 'undefined') {
      const d = JSON.parse(fuente.textContent);
      Graficas.montar(caja.parentElement, {
        tipo: 'lineas', titulo: 'Evolución por consulta',
        categorias: d.categorias.map((c) => c.etiqueta), series: d.series.map((s) => ({ nombre: s.nombre, valores: s.valores })),
      });
      caja.remove();
    }
  });
})();
