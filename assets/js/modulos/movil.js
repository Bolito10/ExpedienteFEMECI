/* ==========================================================================
   Expedientes Clínicos — comportamiento en pantallas táctiles y angostas
   - Barra lateral deslizable: se cierra con Esc, al deslizar a la izquierda,
     al elegir un enlace o al pasar a pantalla ancha (la abre el botón de
     la barra de pestañas, alternarLateral en app.js).
   - Toque largo = clic derecho: abre los mismos menús contextuales del árbol,
     las bases de datos, la lista de páginas y la barra superior (iOS no
     dispara "contextmenu"; en Android se evita abrirlos dos veces).
   - Tarjeta de acciones del paciente: se cierra al tocar fuera.
   ========================================================================== */
(() => {
  const raiz = document.documentElement;
  const angosta = window.matchMedia('(max-width: 900px)');

  /* ---------- Barra lateral deslizable ---------- */
  const cerrarLateral = () => raiz.classList.remove('lateral-abierta');
  const lateral = document.getElementById('barra-lateral');

  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && raiz.classList.contains('lateral-abierta') && !document.querySelector('.popover, .paleta')) cerrarLateral();
  });
  angosta.addEventListener('change', (ev) => { if (!ev.matches) cerrarLateral(); });

  if (lateral) {
    // Elegir un destino cierra el panel (la página nueva carga sin él abierto)
    lateral.addEventListener('click', (ev) => {
      if (!angosta.matches) return;
      const enlace = ev.target.closest('a[href]');
      if (enlace && !ev.target.closest('button') && !enlace.matches('[href="#"]')) cerrarLateral();
    });
    // Deslizar hacia la izquierda lo cierra
    let inicio = null;
    lateral.addEventListener('touchstart', (ev) => {
      if (!raiz.classList.contains('lateral-abierta') || ev.touches.length !== 1) { inicio = null; return; }
      inicio = { x: ev.touches[0].clientX, y: ev.touches[0].clientY };
    }, { passive: true });
    lateral.addEventListener('touchend', (ev) => {
      if (!inicio) return;
      const t = ev.changedTouches[0];
      const dx = t.clientX - inicio.x;
      const dy = Math.abs(t.clientY - inicio.y);
      inicio = null;
      if (dx < -60 && dy < 50) cerrarLateral();
    }, { passive: true });
  }

  /* ---------- Toque largo = menú contextual ---------- */
  // Solo donde la app tiene su propio menú de clic derecho (fuera de campos y texto editable)
  const ZONAS = [
    '.barra-lateral__desplazable .arbol__nodo',
    '[data-bdp] th[data-col]',
    '[data-bdp] tr[data-fila]',
    '[data-hoja-hijos] a',
    '.barra-superior',
    '[data-editor-bloques] .bloque__controles',
  ].join(',');
  const ESPERA = 520;      // ms
  const HOLGURA = 10;      // px que el dedo puede moverse sin cancelar

  let temporizador = null;
  let punto = null;
  let disparado = false;
  let suprimirNativoHasta = 0;

  const cancelar = () => { clearTimeout(temporizador); temporizador = null; };

  document.addEventListener('pointerdown', (ev) => {
    if (ev.pointerType !== 'touch' || !ev.isPrimary) return;
    disparado = false;
    const objetivo = ev.target.closest(ZONAS);
    if (!objetivo || ev.target.closest('input, textarea, select, [contenteditable="true"], [contenteditable="plaintext-only"], .popover')) return;
    punto = { x: ev.clientX, y: ev.clientY, el: ev.target };
    cancelar();
    temporizador = setTimeout(() => {
      temporizador = null;
      if (!punto?.el.isConnected) return;
      disparado = true;
      suprimirNativoHasta = Date.now() + 900;
      if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) { /* opcional */ } }
      punto.el.dispatchEvent(new MouseEvent('contextmenu', {
        bubbles: true, cancelable: true, view: window, button: 2, buttons: 0,
        clientX: punto.x, clientY: punto.y, screenX: punto.x, screenY: punto.y,
      }));
    }, ESPERA);
  }, { passive: true });

  document.addEventListener('pointermove', (ev) => {
    if (!temporizador || ev.pointerType !== 'touch') return;
    if (Math.abs(ev.clientX - punto.x) > HOLGURA || Math.abs(ev.clientY - punto.y) > HOLGURA) cancelar();
  }, { passive: true });
  document.addEventListener('pointercancel', cancelar, { passive: true });
  document.addEventListener('pointerup', (ev) => {
    if (ev.pointerType !== 'touch') return;
    cancelar();
    if (!disparado) return;
    // El dedo se levanta después de abrir el menú: ese toque no debe abrir el enlace ni la celda
    const bloquear = (e) => { e.preventDefault(); e.stopPropagation(); };
    document.addEventListener('click', bloquear, { capture: true, once: true });
    setTimeout(() => document.removeEventListener('click', bloquear, { capture: true }), 400);
  }, { passive: true });

  // Si el sistema ya dispara "contextmenu" con el toque largo (Android), no se duplica
  document.addEventListener('contextmenu', (ev) => {
    if (!ev.isTrusted) return;
    if (temporizador) { cancelar(); return; }
    if (Date.now() < suprimirNativoHasta) { ev.preventDefault(); ev.stopImmediatePropagation(); }
  }, true);

  /* ---------- Tarjeta de acciones del paciente ---------- */
  const tarjeta = document.querySelector('[data-tarjeta-lateral]');
  if (tarjeta) {
    document.addEventListener('pointerdown', (ev) => {
      if (ev.pointerType === 'mouse' || !tarjeta.classList.contains('abierta') || tarjeta.querySelector('.perfil')?.contains(ev.target)) return;
      if (ev.target.closest('.tarjeta-lateral__asa')) return;
      tarjeta.classList.remove('abierta');
      tarjeta.querySelector('.tarjeta-lateral__asa')?.setAttribute('aria-expanded', 'false');
    }, true);
  }
})();
