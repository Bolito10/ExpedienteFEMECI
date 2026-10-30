/* ==========================================================================
   Imágenes médicas en el perfil del paciente: subir estudios arrastrando archivos o carpetas completas
   (un estudio de TC trae cientos de cortes DICOM). Se suben en tandas pequeñas para respetar los límites
   del servidor y se muestra el avance. Al terminar, la sección se recarga con el estudio agrupado.
   ========================================================================== */
(() => {
  const seccion = document.querySelector('[data-imagenes-medicas]');
  const zona = seccion?.querySelector('[data-imagenes-zona]');
  if (!seccion || !zona) return;
  const pacienteId = seccion.dataset.imagenesMedicas;
  const texto = zona.querySelector('[data-imagenes-texto]');
  const textoInicial = texto.textContent;
  const entrada = zona.querySelector('[data-imagenes-archivo]');
  const BASE_URL = document.body.dataset.base ?? '';
  const CSRF = document.querySelector('meta[name="csrf"]')?.content ?? '';
  const TANDA_ARCHIVOS = 15;
  const TANDA_BYTES = 30 * 1024 * 1024;
  let subiendo = false;

  // Archivos de una carpeta arrastrada (con sus subcarpetas)
  async function leerEntrada(item, lista) {
    if (item.isFile) {
      await new Promise((ok) => item.file((f) => { lista.push(f); ok(); }, ok));
    } else if (item.isDirectory) {
      const lector = item.createReader();
      let lote;
      do {
        lote = await new Promise((ok) => lector.readEntries(ok, () => ok([])));
        for (const hijo of lote) await leerEntrada(hijo, lista);
      } while (lote.length);
    }
  }

  async function subir(archivos) {
    if (subiendo) return;
    // Sin carpetas ocultas ni archivos del sistema (DICOMDIR, .DS_Store, thumbs.db)
    archivos = archivos.filter((f) => f.size > 0 && !/^(\.|thumbs\.db$|dicomdir$)/i.test(f.name));
    if (!archivos.length) return;
    subiendo = true;
    zona.classList.add('imagenes-subir--ocupada');
    const total = archivos.length;
    let enviados = 0;
    const acumulado = { guardados: 0, repetidos: 0, errores: [], nombres: new Set() };
    try {
      while (archivos.length) {
        const tanda = [];
        let bytes = 0;
        while (archivos.length && tanda.length < TANDA_ARCHIVOS && (bytes + archivos[0].size <= TANDA_BYTES || !tanda.length)) {
          const f = archivos.shift();
          tanda.push(f);
          bytes += f.size;
        }
        texto.textContent = `Subiendo ${enviados + 1}–${enviados + tanda.length} de ${total}…`;
        const datos = new FormData();
        datos.append('paciente_id', pacienteId);
        tanda.forEach((f) => datos.append('archivos[]', f, f.name));
        const r = await fetch(`${BASE_URL}/api/pacientes/imagenes.php`, { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: datos });
        const j = await r.json().catch(() => ({ ok: false, error: 'Respuesta inesperada del servidor.' }));
        if (!r.ok || !j.ok) throw new Error(j.error || 'No se pudo subir.');
        acumulado.guardados += j.guardados;
        acumulado.repetidos += j.repetidos;
        acumulado.errores.push(...j.errores);
        (j.nombres_dicom || []).forEach((n) => acumulado.nombres.add(n));
        enviados += tanda.length;
      }
      const partes = [`${acumulado.guardados} ${acumulado.guardados === 1 ? 'archivo agregado' : 'archivos agregados'}`];
      if (acumulado.repetidos) partes.push(`${acumulado.repetidos} ya estaban`);
      if (acumulado.errores.length) partes.push(`${acumulado.errores.length} no admitidos`);
      window.mostrarNotificacion?.(acumulado.errores.length && !acumulado.guardados ? 'error' : 'exito', partes.join(' · ') + '.');
      if (acumulado.errores.length) console.warn('Imágenes no admitidas:', acumulado.errores);
      // El nombre que trae el DICOM sirve para notar si se subió el estudio de otro paciente
      const nombre = document.body.dataset.pacienteNombre?.toLowerCase() ?? '';
      const ajenos = [...acumulado.nombres].filter((n) => n && nombre && !n.toLowerCase().split(/\s+/).some((p) => p.length > 2 && nombre.includes(p)));
      if (ajenos.length) window.mostrarNotificacion?.('error', `Revisa: el estudio dice «${ajenos[0]}», que no parece este paciente.`);
      if (acumulado.guardados) setTimeout(() => location.reload(), 900);
    } catch (e) {
      window.mostrarNotificacion?.('error', e.message);
    } finally {
      subiendo = false;
      zona.classList.remove('imagenes-subir--ocupada', 'imagenes-subir--encima');
      texto.textContent = textoInicial;
    }
  }

  entrada.addEventListener('change', () => { subir([...entrada.files]); entrada.value = ''; });
  zona.addEventListener('dragover', (ev) => { ev.preventDefault(); zona.classList.add('imagenes-subir--encima'); });
  zona.addEventListener('dragleave', () => zona.classList.remove('imagenes-subir--encima'));
  zona.addEventListener('drop', async (ev) => {
    ev.preventDefault();
    const lista = [];
    const items = [...(ev.dataTransfer.items || [])].map((i) => i.webkitGetAsEntry?.()).filter(Boolean);
    if (items.length) { for (const it of items) await leerEntrada(it, lista); } else { lista.push(...ev.dataTransfer.files); }
    subir(lista);
  });
})();
