/* ==========================================================================
   Ancho libre de imágenes y gráficas en las notas: se arrastra cualquiera de los dos bordes (como en Notion)
   y se guarda como porcentaje del ancho de la nota (10–100). La IA usa el mismo valor (ajustar_imagen).
   Doble clic en un borde vuelve al ancho completo.
   ========================================================================== */
(() => {
  if (typeof Editor === 'undefined') return;
  const VISUALES = ['imagen', 'grafica'];

  const crearOriginal = Editor.crearBloque;
  Editor.crearBloque = function (b) {
    const el = crearOriginal.call(this, b);
    if (b && VISUALES.includes(b.t)) {
      const fig = el.querySelector('.bloque__imagen, .bloque__grafica');
      if (Number(b.ancho) >= 10 && Number(b.ancho) < 100) {
        el.dataset.ancho = String(Math.round(b.ancho));
        delete el.dataset.tam;
        fig?.style.setProperty('--ancho', `${el.dataset.ancho}%`);
      }
      if (this.editable && fig) {
        fig.insertAdjacentHTML('beforeend', '<span class="ajuste-ancho ajuste-ancho--izq" contenteditable="false" data-ajuste-ancho="izq" title="Arrastra para cambiar el ancho · doble clic: ancho completo"></span>'
          + '<span class="ajuste-ancho ajuste-ancho--der" contenteditable="false" data-ajuste-ancho="der" title="Arrastra para cambiar el ancho · doble clic: ancho completo"></span>'
          + '<span class="ajuste-ancho__etiqueta" contenteditable="false" aria-hidden="true"></span>');
      }
    }
    return el;
  };
  const datosOriginal = Editor.datosDe;
  Editor.datosDe = function (b) {
    const d = datosOriginal.call(this, b);
    if (VISUALES.includes(b.dataset.t) && b.dataset.ancho) { d.ancho = Number(b.dataset.ancho); delete d.tam; }
    return d;
  };

  const fijar = (bloque, ancho) => {
    const fig = bloque.querySelector('.bloque__imagen, .bloque__grafica');
    if (ancho >= 100) { delete bloque.dataset.ancho; fig.style.removeProperty('--ancho'); } else { bloque.dataset.ancho = String(ancho); fig.style.setProperty('--ancho', `${ancho}%`); }
    delete bloque.dataset.tam;
  };

  document.addEventListener('pointerdown', (ev) => {
    const asa = ev.target.closest('[data-ajuste-ancho]');
    if (!asa || ev.button !== 0 || !Editor.editable) return;
    ev.preventDefault();
    ev.stopPropagation();
    const bloque = asa.closest('.bloque');
    const fig = asa.parentElement;
    const total = Editor.raiz.getBoundingClientRect().width;
    const inicio = fig.getBoundingClientRect();
    const x0 = ev.clientX;
    const lado = asa.dataset.ajusteAncho;
    const etiqueta = fig.querySelector('.ajuste-ancho__etiqueta');
    fig.classList.add('ajustando-ancho');
    asa.setPointerCapture(ev.pointerId);
    let ancho = Number(bloque.dataset.ancho) || 100;
    const mover = (e) => {
      // El bloque está centrado: mover un borde cambia el ancho el doble
      const delta = (lado === 'der' ? e.clientX - x0 : x0 - e.clientX) * 2;
      ancho = Math.round(Math.max(10, Math.min(100, ((inicio.width + delta) / total) * 100)));
      fijar(bloque, ancho);
      if (etiqueta) etiqueta.textContent = `${ancho} %`;
    };
    const soltar = () => {
      asa.removeEventListener('pointermove', mover);
      fig.classList.remove('ajustando-ancho');
      Editor.programarGuardado();
    };
    asa.addEventListener('pointermove', mover);
    asa.addEventListener('pointerup', soltar, { once: true });
    asa.addEventListener('pointercancel', soltar, { once: true });
  });
  document.addEventListener('dblclick', (ev) => {
    const asa = ev.target.closest('[data-ajuste-ancho]');
    if (!asa || !Editor.editable) return;
    fijar(asa.closest('.bloque'), 100);
    Editor.programarGuardado();
  });
})();
