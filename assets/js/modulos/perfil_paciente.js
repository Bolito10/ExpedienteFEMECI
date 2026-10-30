/* ==========================================================================
   Perfil del paciente a la medida de cada quien (pacientes/ver.php):
   - Barra fina de íconos para enfocarse en una cosa (clínico, signos, imágenes, historial, agenda, datos, notas).
   - Buscador dentro del perfil: deja solo las secciones que contienen lo buscado.
   - Modos de vista: dos columnas, lectura (una columna tranquila) y compacto (solo títulos, se abren al tocar).
   - Cada sección se pliega con su flecha y se mueve arrastrando su asa (también entre columnas).
   Orden, plegado y modo se recuerdan en este navegador para todos los expedientes (preferencia personal).
   ========================================================================== */
(() => {
  const cuerpo = document.querySelector('.hoja-paciente__cuerpo');
  const resumen = document.querySelector('.hoja-paciente__resumen');
  if (!cuerpo || !resumen) return;
  const contenido = cuerpo.parentElement;
  const columnas = { datos: cuerpo.querySelector('.hoja-paciente__datos'), lado: cuerpo.querySelector('.hoja-paciente__lado') };
  const CLAVE = `femeci-perfil-${typeof USUARIO_ID !== 'undefined' ? USUARIO_ID : ''}`;
  const leer = () => { try { return JSON.parse(localStorage.getItem(CLAVE) || '{}'); } catch (e) { return {}; } };
  const guardar = (cambios) => { try { localStorage.setItem(CLAVE, JSON.stringify({ ...leer(), ...cambios })); } catch (e) { /* opcional */ } };
  const sinAcentos = (t) => String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
  const icono = (n) => (typeof ico === 'function' ? ico(n) : '');

  // Enfoques: qué secciones muestra cada ícono (por su título)
  const ENFOQUES = [
    ['todo', 'Todo', 'panel', null],
    ['clinico', 'Clínico', 'clinico', ['sugerencias', 'diagnosticos', 'tratamiento', 'hospitalizaciones', 'evaluacion', 'antecedentes']],
    ['signos', 'Signos vitales', 'termometro', ['signos vitales']],
    ['imagenes', 'Imágenes', 'imagen', ['imagenes']],
    ['historial', 'Historial', 'reloj', ['historial clinico', 'actividad', 'registros por mes', 'cambios al expediente']],
    ['agenda', 'Citas', 'calendario', ['proximas citas']],
    ['datos', 'Datos personales', 'usuario', ['identificacion', 'contacto', 'ubicacion']],
    ['notas', 'Notas', 'documento', ['notas y bases de datos', 'notas del expediente']],
  ];
  const MODOS = [['columnas', 'Dos columnas', 'panel'], ['lectura', 'Lectura', 'texto'], ['compacto', 'Compacto', 'lista']];

  // Secciones con una clave estable sacada de su título
  const tituloDe = (s) => (s.querySelector('.ficha__titulo, .grafica__titulo')?.textContent || s.getAttribute('aria-label') || '').trim();
  const secciones = [...document.querySelectorAll('.contenido__pagina .ficha__seccion, #notas-seccion')].filter((s) => !s.closest('.popover'));
  secciones.forEach((s) => { s.dataset.perfilClave = sinAcentos(tituloDe(s)).replace(/\s+/g, '-') || `seccion-${secciones.indexOf(s)}`; });
  const movibles = secciones.filter((s) => s.parentElement === columnas.datos || s.parentElement === columnas.lado);

  /* --- Barra --- */
  const barra = document.createElement('div');
  barra.className = 'perfil-barra';
  barra.setAttribute('role', 'toolbar');
  barra.setAttribute('aria-label', 'Cómo ver este expediente');
  barra.innerHTML = `
    <div class="perfil-barra__enfoques" role="radiogroup" aria-label="Enfocar">
      ${ENFOQUES.map(([k, t, i]) => `<button type="button" class="perfil-barra__boton" role="radio" aria-checked="${k === 'todo'}" data-enfoque="${k}" title="${t}" aria-label="${t}">${icono(i)}<span class="perfil-barra__texto">${t}</span></button>`).join('')}
    </div>
    <label class="perfil-barra__buscar" title="Buscar en este expediente">${icono('buscar')}<input type="search" placeholder="Buscar en el expediente" aria-label="Buscar en el expediente" data-perfil-buscar></label>
    <span class="perfil-barra__separador" aria-hidden="true"></span>
    <div class="perfil-barra__modos" role="radiogroup" aria-label="Vista">
      ${MODOS.map(([k, t, i]) => `<button type="button" class="perfil-barra__boton" role="radio" aria-checked="false" data-modo="${k}" title="Vista: ${t}" aria-label="Vista: ${t}">${icono(i)}</button>`).join('')}
      <button type="button" class="perfil-barra__boton" data-perfil-mas title="Más opciones" aria-label="Más opciones" aria-haspopup="menu">${icono('mas')}</button>
    </div>`;
  resumen.after(barra);
  // Fija debajo de la barra de ruta (que también se queda arriba al desplazar)
  const fijarTope = () => { const cab = document.querySelector('.barra-superior'); barra.style.top = cab && getComputedStyle(cab).position === 'sticky' ? `${cab.offsetHeight}px` : '0px'; };
  fijarTope();
  window.addEventListener('resize', fijarTope);
  const aviso = document.createElement('p');
  aviso.className = 'perfil-aviso';
  aviso.hidden = true;
  barra.after(aviso);

  /* --- Plegar y arrastrar: controles en el título de cada sección --- */
  secciones.forEach((s) => {
    const fila = s.querySelector(':scope > .ficha__titulo-fila, :scope > .grafica__cabecera') || s.querySelector(':scope > .ficha__titulo');
    if (!fila) return;
    const controles = document.createElement('span');
    controles.className = 'perfil-controles';
    controles.innerHTML = `${movibles.includes(s) ? `<button type="button" class="perfil-controles__asa" data-perfil-asa title="Arrastra para mover la sección" aria-label="Mover sección">${icono('agarre')}</button>` : ''}`
      + `<button type="button" class="perfil-controles__plegar" data-perfil-plegar title="Ocultar o mostrar el contenido" aria-expanded="true">${icono('flecha_abajo')}</button>`;
    fila.prepend(controles);
    fila.classList.add('perfil-titulo');
  });

  /* --- Estado --- */
  let estado = { modo: 'columnas', plegadas: [], orden: null, ...leer() };
  let enfoque = 'todo';
  let termino = '';

  function aplicarOrden() {
    if (!estado.orden) return;
    ['datos', 'lado'].forEach((col) => (estado.orden[col] || []).forEach((clave) => {
      const s = movibles.find((x) => x.dataset.perfilClave === clave);
      if (s) columnas[col].appendChild(s);
    }));
    // El pie «Registrado el…» queda al final de su columna
    const registro = columnas.datos.querySelector('.hoja-paciente__registro');
    if (registro) columnas.datos.appendChild(registro);
  }
  function guardarOrden() {
    estado.orden = { datos: [...columnas.datos.querySelectorAll(':scope > [data-perfil-clave]')].map((s) => s.dataset.perfilClave),
                     lado: [...columnas.lado.querySelectorAll(':scope > [data-perfil-clave]')].map((s) => s.dataset.perfilClave) };
    guardar({ orden: estado.orden });
  }
  function aplicarPlegado() {
    secciones.forEach((s) => {
      const plegada = estado.modo === 'compacto' ? !(estado.abiertasCompacto || []).includes(s.dataset.perfilClave) : estado.plegadas.includes(s.dataset.perfilClave);
      s.classList.toggle('perfil--plegada', plegada);
      s.querySelector('[data-perfil-plegar]')?.setAttribute('aria-expanded', String(!plegada));
    });
  }
  function aplicarModo() {
    contenido.classList.toggle('perfil-modo--lectura', estado.modo === 'lectura');
    contenido.classList.toggle('perfil-modo--compacto', estado.modo === 'compacto');
    barra.querySelectorAll('[data-modo]').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.modo === estado.modo)));
    aplicarPlegado();
  }
  function aplicarFiltro() {
    const lista = ENFOQUES.find(([k]) => k === enfoque)?.[3];
    const q = sinAcentos(termino);
    let visibles = 0;
    secciones.forEach((s) => {
      const clave = sinAcentos(tituloDe(s));
      const porEnfoque = !lista || lista.some((x) => clave.startsWith(x));
      const porTexto = !q || sinAcentos(s.textContent).includes(q);
      const ver = porEnfoque && porTexto;
      s.classList.toggle('perfil--oculta', !ver);
      if (ver) visibles++;
      // Al buscar, las secciones que coinciden se abren aunque estén plegadas
      if (q && ver) s.classList.remove('perfil--plegada');
    });
    if (!q) aplicarPlegado();
    const filtrando = enfoque !== 'todo' || !!q;
    contenido.classList.toggle('perfil--filtrando', filtrando);
    document.querySelector('.hoja-paciente__registro')?.toggleAttribute('hidden', filtrando);
    aviso.hidden = !filtrando;
    aviso.innerHTML = !filtrando ? '' : visibles
      ? `${visibles === 1 ? 'Se muestra 1 sección' : `Se muestran ${visibles} secciones`}${q ? ` con «${escaparHtml(termino)}»` : ''}. <button type="button" data-perfil-todo>Ver todo</button>`
      : `Nada coincide${q ? ` con «${escaparHtml(termino)}»` : ''}. <button type="button" data-perfil-todo>Ver todo</button>`;
    barra.querySelectorAll('[data-enfoque]').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.enfoque === enfoque)));
  }

  aplicarOrden();
  aplicarModo();

  /* --- Eventos --- */
  const verTodo = () => {
    enfoque = 'todo';
    termino = '';
    barra.querySelector('[data-perfil-buscar]').value = '';
    aplicarFiltro();
  };
  barra.addEventListener('click', (ev) => {
    const e = ev.target.closest('[data-enfoque]');
    if (e) { enfoque = e.dataset.enfoque === enfoque ? 'todo' : e.dataset.enfoque; aplicarFiltro(); window.scrollTo?.({ top: 0 }); return; }
    const m = ev.target.closest('[data-modo]');
    if (m) { estado.modo = m.dataset.modo; guardar({ modo: estado.modo }); aplicarModo(); return; }
    const mas = ev.target.closest('[data-perfil-mas]');
    if (mas && typeof abrirMenu === 'function') {
      abrirMenu(mas, [
        { titulo: 'Organizar el expediente' },
        { icono: 'arriba', texto: 'Plegar todas las secciones', accion: () => { estado.plegadas = secciones.map((s) => s.dataset.perfilClave); guardar({ plegadas: estado.plegadas }); aplicarPlegado(); } },
        { icono: 'abajo', texto: 'Desplegar todas', accion: () => { estado.plegadas = []; estado.abiertasCompacto = secciones.map((s) => s.dataset.perfilClave); guardar({ plegadas: [], abiertasCompacto: estado.abiertasCompacto }); aplicarPlegado(); } },
        'separador',
        { icono: 'reabrir', texto: 'Restablecer orden y vista', detalle: 'como venía', accion: () => { try { localStorage.removeItem(CLAVE); } catch (e) { /* opcional */ } location.reload(); } },
      ], { alinear: 'derecha' });
    }
  });
  aviso.addEventListener('click', (ev) => { if (ev.target.closest('[data-perfil-todo]')) verTodo(); });
  let temporizador = null;
  barra.querySelector('[data-perfil-buscar]').addEventListener('input', (ev) => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => { termino = ev.target.value; aplicarFiltro(); }, 120);
  });
  barra.querySelector('[data-perfil-buscar]').addEventListener('keydown', (ev) => { if (ev.key === 'Escape') { ev.target.value = ''; termino = ''; aplicarFiltro(); } });

  document.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-perfil-plegar]');
    // En compacto, tocar el título también abre o cierra
    const tituloCompacto = estado.modo === 'compacto' && ev.target.closest('.perfil-titulo') && !ev.target.closest('a, button:not([data-perfil-plegar]), [data-perfil-asa]');
    if (!b && !tituloCompacto) return;
    const s = (b || ev.target).closest('[data-perfil-clave]');
    if (!s) return;
    const clave = s.dataset.perfilClave;
    if (estado.modo === 'compacto') {
      const abiertas = new Set(estado.abiertasCompacto || []);
      abiertas.has(clave) ? abiertas.delete(clave) : abiertas.add(clave);
      estado.abiertasCompacto = [...abiertas];
      guardar({ abiertasCompacto: estado.abiertasCompacto });
    } else {
      const plegadas = new Set(estado.plegadas);
      plegadas.has(clave) ? plegadas.delete(clave) : plegadas.add(clave);
      estado.plegadas = [...plegadas];
      guardar({ plegadas: estado.plegadas });
    }
    aplicarPlegado();
  });

  /* --- Arrastrar secciones (ratón y toque) --- */
  let arrastre = null;
  document.addEventListener('pointerdown', (ev) => {
    const asa = ev.target.closest('[data-perfil-asa]');
    if (!asa || ev.button !== 0) return;
    const s = asa.closest('[data-perfil-clave]');
    if (!s || contenido.classList.contains('perfil--filtrando')) return;
    ev.preventDefault();
    const r = s.getBoundingClientRect();
    const fantasma = s.cloneNode(true);
    fantasma.className = `${s.className} perfil-fantasma`;
    fantasma.style.width = `${r.width}px`;
    fantasma.style.left = `${r.left}px`;
    fantasma.style.top = `${r.top}px`;
    document.body.appendChild(fantasma);
    const marcador = document.createElement('div');
    marcador.className = 'perfil-marcador';
    s.after(marcador);
    s.classList.add('perfil--arrastrando');
    arrastre = { s, fantasma, marcador, dy: ev.clientY - r.top, x0: ev.clientX - r.left };
    asa.setPointerCapture?.(ev.pointerId);
    document.body.classList.add('perfil-moviendo');
  });
  document.addEventListener('pointermove', (ev) => {
    if (!arrastre) return;
    const { fantasma, marcador, s, dy, x0 } = arrastre;
    fantasma.style.top = `${ev.clientY - dy}px`;
    fantasma.style.left = `${ev.clientX - x0}px`;
    // Columna bajo el puntero (en lectura/compacto hay una sola: la de datos sigue siendo la referencia)
    const col = Object.values(columnas).find((c) => { const r = c.getBoundingClientRect(); return ev.clientX >= r.left - 20 && ev.clientX <= r.right + 20; }) || s.parentElement;
    const hermanas = [...col.querySelectorAll(':scope > [data-perfil-clave]')].filter((x) => x !== s && !x.classList.contains('perfil--oculta'));
    const antes = hermanas.find((x) => { const r = x.getBoundingClientRect(); return ev.clientY < r.top + r.height / 2; });
    if (antes) col.insertBefore(marcador, antes);
    else {
      const registro = col.querySelector(':scope > .hoja-paciente__registro');
      registro ? col.insertBefore(marcador, registro) : col.appendChild(marcador);
    }
    // Desplazamiento automático cerca de los bordes
    if (ev.clientY < 70) window.scrollBy(0, -14); else if (ev.clientY > innerHeight - 70) window.scrollBy(0, 14);
  });
  const soltar = () => {
    if (!arrastre) return;
    const { s, fantasma, marcador } = arrastre;
    marcador.replaceWith(s);
    fantasma.remove();
    s.classList.remove('perfil--arrastrando');
    s.classList.add('perfil--recien-movida');
    setTimeout(() => s.classList.remove('perfil--recien-movida'), 900);
    document.body.classList.remove('perfil-moviendo');
    arrastre = null;
    guardarOrden();
  };
  document.addEventListener('pointerup', soltar);
  document.addEventListener('pointercancel', soltar);
  document.addEventListener('keydown', (ev) => {
    // Accesible con teclado: con el foco en el asa, flechas arriba/abajo mueven la sección
    const asa = ev.target.closest?.('[data-perfil-asa]');
    if (!asa || !['ArrowUp', 'ArrowDown'].includes(ev.key)) return;
    ev.preventDefault();
    const s = asa.closest('[data-perfil-clave]');
    const vecina = ev.key === 'ArrowUp' ? s.previousElementSibling : s.nextElementSibling;
    if (!vecina?.dataset.perfilClave) return;
    ev.key === 'ArrowUp' ? vecina.before(s) : vecina.after(s);
    asa.focus();
    guardarOrden();
  });
})();
