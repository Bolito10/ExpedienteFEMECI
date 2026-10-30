/* ==========================================================================
   IA dentro de la nota (como en Notion): sin abrir el panel del asistente, se pregunta ahí mismo.
   Se abre con «/ia» (o «/preguntar»), con Ctrl+J, o con Espacio al inicio de un renglón vacío.
   Una sola tarjeta: petición arriba, respuesta en vivo y acciones (reemplazar, insertar, otra versión, refinar).
   El asistente conoce la nota abierta (pagina_id) y puede editarla con sus herramientas si se le pide.
   Funciona igual en las notas debajo de las secciones (Pacientes, Agenda, expediente).
   Usa Editor (espacio.js), markdownSeguro, BASE y TOKEN_CSRF (app.js).
   ========================================================================== */
(() => {
  if (typeof Editor === 'undefined' || typeof markdownSeguro !== 'function') return;
  // [texto del botón, petición, icono]; las de «renglón» solo aparecen si el renglón tiene texto
  const GRUPOS = [
    ['Escribir', [
      ['Continuar escribiendo', 'Continúa escribiendo a partir del renglón actual, con el mismo tono y formato. Devuelve solo el texto nuevo.', 'editar'],
      ['Resumir la nota', 'Resume esta nota en pocas líneas, con lo más importante primero.', 'texto'],
      ['Sacar pendientes', 'Haz una lista de tareas con los pendientes que aparecen en esta nota (formato - [ ] tarea).', 'casilla'],
      ['Ordenar por apartados', 'Propón esta nota ordenada en apartados con encabezados claros, sin perder información.', 'titulo'],
    ]],
    ['Editar el renglón', [
      ['Mejorar la redacción', 'Mejora la redacción del renglón actual sin cambiar su sentido. Devuelve solo el texto mejorado.', 'chispa'],
      ['Hacerlo más corto', 'Reescribe el renglón actual más corto y directo. Devuelve solo el texto.', 'menos'],
      ['Lenguaje clínico', 'Reescribe el renglón actual con terminología clínica correcta y formal. Devuelve solo el texto.', 'clinico'],
      ['Explicar', 'Explica con claridad el renglón actual.', 'info'],
    ], true],
  ];
  const REFINAR = [
    ['Más corto', 'Hazlo más corto.'],
    ['Más detallado', 'Hazlo más detallado.'],
    ['En lista', 'Ponlo como lista con viñetas.'],
    ['Más formal', 'Usa un tono más formal y clínico.'],
  ];
  let abierto = null;   // {caja, bloque, historial, controlador, respuesta, ultimaPeticion}

  const titulo = () => document.querySelector('[data-hoja-titulo]')?.textContent.trim()
    || Editor.raiz.closest('.notas-seccion')?.getAttribute('aria-label') || 'esta nota';

  function cerrar() {
    if (!abierto) return;
    const { caja, colocar } = abierto;
    abierto.controlador?.abort();
    window.removeEventListener('resize', colocar);
    abierto = null;
    caja.classList.add('ia-linea--saliendo');
    caja.addEventListener('animationend', () => caja.remove(), { once: true });
    setTimeout(() => caja.remove(), 260);
  }

  // Markdown de la respuesta → bloques de la nota (encabezados, listas, tareas, citas y párrafos)
  function bloquesDesde(texto) {
    return texto.replace(/```[\s\S]*?```/g, '').split(/\r?\n/).map((l) => l.trimEnd()).filter((l, i, a) => l || (a[i - 1] && a[i + 1])).map((l) => {
      let m;
      if ((m = l.match(/^###\s+(.*)/))) return { t: 'h3', x: m[1] };
      if ((m = l.match(/^##\s+(.*)/))) return { t: 'h2', x: m[1] };
      if ((m = l.match(/^#\s+(.*)/))) return { t: 'h1', x: m[1] };
      if ((m = l.match(/^[-*]\s+\[( |x|X)\]\s+(.*)/))) return { t: 'tarea', x: m[2], c: m[1].toLowerCase() === 'x' };
      if ((m = l.match(/^[-*•]\s+(.*)/))) return { t: 'lista', x: m[1] };
      if ((m = l.match(/^\d+[.)]\s+(.*)/))) return { t: 'numerada', x: m[1] };
      if ((m = l.match(/^>\s?(.*)/))) return { t: 'cita', x: m[1] };
      return { t: 'p', x: l };
    }).map((b) => ({ ...b, x: b.x.replace(/\*\*(.+?)\*\*/g, '$1').replace(/`([^`]+)`/g, '$1') }));
  }

  function estado(nombre) {
    const a = abierto;
    a.caja.dataset.estado = nombre;   // inicio | pensando | listo
    a.caja.querySelector('[data-ia-sugerencias]').hidden = nombre !== 'inicio';
    a.caja.querySelector('[data-ia-resultado]').hidden = nombre === 'inicio';
    a.caja.querySelector('[data-ia-acciones]').hidden = nombre !== 'listo';
  }

  async function preguntar(texto, { repetir = false } = {}) {
    const a = abierto;
    if (!a || !texto.trim() || a.caja.dataset.estado === 'pensando') return;
    const renglon = Editor.texto(a.bloque).trim();
    const contexto = renglon ? `\n\n(Renglón actual de la nota: «${renglon.slice(0, 600)}»)` : '';
    if (!repetir) a.historial.push({ rol: 'usuario', texto: texto + contexto });
    a.ultimaPeticion = texto;
    const resultado = a.caja.querySelector('[data-ia-resultado]');
    const pasos = a.caja.querySelector('[data-ia-pasos]');
    const md = a.caja.querySelector('[data-ia-respuesta]');
    a.caja.querySelector('[data-ia-pregunta]').textContent = texto;
    md.innerHTML = '';
    pasos.innerHTML = '<span class="ia-linea__paso ia-linea__paso--activo trShimmer" data-ia-pensando>Pensando…</span>';
    estado('pensando');
    let respuesta = '';
    let editoLaNota = false;
    a.controlador = new AbortController();
    try {
      // Debajo de una sección la nota se crea al primer uso, así la IA también puede escribir en ella
      const paginaId = Editor.editable ? await Editor.asegurarPagina() : Editor.paginaId;
      const r = await fetch(`${BASE}/api/asistente/conversar.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
        body: JSON.stringify({ mensajes: a.historial, acceso: 'expediente', herramienta: 'pagina', esfuerzo: 'medium', pagina_id: paginaId || null }),
        signal: a.controlador.signal,
      });
      const lector = r.body.getReader();
      const dec = new TextDecoder();
      let pendiente = '';
      for (;;) {
        const { value, done } = await lector.read();
        if (done) break;
        pendiente += dec.decode(value, { stream: true });
        let fin;
        while ((fin = pendiente.indexOf('\n')) >= 0) {
          const linea = pendiente.slice(0, fin).trim();
          pendiente = pendiente.slice(fin + 1);
          if (!linea) continue;
          const ev = JSON.parse(linea);
          if (ev.t === 'texto') {
            if (!respuesta) { pasos.querySelector('[data-ia-pensando]')?.remove(); pasos.querySelectorAll('.ia-linea__paso--activo').forEach((p) => p.classList.remove('ia-linea__paso--activo', 'trShimmer')); }
            respuesta += ev.d;
            md.innerHTML = markdownSeguro(respuesta);
            resultado.scrollTop = resultado.scrollHeight;
          } else if (ev.t === 'herramienta_inicio') {
            pasos.querySelector('[data-ia-pensando]')?.remove();
            pasos.querySelectorAll('.ia-linea__paso--activo').forEach((p) => p.classList.remove('ia-linea__paso--activo', 'trShimmer'));
            pasos.insertAdjacentHTML('beforeend', `<span class="ia-linea__paso ia-linea__paso--activo trShimmer">${escaparHtml(ev.d)}</span>`);
          } else if (ev.t === 'herramienta') {
            const ultimo = pasos.querySelector('.ia-linea__paso--activo');
            if (ultimo) { ultimo.classList.remove('ia-linea__paso--activo', 'trShimmer'); ultimo.classList.add(ev.ok ? 'ia-linea__paso--hecho' : 'ia-linea__paso--fallo'); ultimo.textContent = ev.d || ultimo.textContent; }
            if (ev.ok && ['editar_nota', 'escribir_nota'].includes(ev.nombre)) editoLaNota = true;
          } else if (ev.t === 'error' || ev.t === 'aviso') {
            respuesta += `\n\n*${ev.d}*`;
            md.innerHTML = markdownSeguro(respuesta);
          }
        }
      }
    } catch (e) {
      if (e.name === 'AbortError') return;
      md.textContent = 'No se pudo consultar al asistente. Revisa la conexión o los ajustes de la IA.';
    }
    if (abierto !== a) return;
    pasos.querySelectorAll('.ia-linea__paso--activo').forEach((p) => p.remove());
    a.historial.push({ rol: 'asistente', texto: respuesta });
    a.respuesta = respuesta;
    estado('listo');
    const conTexto = !!respuesta.trim() && !editoLaNota;
    a.caja.querySelector('[data-ia-insertar]').hidden = !conTexto;
    a.caja.querySelector('[data-ia-reemplazar]').hidden = !conTexto || !Editor.texto(a.bloque).trim();
    a.caja.querySelector('[data-ia-turnos]').textContent = a.historial.length > 2 ? `${a.historial.length / 2} intercambios` : '';
    const entrada = a.caja.querySelector('[data-ia-entrada]');
    entrada.value = '';
    entrada.placeholder = 'Pide un cambio o haz otra pregunta…';
    entrada.focus();
    // Si la IA editó la nota directamente, se recarga para ver el cambio
    if (editoLaNota) { window.mostrarNotificacion?.('exito', 'La IA actualizó la nota.'); setTimeout(() => location.reload(), 900); }
  }

  function insertar(reemplazar) {
    const a = abierto;
    let ultimo = a.bloque;
    const vacio = !Editor.texto(a.bloque).trim() && a.bloque.dataset.t === 'p';
    const nuevos = bloquesDesde(a.respuesta || '');
    nuevos.forEach((b) => { ultimo = Editor.insertarDespues(ultimo, b); ultimo.classList.add('bloque--nuevo-ia'); });
    if ((vacio || reemplazar) && ultimo !== a.bloque) a.bloque.remove();
    Editor.programarGuardado();
    cerrar();
    Editor.enfocar(ultimo);
  }

  Editor.abrirIaEnLinea = function (bloque) {
    cerrar();
    const contenedor = this.raiz.parentElement;
    if (getComputedStyle(contenedor).position === 'static') contenedor.style.position = 'relative';
    const conTexto = !!this.texto(bloque).trim();
    const caja = document.createElement('div');
    caja.className = 'ia-linea';
    caja.dataset.estado = 'inicio';
    caja.setAttribute('contenteditable', 'false');
    caja.innerHTML = `
      <div class="ia-linea__tarjeta">
        <div class="ia-linea__cabecera">
          <span class="ia-linea__marca">${ico('chispa')}<span>IA</span></span>
          <span class="ia-linea__contexto">sobre «${escaparHtml(titulo().slice(0, 60))}»</span>
          <span class="ia-linea__turnos" data-ia-turnos></span>
          <kbd class="ia-linea__tecla">Esc</kbd>
        </div>
        <div class="ia-linea__resultado" data-ia-resultado hidden>
          <p class="ia-linea__pregunta" data-ia-pregunta></p>
          <div class="ia-linea__pasos" data-ia-pasos></div>
          <div class="ia-linea__respuesta md" data-ia-respuesta></div>
        </div>
        <div class="ia-linea__barra">
          <textarea data-ia-entrada rows="1" placeholder="Pregunta a la IA sobre esta nota o pídele que escriba…" aria-label="Pregunta a la IA"></textarea>
          <button type="button" class="ia-linea__enviar" data-ia-enviar aria-label="Enviar">${ico('flecha_arriba') || '↑'}</button>
        </div>
        <div class="ia-linea__acciones" data-ia-acciones hidden>
          <div class="ia-linea__principales">
            <button type="button" class="ia-linea__boton ia-linea__boton--primario" data-ia-reemplazar>${ico('editar')}<span>Reemplazar renglón</span></button>
            <button type="button" class="ia-linea__boton" data-ia-insertar>${ico('agregar')}<span>Insertar debajo</span></button>
            <button type="button" class="ia-linea__boton" data-ia-otra>${ico('reabrir') || ''}<span>Otra versión</span></button>
            <button type="button" class="ia-linea__boton" data-ia-copiar>${ico('documento')}<span>Copiar</span></button>
            <button type="button" class="ia-linea__boton ia-linea__boton--tenue" data-ia-descartar>${ico('cerrar')}<span>Descartar</span></button>
          </div>
          <div class="ia-linea__refinar">${REFINAR.map(([t], i) => `<button type="button" data-ia-refinar="${i}">${t}</button>`).join('')}</div>
        </div>
      </div>
      <div class="menu-flotante ia-linea__sugerencias" data-ia-sugerencias>
        ${GRUPOS.filter(([, , soloConTexto]) => !soloConTexto || conTexto).map(([nombre, opciones]) => `
          <div class="menu-flotante__titulo">${nombre}</div>
          ${opciones.map(([t, , icono]) => `<button type="button" data-ia-sugerencia="${escaparHtml(t)}">${ico(icono) || ico('chispa')}<span>${t}</span></button>`).join('')}`).join('')}
      </div>`;
    contenedor.appendChild(caja);
    const colocar = () => {
      const cb = contenedor.getBoundingClientRect(), bb = bloque.getBoundingClientRect(), rb = this.raiz.getBoundingClientRect();
      caja.style.top = `${bb.bottom - cb.top + 6}px`;
      caja.style.left = `${rb.left - cb.left}px`;
      caja.style.width = `${rb.width}px`;
    };
    colocar();
    window.addEventListener('resize', colocar);
    abierto = { caja, bloque, historial: [], colocar };
    bloque.classList.add('bloque--con-ia');
    const entrada = caja.querySelector('[data-ia-entrada]');
    entrada.addEventListener('input', () => { entrada.style.height = 'auto'; entrada.style.height = `${Math.min(entrada.scrollHeight, 160)}px`; });
    entrada.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); preguntar(entrada.value); }
      if (ev.key === 'Escape') { ev.preventDefault(); cerrar(); bloque.classList.remove('bloque--con-ia'); Editor.enfocar(bloque); }
    });
    caja.querySelector('[data-ia-enviar]').addEventListener('click', () => preguntar(entrada.value));
    caja.addEventListener('click', (ev) => {
      const s = ev.target.closest('[data-ia-sugerencia]');
      if (s) {
        const opcion = GRUPOS.flatMap(([, o]) => o).find(([t]) => t === s.dataset.iaSugerencia);
        if (opcion) preguntar(opcion[1]);
      }
      const r = ev.target.closest('[data-ia-refinar]');
      if (r) preguntar(REFINAR[Number(r.dataset.iaRefinar)][1]);
      if (ev.target.closest('[data-ia-otra]')) {
        // Otra versión: quita la última respuesta y repite la misma petición
        abierto.historial.pop();
        preguntar(abierto.ultimaPeticion, { repetir: true });
      }
      if (ev.target.closest('[data-ia-descartar]')) { bloque.classList.remove('bloque--con-ia'); cerrar(); Editor.enfocar(bloque); }
      if (ev.target.closest('[data-ia-copiar]')) navigator.clipboard?.writeText(abierto.respuesta || '').then(() => window.mostrarNotificacion?.('exito', 'Respuesta copiada.'));
      if (ev.target.closest('[data-ia-insertar]')) { bloque.classList.remove('bloque--con-ia'); insertar(false); }
      if (ev.target.closest('[data-ia-reemplazar]')) { bloque.classList.remove('bloque--con-ia'); insertar(true); }
    });
    entrada.focus();
  };

  // Cerrar con un clic fuera (sin perder una respuesta en curso: solo si ya terminó o no se ha preguntado)
  document.addEventListener('mousedown', (ev) => {
    if (abierto && !abierto.caja.contains(ev.target) && abierto.caja.dataset.estado !== 'pensando') {
      abierto.bloque.classList.remove('bloque--con-ia');
      cerrar();
    }
  });
})();
