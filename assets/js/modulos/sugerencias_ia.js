/* ==========================================================================
   Sugerencias del expediente: «Resolver con IA» abre el asistente con la petición lista y el expediente
   del paciente como contexto (el clic del usuario es el permiso para compartirlo; el asistente avisa como siempre).
   ========================================================================== */
(() => {
  const caja = document.querySelector('[data-sugerencias-ia]');
  if (!caja) return;
  caja.addEventListener('click', (ev) => {
    const boton = ev.target.closest('[data-pedir-ia]');
    if (!boton) return;
    if (typeof window.preguntarAsistente !== 'function') {
      window.mostrarNotificacion?.('error', 'El asistente no está disponible en esta página.');
      return;
    }
    window.preguntarAsistente(boton.dataset.pedirIa, { acceso: 'expediente', herramienta: 'paciente' });
  });
})();
