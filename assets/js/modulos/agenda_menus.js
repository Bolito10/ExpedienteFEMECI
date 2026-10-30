/* ==========================================================================
   Agenda más tranquila: lo secundario va en submenús pequeños en la barra.
   - «Vista ▾»: Día, Semana, Mes, Lista (con su atajo).
   - Filtros (embudo, con punto si hay algo filtrado): profesionales, citas canceladas y atajos de teclado.
   El panel izquierdo queda solo con el mini calendario. Los controles originales siguen en la página
   (ocultos) y los menús los accionan, así agenda.js no cambia.
   ========================================================================== */
(() => {
  const raiz = document.querySelector('[data-agenda]');
  if (!raiz || typeof abrirMenu !== 'function') return;
  raiz.classList.add('calendario--sereno');
  const herramientas = raiz.querySelector('.calendario__herramientas');
  const nueva = raiz.querySelector('[data-agenda-nueva]');
  const icono = (n) => (typeof ico === 'function' ? ico(n) : '');
  const VISTAS = [['dia', 'Día', 'D'], ['semana', 'Semana', 'W'], ['mes', 'Mes', 'M'], ['lista', 'Lista', 'L']];

  const botonVista = document.createElement('button');
  botonVista.type = 'button';
  botonVista.className = 'cal-menu-boton';
  botonVista.setAttribute('aria-haspopup', 'menu');
  const botonFiltros = document.createElement('button');
  botonFiltros.type = 'button';
  botonFiltros.className = 'cal-menu-boton cal-menu-boton--icono';
  botonFiltros.setAttribute('aria-haspopup', 'menu');
  botonFiltros.title = 'Filtros y opciones';
  botonFiltros.setAttribute('aria-label', 'Filtros y opciones');
  herramientas.insertBefore(botonVista, nueva);
  herramientas.insertBefore(botonFiltros, nueva);

  const vistaActual = () => raiz.querySelector('.calendario__vistas [aria-pressed="true"]')?.dataset.vista || 'semana';
  const pintar = () => {
    const v = VISTAS.find(([k]) => k === vistaActual()) || VISTAS[1];
    const htmlVista = `<span>${v[1]}</span>${icono('flecha_abajo')}`;
    if (botonVista.innerHTML !== htmlVista) botonVista.innerHTML = htmlVista;
    botonVista.title = `Cambiar la vista (${VISTAS.map(([, t, a]) => `${t}: ${a}`).join(', ')})`;
    const filtrando = raiz.querySelector('[data-agenda-profesionales] [aria-checked="false"]') || raiz.querySelector('[data-ver-canceladas]')?.getAttribute('aria-checked') === 'true';
    // Solo se redibuja si cambió (si no, el observador se llamaría a sí mismo sin fin)
    const htmlFiltros = `${icono('filtro')}${filtrando ? '<i class="cal-menu-boton__punto" aria-hidden="true"></i>' : ''}`;
    if (botonFiltros.innerHTML !== htmlFiltros) botonFiltros.innerHTML = htmlFiltros;
  };
  pintar();
  const observador = new MutationObserver(pintar);
  observador.observe(raiz.querySelector('.calendario__vistas'), { subtree: true, attributes: true, attributeFilter: ['aria-pressed'] });
  observador.observe(raiz.querySelector('[data-agenda-rail]'), { subtree: true, attributes: true, attributeFilter: ['aria-checked'], childList: true });

  botonVista.addEventListener('click', () => abrirMenu(botonVista, [
    { titulo: 'Vista' },
    ...VISTAS.map(([k, t, a]) => ({ texto: t, detalle: a, marcado: vistaActual() === k, accion: () => raiz.querySelector(`.calendario__vistas [data-vista="${k}"]`)?.click() })),
  ], { alinear: 'derecha' }));

  botonFiltros.addEventListener('click', () => {
    const profesionales = [...raiz.querySelectorAll('[data-agenda-profesionales] [data-prof-alternar]')];
    const canceladas = raiz.querySelector('[data-ver-canceladas]');
    abrirMenu(botonFiltros, [
      { titulo: 'Profesionales' },
      ...profesionales.map((b) => ({ texto: b.querySelector('span')?.textContent || 'Profesional', detalle: b.querySelector('small')?.textContent || '',
        marcado: b.getAttribute('aria-checked') === 'true', accion: () => b.click() })),
      profesionales.length > 1 && { icono: 'usuarios', texto: 'Ver a todos', accion: () => raiz.querySelector('[data-profesionales-todos]')?.click() },
      'separador',
      canceladas && { texto: 'Mostrar citas canceladas', marcado: canceladas.getAttribute('aria-checked') === 'true', accion: () => canceladas.click() },
      'separador',
      { icono: 'info', texto: 'Atajos de teclado', detalle: '?', accion: () => raiz.querySelector('[data-agenda-atajos]')?.click() },
    ], { alinear: 'derecha' });
  });

  // Búsqueda compacta: solo la lupa hasta que se usa
  const buscar = raiz.querySelector('.cal-buscar');
  const entrada = buscar?.querySelector('input');
  const marcarBusqueda = () => buscar?.classList.toggle('cal-buscar--activa', !!entrada?.value);
  entrada?.addEventListener('input', marcarBusqueda);
  marcarBusqueda();
})();
