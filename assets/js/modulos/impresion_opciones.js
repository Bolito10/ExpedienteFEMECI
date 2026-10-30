/* ==========================================================================
   Imprimir desde el perfil del paciente: elegir qué se imprime antes de abrir el documento
   (pacientes/imprimir.php). Secciones del expediente con interruptor (todas encendidas) y, debajo,
   documentos sueltos del historial: si se elige alguno, se imprimen solo esos.
   Mismos componentes que el panel "Propiedades" de Pacientes (abrirPopover, .propiedades, .interruptor).
   ========================================================================== */
(() => {
  const fuente = document.getElementById('impresion-datos');
  if (!fuente) return;
  const datos = JSON.parse(fuente.textContent);
  const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fila = (nombre, valor, texto, detalle, encendido) => `
    <label class="propiedades__fila"><span>${esc(texto)}${detalle ? ` <small class="impresion-opciones__detalle">${esc(detalle)}</small>` : ''}</span>
      <input type="checkbox" name="${nombre}" value="${esc(valor)}"${encendido ? ' checked' : ''}><i class="interruptor" aria-hidden="true"></i></label>`;

  window.abrirImpresion = (ancla) => {
    if (typeof abrirPopover !== 'function') { window.print(); return; }
    const caja = document.createElement('div');
    caja.className = 'propiedades impresion-opciones';
    caja.innerHTML = `
      <div class="menu-flotante__titulo">Imprimir</div>
      <div class="propiedades__lista">${datos.partes.map((p) => fila('partes', p.clave, p.texto, p.n ? String(p.n) : '', true)).join('')}</div>
      ${datos.registros.length ? `<div class="menu-flotante__separador"></div>
        <div class="menu-flotante__titulo">Solo algunos documentos</div>
        <div class="propiedades__lista impresion-opciones__documentos">${datos.registros.map((r) => fila('registros', r.id, r.texto, r.fecha, false)).join('')}</div>` : ''}
      <p class="impresion-opciones__resumen" data-resumen></p>
      <button type="button" class="boton boton--primario propiedades__agregar" data-imprimir-ahora>Imprimir</button>`;
    abrirPopover(ancla, caja, { clase: 'popover--menu popover--propiedades', alinear: 'derecha' });

    const resumen = caja.querySelector('[data-resumen]');
    const marcados = (nombre) => [...caja.querySelectorAll(`input[name="${nombre}"]:checked`)].map((i) => i.value);
    const actualizar = () => {
      const docs = marcados('registros');
      const partes = marcados('partes');
      // Con documentos elegidos, las secciones no aplican: se imprimen solo esos documentos
      caja.querySelectorAll('input[name="partes"]').forEach((i) => { i.disabled = docs.length > 0; });
      resumen.textContent = docs.length
        ? (docs.length === 1 ? 'Se imprimirá el documento elegido.' : `Se imprimirán ${docs.length} documentos.`)
        : partes.length === datos.partes.length ? 'Se imprimirá el expediente completo.' : partes.length ? `Se imprimirán ${partes.length} de ${datos.partes.length} secciones.` : 'Elige al menos una sección o un documento.';
      caja.querySelector('[data-imprimir-ahora]').disabled = !docs.length && !partes.length;
    };
    caja.addEventListener('change', actualizar);
    actualizar();

    caja.querySelector('[data-imprimir-ahora]').addEventListener('click', () => {
      const parametros = new URLSearchParams({ id: datos.id, imprimir: '1' });
      const docs = marcados('registros');
      const partes = marcados('partes');
      if (docs.length) docs.forEach((d) => parametros.append('registros[]', d));
      else if (partes.length < datos.partes.length) partes.forEach((p) => parametros.append('partes[]', p));
      window.open(`${document.body.dataset.base ?? ''}/pacientes/imprimir.php?${parametros}`, '_blank', 'noopener');
      if (typeof cerrarPopover === 'function') cerrarPopover();
    });
  };
})();
