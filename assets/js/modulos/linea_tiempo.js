/* ==========================================================================
   Historial del paciente como línea de tiempo (pacientes/ver.php): búsqueda dentro del historial,
   filtro por tipo (los botones de siempre), salto a un año y «Expandir todo». Los meses sin eventos
   visibles se ocultan para no perderse.
   ========================================================================== */
(() => {
  const seccion = document.querySelector('[data-historial]');
  const lista = seccion?.querySelector('.linea-tiempo');
  if (!lista) return;
  const buscar = seccion.querySelector('[data-historial-buscar]');
  const vacio = seccion.querySelector('[data-historial-vacio]');
  const sinAcentos = (t) => String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const eventos = [...lista.querySelectorAll('.linea-tiempo__evento')];
  eventos.forEach((e) => { e.dataset.texto = sinAcentos(e.textContent); });

  function aplicar() {
    const tipo = seccion.querySelector('[data-filtro].activo')?.dataset.filtro || '';
    const q = sinAcentos(buscar?.value.trim() || '');
    let visibles = 0;
    eventos.forEach((e) => {
      const ver = (!tipo || e.dataset.tipo === tipo) && (!q || e.dataset.texto.includes(q));
      e.hidden = !ver;
      if (ver) visibles++;
    });
    lista.querySelectorAll('.linea-tiempo__mes').forEach((m) => {
      m.hidden = !eventos.some((e) => !e.hidden && e.dataset.mes === m.dataset.mes);
    });
    if (vacio) vacio.hidden = visibles > 0;
  }
  // Después del filtro original (app.js), recalcula meses y búsqueda
  seccion.querySelectorAll('[data-filtro]').forEach((b) => b.addEventListener('click', () => setTimeout(aplicar)));
  let t = null;
  buscar?.addEventListener('input', () => { clearTimeout(t); t = setTimeout(aplicar, 120); });
  buscar?.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') { buscar.value = ''; aplicar(); } });

  seccion.addEventListener('click', (ev) => {
    const anio = ev.target.closest('[data-ir-anio]');
    if (anio) {
      lista.querySelector(`.linea-tiempo__mes[data-anio="${anio.dataset.irAnio}"]:not([hidden])`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    const todo = ev.target.closest('[data-historial-todo]');
    if (todo) {
      const abrir = todo.getAttribute('aria-pressed') !== 'true';
      eventos.filter((e) => !e.hidden).forEach((e) => {
        const cab = e.querySelector('.acordeon__cabecera');
        if ((cab.getAttribute('aria-expanded') === 'true') !== abrir) cab.click();
      });
      todo.setAttribute('aria-pressed', String(abrir));
      todo.querySelector('span').textContent = abrir ? 'Contraer todo' : 'Expandir todo';
    }
  });
})();
