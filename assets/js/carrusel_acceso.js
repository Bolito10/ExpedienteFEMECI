/* Panel de acceso: imágenes y video pasan con un fundido suave cada 7 s (el video dura lo que dura).
   Cada diapositiva se carga hasta que le toca (data-src), así la pantalla de acceso abre rápido.
   Con "reducir movimiento" solo se muestra la primera. */
(() => {
  const caja = document.querySelector('[data-carrusel]');
  if (!caja) return;
  const diapositivas = [...caja.querySelectorAll('.acceso__diapositiva')];
  if (diapositivas.length < 2 || matchMedia('(prefers-reduced-motion: reduce)').matches || matchMedia('(max-width: 760px)').matches) return;
  let actual = 0;
  let temporizador = null;
  const cargar = (el) => { if (el.dataset.src) { el.src = el.dataset.src; delete el.dataset.src; } };
  cargar(diapositivas[1]);   // la siguiente ya está lista cuando toque

  const mostrar = (i) => {
    const anterior = diapositivas[actual];
    const siguiente = diapositivas[i];
    cargar(siguiente);
    cargar(diapositivas[(i + 1) % diapositivas.length]);
    siguiente.classList.add('activa');
    anterior.classList.remove('activa');
    if (anterior.tagName === 'VIDEO') setTimeout(() => anterior.pause(), 1400);
    actual = i;
    if (siguiente.tagName === 'VIDEO') {
      siguiente.currentTime = 0;
      siguiente.play().catch(() => {});
      // El video se queda lo que dura (mínimo 7 s)
      const duracion = Number.isFinite(siguiente.duration) && siguiente.duration > 0 ? siguiente.duration * 1000 : 8000;
      programar(Math.max(7000, duracion));
    } else {
      programar(7000);
    }
  };
  const programar = (ms) => { clearTimeout(temporizador); temporizador = setTimeout(() => mostrar((actual + 1) % diapositivas.length), ms); };
  // En otra pestaña no avanza; al volver sigue donde iba
  document.addEventListener('visibilitychange', () => (document.hidden ? clearTimeout(temporizador) : programar(7000)));
  programar(7000);
})();
