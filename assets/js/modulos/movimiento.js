/* ==========================================================================
   Movimiento de la app (assets/css/modulos/movimiento.css): barra de carga al navegar, entradas
   escalonadas de listas, aparición de secciones al desplazarse y números que cuentan hasta su valor.
   No hace nada si el sistema pide «reducir movimiento».
   ========================================================================== */
(() => {
  const quieto = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Barra de carga: al seguir un enlace interno o enviar un formulario que cambia de página
  const barra = document.createElement('div');
  barra.className = 'barra-carga';
  document.body.appendChild(barra);
  const cargar = () => { barra.classList.remove('barra-carga--activa'); void barra.offsetWidth; barra.classList.add('barra-carga--activa'); };
  document.addEventListener('click', (ev) => {
    const a = ev.target.closest('a[href]');
    if (!a || ev.defaultPrevented || ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || a.target === '_blank' || a.hasAttribute('download')) return;
    const url = new URL(a.href, location.href);
    if (url.origin !== location.origin || (url.pathname === location.pathname && url.search === location.search)) return;
    cargar();
  });
  document.addEventListener('submit', (ev) => { if (!ev.defaultPrevented && !ev.target.closest('[data-asistente-form]')) setTimeout(() => { if (!ev.defaultPrevented) cargar(); }); });
  // Al volver con «atrás» la página sale de la caché: la barra no debe quedarse a medias
  window.addEventListener('pageshow', () => barra.classList.remove('barra-carga--activa'));

  if (quieto) return;

  // Entradas escalonadas: los primeros elementos de cada lista llegan uno tras otro
  const LISTAS = ['.bd-tabla tbody', '.historial', '.lista-clinica', '.hoja-paciente__resumen', '.configuracion__contenido', '.portal-lista'];
  document.querySelectorAll(LISTAS.join(',')).forEach((lista) => {
    [...lista.children].slice(0, 24).forEach((el, i) => {
      if (el.matches('script, template, [hidden]')) return;
      el.style.setProperty('--i', i);
      el.setAttribute('data-entrada', '');
      el.addEventListener('animationend', () => el.removeAttribute('data-entrada'), { once: true });
    });
  });

  // Secciones fuera de la vista: aparecen al llegar a ellas
  if ('IntersectionObserver' in window) {
    const alto = window.innerHeight;
    const observador = new IntersectionObserver((entradas) => entradas.forEach((e) => {
      if (!e.isIntersecting) return;
      e.target.classList.add('revelado');
      observador.unobserve(e.target);
      // Al terminar se quita la clase sin que su animación de entrada original se repita
      setTimeout(() => { e.target.style.animation = 'none'; e.target.classList.remove('revelar', 'revelado'); }, 700);
    }), { rootMargin: '0px 0px -8% 0px' });
    document.querySelectorAll('.ficha__seccion, .grafica, .notas-seccion, .tarjeta-panel').forEach((el) => {
      if (el.getBoundingClientRect().top < alto * .92) return;   // lo que ya se ve entra con su propia animación
      el.classList.add('revelar');
      observador.observe(el);
    });
  }

  // Números que cuentan: valores enteros de los resúmenes suben desde 0 al aparecer
  const contar = (el) => {
    const final = Number(el.textContent.replace(/[^\d]/g, ''));
    if (!final || final > 100000) return;
    const formato = new Intl.NumberFormat('es-MX');
    const inicio = performance.now();
    const duracion = Math.min(1100, 420 + final * 12);
    el.dataset.contando = '';
    const paso = (t) => {
      const p = Math.min(1, (t - inicio) / duracion);
      el.textContent = formato.format(Math.round(final * (1 - Math.pow(1 - p, 3))));
      if (p < 1) requestAnimationFrame(paso); else delete el.dataset.contando;
    };
    requestAnimationFrame(paso);
  };
  document.querySelectorAll('.hoja-paciente__resumen dd, [data-contar]').forEach((el) => {
    if (/^\s*[\d.,]+\s*$/.test(el.textContent)) contar(el);
  });
})();
