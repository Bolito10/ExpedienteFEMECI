/* ==========================================================================
   Configuración (configuracion/index.php): buscador que filtra secciones y filas, y acceso a los ajustes
   del asistente de IA (viven en su panel). Los selects los convierte controles.js en menús propios.
   ========================================================================== */
(() => {
  const buscar = document.querySelector('[data-configuracion-buscar]');
  if (buscar) {
    const quitarAcentos = (t) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    buscar.addEventListener('input', () => {
      const q = quitarAcentos(buscar.value.trim());
      document.querySelectorAll('.configuracion__enlace').forEach((a) => { a.hidden = q !== '' && !quitarAcentos(a.textContent).includes(q); });
      document.querySelectorAll('[data-ajuste]').forEach((f) => { f.hidden = q !== '' && !quitarAcentos(f.textContent).includes(q); });
      document.querySelectorAll('.configuracion__grupo').forEach((g) => {
        let s = g.nextElementSibling;
        let visible = false;
        while (s && !s.classList.contains('configuracion__grupo')) { if (!s.hidden) visible = true; s = s.nextElementSibling; }
        g.hidden = !visible;
      });
    });
  }
  // Ajustes del asistente y de sincronización: abre el panel del asistente directamente en sus ajustes
  document.querySelectorAll('[data-abrir-ajustes-ia]').forEach((b) => b.addEventListener('click', () => {
    document.querySelector('[data-asistente-abrir]')?.click();
    setTimeout(() => document.querySelector('[data-asistente-ajustes]')?.click(), 350);
  }));
  // Aura de citas: encender o apagar en este navegador
  const aura = document.querySelector('[data-aura-alternar]');
  if (aura) {
    const pintarAura = () => aura.setAttribute('aria-checked', String(!window.AuraCitas?.apagada()));
    pintarAura();
    aura.addEventListener('click', () => { window.AuraCitas?.fijar(!window.AuraCitas.apagada()); pintarAura(); });
  }
  // Botones que borran algo: confirmación con el diálogo de la app antes de enviar el formulario
  document.querySelectorAll('.configuracion [data-confirmar]').forEach((b) => b.addEventListener('click', async (ev) => {
    if (b.dataset.confirmado) return;
    ev.preventDefault();
    if (typeof confirmar !== 'function') return;
    if (await confirmar(b, { titulo: b.dataset.confirmar, texto: 'No se puede deshacer.', boton: b.textContent.trim() })) {
      b.dataset.confirmado = '1';
      b.click();
    }
  }));
})();
