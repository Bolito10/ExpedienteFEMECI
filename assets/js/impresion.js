/* Expediente imprimible: con ?imprimir=1 abre el diálogo de impresión en cuanto la página terminó de cargar. */
if (document.body.dataset.imprimir === '1') {
  window.addEventListener('load', () => setTimeout(() => window.print(), 250));
}
