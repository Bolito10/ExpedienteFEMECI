/* ==========================================================================
   Importar y exportar (datos/index.php): subir un archivo, revisar el mapeo de
   columnas y la validación fila por fila, importar; exportar pacientes o una base
   de datos del espacio; historial de conversiones. Solo corre en esa página.
   ========================================================================== */
(() => {
  const raiz = document.querySelector('[data-datos]');
  const configEl = document.getElementById('datos-config');
  if (!raiz || !configEl) return;

  const CONFIG = JSON.parse(configEl.textContent);
  const BASE_URL = document.body.dataset.base ?? '';
  const CSRF = document.querySelector('meta[name="csrf"]')?.content ?? '';
  const ICONOS = JSON.parse(document.getElementById('iconos-js')?.textContent || '{}');
  const $ = (sel, el = raiz) => el.querySelector(sel);
  const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const avisar = (tipo, texto) => (typeof window.mostrarNotificacion === 'function' ? window.mostrarNotificacion(tipo, texto) : window.alert(texto));
  const plural = (n, uno, varios) => `${n.toLocaleString('es-MX')} ${n === 1 ? uno : varios}`;
  const tamano = (b) => (b < 1024 ? `${b} B` : b < 1048576 ? `${(b / 1024).toFixed(1)} KB` : `${(b / 1048576).toFixed(1)} MB`);

  async function api(datos) {
    const respuesta = await fetch(`${BASE_URL}/api/datos/importar.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(datos),
    });
    const json = await respuesta.json().catch(() => ({ ok: false, error: 'Respuesta inesperada del servidor.' }));
    if (!respuesta.ok && json.ok !== false) json.ok = false;
    return json;
  }

  /* ---------- Selector propio (lista desplegable) ---------- */
  let listaAbierta = null;

  function cerrarLista() {
    if (!listaAbierta) return;
    listaAbierta.boton.setAttribute('aria-expanded', 'false');
    listaAbierta.el.remove();
    listaAbierta = null;
  }

  function abrirLista(boton, opciones, actual, elegir) {
    cerrarLista();
    const el = document.createElement('div');
    el.className = 'datos-lista';
    el.setAttribute('role', 'listbox');
    const conFiltro = opciones.length > 12;
    el.innerHTML = conFiltro ? '<input type="text" class="datos-lista__filtro" placeholder="Buscar…" aria-label="Buscar opción">' : '';
    const contenedor = document.createElement('div');
    contenedor.className = 'datos-lista__opciones';
    el.appendChild(contenedor);

    const pintarOpciones = (filtro = '') => {
      const f = filtro.trim().toLowerCase();
      let grupo = null;
      let html = '';
      for (const o of opciones) {
        if (f && !`${o.texto} ${o.nota ?? ''}`.toLowerCase().includes(f)) continue;
        if (o.grupo && o.grupo !== grupo) {
          grupo = o.grupo;
          html += `<div class="datos-lista__grupo">${esc(grupo)}</div>`;
        }
        html += `<button type="button" role="option" class="datos-lista__opcion${o.valor === actual ? ' activa' : ''}" data-valor="${esc(o.valor)}" aria-selected="${o.valor === actual}"${o.deshabilitada ? ' disabled' : ''}>`
          + `<span class="datos-lista__texto">${esc(o.texto)}</span>${o.nota ? `<span class="datos-lista__nota">${esc(o.nota)}</span>` : ''}</button>`;
      }
      contenedor.innerHTML = html || '<div class="datos-lista__vacio">Sin coincidencias</div>';
    };
    pintarOpciones();
    document.body.appendChild(el);

    // Posición: debajo del botón (o arriba si no cabe)
    const r = boton.getBoundingClientRect();
    el.style.minWidth = `${Math.max(r.width, 200)}px`;
    const alto = el.offsetHeight;
    const abajo = window.innerHeight - r.bottom;
    el.style.left = `${Math.min(r.left, window.innerWidth - el.offsetWidth - 8)}px`;
    el.style.top = `${abajo < alto + 12 && r.top > alto + 12 ? r.top - alto - 4 : r.bottom + 4}px`;

    boton.setAttribute('aria-expanded', 'true');
    listaAbierta = { el, boton };
    const filtro = el.querySelector('.datos-lista__filtro');
    (filtro || el.querySelector('.datos-lista__opcion.activa') || el.querySelector('.datos-lista__opcion'))?.focus();
    filtro?.addEventListener('input', () => pintarOpciones(filtro.value));

    el.addEventListener('click', (ev) => {
      const opcion = ev.target.closest('.datos-lista__opcion');
      if (!opcion || opcion.disabled) return;
      cerrarLista();
      boton.focus();
      elegir(opcion.dataset.valor);
    });
    el.addEventListener('keydown', (ev) => {
      const botones = [...el.querySelectorAll('.datos-lista__opcion:not([disabled])')];
      const i = botones.indexOf(document.activeElement);
      if (ev.key === 'ArrowDown') { ev.preventDefault(); (botones[i + 1] || botones[0])?.focus(); }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); (i <= 0 ? (filtro || botones[botones.length - 1]) : botones[i - 1])?.focus(); }
      else if (ev.key === 'Enter' && document.activeElement === filtro) { ev.preventDefault(); botones[0]?.click(); }
      else if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); cerrarLista(); boton.focus(); }
      else if (ev.key === 'Tab') cerrarLista();
    });
  }

  document.addEventListener('mousedown', (ev) => {
    if (listaAbierta && !listaAbierta.el.contains(ev.target) && !listaAbierta.boton.contains(ev.target)) cerrarLista();
  });
  window.addEventListener('resize', cerrarLista);
  document.addEventListener('scroll', (ev) => { if (listaAbierta && !listaAbierta.el.contains(ev.target)) cerrarLista(); }, true);

  /** Botón que abre la lista; .valor y .establecer(v, opciones?) para leerlo o cambiarlo. */
  function crearSelector(opciones, valor, alCambiar, { vacio = 'Elegir', etiqueta = '' } = {}) {
    const boton = document.createElement('button');
    boton.type = 'button';
    boton.className = 'datos-selector';
    boton.setAttribute('aria-haspopup', 'listbox');
    boton.setAttribute('aria-expanded', 'false');
    if (etiqueta) boton.setAttribute('aria-label', etiqueta);
    let lista = opciones;
    const pintar = () => {
      const o = lista.find((x) => x.valor === boton.valor);
      boton.classList.toggle('datos-selector--vacio', !o || o.valor === '');
      boton.innerHTML = `<span class="datos-selector__texto">${esc(o ? o.texto : vacio)}</span>${ICONOS.flecha_abajo ?? ''}`;
    };
    boton.valor = valor;
    boton.establecer = (v, nuevas) => { if (nuevas) lista = nuevas; boton.valor = v; pintar(); };
    boton.addEventListener('click', () => {
      if (listaAbierta?.boton === boton) { cerrarLista(); return; }
      abrirLista(boton, typeof boton.opcionesVivas === 'function' ? boton.opcionesVivas() : lista, boton.valor, (v) => {
        boton.valor = v;
        pintar();
        alCambiar(v);
      });
    });
    pintar();
    return boton;
  }

  /** Casilla propia (role=checkbox). */
  function crearCasilla(marcada, texto, alCambiar) {
    const boton = document.createElement('button');
    boton.type = 'button';
    boton.className = 'datos-casilla';
    boton.setAttribute('role', 'checkbox');
    const pintar = () => boton.setAttribute('aria-checked', boton.marcada ? 'true' : 'false');
    boton.marcada = marcada;
    boton.innerHTML = `<span class="datos-casilla__caja" aria-hidden="true"><svg viewBox="0 0 16 16"><path d="M3.5 8.5l3 3 6-7"/></svg></span>${texto ? `<span class="datos-casilla__texto">${esc(texto)}</span>` : ''}`;
    if (!texto) boton.setAttribute('aria-label', 'Incluir');
    boton.addEventListener('click', () => { boton.marcada = !boton.marcada; pintar(); alCambiar(boton.marcada); });
    boton.establecer = (v) => { boton.marcada = v; pintar(); };
    pintar();
    return boton;
  }

  /** Fila de propiedad (etiqueta + control), como las propiedades de una página de Notion. */
  function propiedad(lista, etiqueta, control, clave) {
    const dt = document.createElement('dt');
    dt.textContent = etiqueta;
    const dd = document.createElement('dd');
    dd.appendChild(control);
    if (clave) { dt.dataset.prop = clave; dd.dataset.prop = clave; }
    lista.append(dt, dd);
  }

  /* ---------- Vistas ---------- */
  const pestanas = [...raiz.querySelectorAll('[data-datos-vista]')];
  pestanas.forEach((p) => p.addEventListener('click', () => {
    pestanas.forEach((x) => { x.classList.toggle('activa', x === p); x.setAttribute('aria-selected', x === p ? 'true' : 'false'); });
    raiz.querySelectorAll('[data-datos-panel]').forEach((panel) => { panel.hidden = panel.dataset.datosPanel !== p.dataset.datosVista; });
    cerrarLista();
  }));
  // #exportar o #historial en la dirección abre esa vista (p. ej. desde el menú de Pacientes)
  pestanas.find((p) => `#${p.dataset.datosVista}` === location.hash)?.click();

  /* ======================================================================
     Importar
     ====================================================================== */
  const zona = $('[data-datos-zona]');
  const entradaArchivo = $('[data-datos-archivo]');
  const cargando = $('[data-datos-cargando]');
  const analisis = $('[data-datos-analisis]');
  const resultado = $('[data-datos-resultado]');
  const botonImportar = $('[data-datos-importar]');
  const informeEl = $('[data-datos-informe]');

  let estado = null;            // importación en curso
  let temporizador = null;
  let solicitud = 0;

  const camposDestino = Object.entries(CONFIG.camposDestino).map(([valor, c]) => ({ valor, texto: c.texto, req: c.req }));
  const tiposColumna = Object.entries(CONFIG.tiposColumna).map(([valor, texto]) => ({ valor, texto }));

  zona.addEventListener('dragover', (ev) => { ev.preventDefault(); zona.classList.add('arrastrando'); });
  zona.addEventListener('dragleave', () => zona.classList.remove('arrastrando'));
  zona.addEventListener('drop', (ev) => {
    ev.preventDefault();
    zona.classList.remove('arrastrando');
    if (ev.dataTransfer.files[0]) analizar(ev.dataTransfer.files[0]);
  });
  entradaArchivo.addEventListener('change', () => { if (entradaArchivo.files[0]) analizar(entradaArchivo.files[0]); });

  async function analizar(archivo) {
    if (archivo.size > 20 * 1024 * 1024) { avisar('error', 'El archivo es demasiado grande (máximo 20 MB).'); return; }
    if (estado) api({ accion: 'descartar', token: estado.token });
    estado = null;
    zona.hidden = true;
    analisis.hidden = true;
    resultado.hidden = true;
    cargando.hidden = false;
    const datos = new FormData();
    datos.append('accion', 'analizar');
    datos.append('archivo', archivo);
    let json;
    try {
      const r = await fetch(`${BASE_URL}/api/datos/importar.php`, { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: datos });
      json = await r.json().catch(() => ({ ok: false, error: r.status === 413 ? 'El archivo es demasiado grande.' : 'Respuesta inesperada del servidor.' }));
    } catch {
      json = { ok: false, error: 'No se pudo enviar el archivo. Revisa la conexión.' };
    }
    cargando.hidden = true;
    entradaArchivo.value = '';
    if (!json.ok) {
      zona.hidden = false;
      avisar('error', json.error);
      return;
    }
    estado = {
      token: json.token, archivo: json.archivo, avisos: json.avisos, tablas: json.tablas, tabla: 0,
      duplicados: 'omitir', seccion: 'privado', titulo: '', esPacientes: json.pacientes,
    };
    cargarDetalle(json.detalle, true);
    analisis.hidden = false;
  }

  /** Aplica el detalle de una tabla (columnas, vista previa, sugerencias). */
  function cargarDetalle(detalle, primeraVez) {
    estado.detalle = detalle;
    estado.mapeo = detalle.columnas.map((c) => c.campo || '');
    estado.columnas = detalle.columnas.map((c) => ({ incluir: !c.vacia, nombre: c.nombre, tipo: c.tipo }));
    const asignadosObligatorios = camposDestino.filter((c) => c.req && estado.mapeo.includes(c.valor)).length;
    if (primeraVez || !estado.destinoElegido) {
      estado.destino = CONFIG.puede.importarPacientes && (estado.esPacientes || asignadosObligatorios >= 3) ? 'pacientes' : 'pagina';
    }
    if (!estado.tituloEditado) estado.titulo = estado.tablas.length > 1 ? detalle.nombre : estado.archivo.nombre.replace(/\.[^.]+$/, '');
    pintarAnalisis();
    validarPronto(0);
  }

  function pintarAnalisis() {
    const a = estado.archivo;
    $('[data-datos-nombre]').textContent = a.nombre;
    $('[data-datos-meta]').innerHTML = `${esc(a.formato_texto)} · ${esc(tamano(a.bytes))} · SHA-256 <button type="button" class="datos-huella" data-copiar="${esc(a.sha256)}" title="Copiar la huella completa">${esc(a.sha256.slice(0, 16))}…</button>`
      + (estado.avisos.length ? `<span class="datos-archivo__avisos">${estado.avisos.map(esc).join(' · ')}</span>` : '');
    pintarPropiedades();
    pintarMapeo();
    pintarVista();
  }

  function pintarPropiedades() {
    const lista = $('[data-datos-propiedades]');
    lista.innerHTML = '';
    if (estado.tablas.length > 1) {
      const esHoja = estado.archivo.formato === 'xlsx' || estado.archivo.formato === 'xml';
      const opciones = estado.tablas.map((t, i) => ({ valor: String(i), texto: t.nombre, nota: plural(t.filas, 'fila', 'filas') }));
      propiedad(lista, esHoja ? 'Hoja' : 'Tabla', crearSelector(opciones, String(estado.tabla), async (v) => {
        estado.tabla = Number(v);
        const json = await api({ accion: 'tabla', token: estado.token, tabla: estado.tabla });
        if (!json.ok) { avisar('error', json.error); return; }
        cargarDetalle(json.detalle, false);
      }, { etiqueta: esHoja ? 'Hoja' : 'Tabla' }));
    }
    const destinos = [];
    if (CONFIG.puede.importarPacientes) destinos.push({ valor: 'pacientes', texto: 'Pacientes', nota: 'expediente clínico' });
    destinos.push({ valor: 'pagina', texto: 'Nueva base de datos', nota: 'espacio de trabajo' });
    propiedad(lista, 'Destino', crearSelector(destinos, estado.destino, (v) => {
      estado.destino = v;
      estado.destinoElegido = true;
      pintarPropiedades();
      pintarMapeo();
      validarPronto(0);
    }, { etiqueta: 'Destino' }));

    if (estado.destino === 'pacientes') {
      propiedad(lista, 'Si ya existe', crearSelector([
        { valor: 'omitir', texto: 'Omitir el registro', nota: 'no se modifica' },
        { valor: 'actualizar', texto: 'Actualizar sus datos', nota: 'solo los campos con valor' },
      ], estado.duplicados, (v) => { estado.duplicados = v; validarPronto(0); }, { etiqueta: 'Si el paciente ya existe' }));
      const nota = document.createElement('span');
      nota.className = 'datos-propiedades__texto';
      nota.textContent = 'Misma CURP, o mismo nombre, primer apellido y fecha de nacimiento';
      propiedad(lista, 'Duplicados', nota);
    } else {
      const titulo = document.createElement('input');
      titulo.type = 'text';
      titulo.className = 'datos-entrada';
      titulo.maxLength = 200;
      titulo.value = estado.titulo;
      titulo.placeholder = 'Sin título';
      titulo.setAttribute('aria-label', 'Título de la base de datos');
      titulo.addEventListener('input', () => { estado.titulo = titulo.value; estado.tituloEditado = true; });
      propiedad(lista, 'Título', titulo);
      propiedad(lista, 'Ubicación', crearSelector([
        { valor: 'privado', texto: 'Privado', nota: 'solo tú' },
        { valor: 'compartido', texto: 'Compartido', nota: 'todo el equipo' },
      ], estado.seccion, (v) => { estado.seccion = v; }, { etiqueta: 'Ubicación' }));
    }
  }

  function opcionesCampo(indice) {
    const usados = new Map(estado.mapeo.map((c, i) => [c, i]));
    return [{ valor: '', texto: 'No importar' }].concat(camposDestino.map((c) => {
      const otro = usados.has(c.valor) && usados.get(c.valor) !== indice ? estado.detalle.columnas[usados.get(c.valor)].nombre : null;
      return { valor: c.valor, texto: c.texto, nota: otro ? `en uso: ${otro}` : (c.req ? 'obligatorio' : '') };
    }));
  }

  function pintarMapeo() {
    const tabla = $('[data-datos-mapeo]');
    const ayuda = $('[data-datos-ayuda-mapeo]');
    const columnas = estado.detalle.columnas;
    tabla.innerHTML = '';
    if (estado.destino === 'pacientes') {
      ayuda.textContent = 'Cada columna del archivo se asignó al campo del expediente que más se le parece. Cambia lo que no corresponda; las columnas sin campo no se importan.';
      tabla.innerHTML = '<colgroup><col style="width:240px"><col><col style="width:280px"></colgroup>'
        + `<thead><tr><th scope="col">${ICONOS.aa ?? ''} Columna del archivo</th><th scope="col">${ICONOS.texto ?? ''} Ejemplos</th><th scope="col">${ICONOS.seleccion ?? ''} Campo del expediente</th></tr></thead><tbody></tbody>`;
      const cuerpo = tabla.querySelector('tbody');
      const selectores = [];
      columnas.forEach((col, i) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td class="datos-mapeo__columna" title="${esc(col.nombre)}">${esc(col.nombre)}</td><td class="datos-mapeo__ejemplos" title="${esc(col.ejemplos.join(' · '))}">${col.ejemplos.length ? esc(col.ejemplos.join(' · ')) : '<span class="datos-tenue">vacía</span>'}</td><td class="datos-mapeo__control"></td>`;
        const selector = crearSelector(opcionesCampo(i), estado.mapeo[i], (v) => {
          // Un campo solo puede venir de una columna: si ya estaba asignado, se mueve aquí
          const anterior = estado.mapeo.indexOf(v);
          if (v && anterior !== -1 && anterior !== i) {
            estado.mapeo[anterior] = '';
            selectores[anterior].establecer('');
          }
          estado.mapeo[i] = v;
          validarPronto();
        }, { vacio: 'No importar', etiqueta: `Campo para la columna ${col.nombre}` });
        selector.opcionesVivas = () => opcionesCampo(i);
        selectores.push(selector);
        tr.lastElementChild.appendChild(selector);
        cuerpo.appendChild(tr);
      });
    } else {
      ayuda.textContent = 'Elige qué columnas pasan a la base de datos, su nombre y su tipo. La primera columna incluida será el nombre de cada fila.';
      tabla.innerHTML = '<colgroup><col style="width:56px"><col style="width:260px"><col style="width:180px"><col></colgroup>'
        + `<thead><tr><th scope="col"><span class="sr-only">Incluir</span></th><th scope="col">${ICONOS.aa ?? ''} Nombre en la base de datos</th><th scope="col">${ICONOS.seleccion ?? ''} Tipo</th><th scope="col">${ICONOS.texto ?? ''} Ejemplos</th></tr></thead><tbody></tbody>`;
      const cuerpo = tabla.querySelector('tbody');
      const primeraIncluida = estado.columnas.findIndex((c) => c.incluir);
      columnas.forEach((col, i) => {
        const cfg = estado.columnas[i];
        const tr = document.createElement('tr');
        tr.classList.toggle('datos-mapeo__excluida', !cfg.incluir);
        tr.innerHTML = `<td class="datos-mapeo__casilla"></td><td class="datos-mapeo__nombre"></td><td class="datos-mapeo__control"></td><td class="datos-mapeo__ejemplos" title="${esc(col.ejemplos.join(' · '))}">${col.ejemplos.length ? esc(col.ejemplos.join(' · ')) : '<span class="datos-tenue">vacía</span>'}</td>`;
        tr.children[0].appendChild(crearCasilla(cfg.incluir, '', (v) => { cfg.incluir = v; pintarMapeo(); validarPronto(); }));
        const nombre = document.createElement('input');
        nombre.type = 'text';
        nombre.className = 'datos-entrada datos-entrada--celda';
        nombre.value = cfg.nombre;
        nombre.maxLength = 60;
        nombre.setAttribute('aria-label', `Nombre de la columna ${col.nombre}`);
        nombre.addEventListener('input', () => { cfg.nombre = nombre.value; });
        tr.children[1].appendChild(nombre);
        const esPrimera = i === primeraIncluida;
        const selector = crearSelector(tiposColumna, esPrimera ? 'texto' : cfg.tipo, (v) => { cfg.tipo = v; validarPronto(); }, { etiqueta: `Tipo de la columna ${col.nombre}` });
        if (esPrimera) {
          selector.disabled = true;
          selector.title = 'La primera columna es el nombre de cada fila y siempre es texto';
        }
        tr.children[2].appendChild(selector);
        cuerpo.appendChild(tr);
      });
    }
  }

  function pintarVista() {
    const d = estado.detalle;
    const tabla = $('[data-datos-previa]');
    tabla.innerHTML = `<thead><tr>${d.columnas.map((c) => `<th scope="col" title="${esc(c.nombre)}">${esc(c.nombre)}</th>`).join('')}</tr></thead>`
      + `<tbody>${d.muestra.map((f) => `<tr>${f.map((v) => `<td title="${esc(v)}">${esc(v)}</td>`).join('')}</tr>`).join('')}</tbody>`;
    $('[data-datos-previa-nota]').textContent = d.total === 0 ? 'La tabla no tiene filas de datos.'
      : `Primeras ${Math.min(20, d.total)} de ${plural(d.total, 'fila', 'filas')}.`;
  }

  function cuerpoValidacion(accion) {
    const base = { accion, token: estado.token, tabla: estado.tabla, destino: estado.destino };
    if (estado.destino === 'pacientes') return { ...base, mapeo: estado.mapeo.map((c) => c || null), duplicados: estado.duplicados };
    return { ...base, columnas: estado.columnas, titulo: estado.titulo, seccion: estado.seccion };
  }

  function validarPronto(espera = 350) {
    clearTimeout(temporizador);
    botonImportar.disabled = true;
    informeEl.classList.add('datos-informe--actualizando');
    temporizador = setTimeout(validar, espera);
  }

  async function validar() {
    if (!estado) return;
    const numero = ++solicitud;
    const json = await api(cuerpoValidacion('validar'));
    if (numero !== solicitud || !estado) return;
    informeEl.classList.remove('datos-informe--actualizando');
    if (!json.ok) {
      if (json.error && /venció/.test(json.error)) reiniciar();
      informeEl.innerHTML = `<p class="datos-informe__error">${esc(json.error)}</p>`;
      return;
    }
    pintarInforme(json);
  }

  function listaPlegable(titulo, elementos, pintarElemento, visibles = 8) {
    if (!elementos.length) return '';
    const items = elementos.map((e, i) => `<li${i >= visibles ? ' hidden' : ''}>${pintarElemento(e)}</li>`).join('');
    const mas = elementos.length > visibles ? `<button type="button" class="datos-enlace" data-ver-todos>Ver ${elementos.length === 500 ? 'los primeros 500' : `los ${elementos.length}`}</button>` : '';
    return `<div class="datos-informe__bloque"><h3>${esc(titulo)}</h3><ul class="datos-informe__lista">${items}</ul>${mas}</div>`;
  }

  function pintarInforme(json) {
    const r = json.resumen;
    const partes = [];
    const cifra = (n, texto, clase = '') => `<span class="datos-informe__cifra ${clase}"><b>${n.toLocaleString('es-MX')}</b> ${texto}</span>`;
    if (estado.destino === 'pacientes') {
      partes.push(cifra(r.nuevos, r.nuevos === 1 ? 'paciente nuevo' : 'pacientes nuevos'));
      if (r.actualizar) partes.push(cifra(r.actualizar, r.actualizar === 1 ? 'se actualizará' : 'se actualizarán'));
      if (r.omitidos) partes.push(cifra(r.omitidos, r.omitidos === 1 ? 'ya existe y se omitirá' : 'ya existen y se omitirán'));
    } else {
      partes.push(cifra(r.nuevos, r.nuevos === 1 ? 'fila lista' : 'filas listas'));
    }
    if (r.errores) partes.push(cifra(r.errores, r.errores === 1 ? 'con errores no se importará' : 'con errores no se importarán', 'datos-informe__cifra--error'));
    if (r.vacias) partes.push(cifra(r.vacias, r.vacias === 1 ? 'vacía' : 'vacías', 'datos-informe__cifra--tenue'));

    let html = '';
    if (json.faltan?.length) html += `<p class="datos-informe__error">Falta asignar: ${esc(json.faltan.join(', '))}.</p>`;
    (json.avisos || []).forEach((a) => { html += `<p class="datos-informe__error">${esc(a)}</p>`; });
    html += `<p class="datos-informe__resumen">${partes.join('<span class="datos-informe__separador" aria-hidden="true">·</span>')}</p>`;
    html += listaPlegable('Filas con errores', json.errores, (e) => `<span class="datos-informe__fila">${esc(e.fila)}</span><span>${esc(e.mensajes.join(' · '))}</span>`);
    html += listaPlegable(estado.duplicados === 'actualizar' ? 'Pacientes que se actualizarán' : 'Pacientes que ya existen', json.duplicados,
      (d) => `<span class="datos-informe__fila">${esc(d.fila)}</span><span><a href="${BASE_URL}/pacientes/ver.php?id=${d.id}" target="_blank" rel="noopener">${esc(d.folio)}</a> · ${esc(d.motivo)}</span>`);
    informeEl.innerHTML = html;

    const aEscribir = r.nuevos + (estado.destino === 'pacientes' ? r.actualizar : 0);
    botonImportar.disabled = aEscribir === 0 || !!json.faltan?.length || !!json.avisos?.length;
    botonImportar.textContent = aEscribir ? `Importar ${plural(aEscribir, 'fila', 'filas')}` : 'Importar';
  }

  informeEl.addEventListener('click', (ev) => {
    const boton = ev.target.closest('[data-ver-todos]');
    if (!boton) return;
    boton.previousElementSibling.querySelectorAll('li[hidden]').forEach((li) => { li.hidden = false; });
    boton.remove();
  });

  botonImportar.addEventListener('click', async () => {
    if (!estado || botonImportar.disabled) return;
    if (estado.destino === 'pagina' && !estado.titulo.trim()) estado.titulo = estado.archivo.nombre.replace(/\.[^.]+$/, '');
    botonImportar.disabled = true;
    const textoOriginal = botonImportar.textContent;
    botonImportar.textContent = 'Importando…';
    const json = await api(cuerpoValidacion('importar'));
    botonImportar.textContent = textoOriginal;
    if (!json.ok) {
      avisar('error', json.error);
      if (json.resumen) pintarInforme(json);
      else botonImportar.disabled = false;
      return;
    }
    const r = json.resumen;
    const escritos = r.nuevos + (estado.destino === 'pacientes' ? r.actualizar : 0);
    const detalle = [
      estado.destino === 'pacientes' && r.nuevos ? plural(r.nuevos, 'paciente nuevo', 'pacientes nuevos') : null,
      estado.destino === 'pacientes' && r.actualizar ? plural(r.actualizar, 'actualizado', 'actualizados') : null,
      r.omitidos ? plural(r.omitidos, 'duplicado omitido', 'duplicados omitidos') : null,
      r.errores ? plural(r.errores, 'fila con errores omitida', 'filas con errores omitidas') : null,
    ].filter(Boolean);
    resultado.innerHTML = `<p class="datos-resultado__titulo">Se importaron ${esc(plural(escritos, 'fila', 'filas'))} a ${esc(json.destino_texto)}.</p>`
      + (detalle.length ? `<p class="datos-ayuda">${esc(detalle.join(' · '))}. Conversión #${json.conversion.id} registrada en el historial.</p>` : `<p class="datos-ayuda">Conversión #${json.conversion.id} registrada en el historial.</p>`)
      + `<div class="datos-acciones datos-acciones--inicio"><a class="boton boton--primario" href="${esc(json.url)}">Abrir ${estado.destino === 'pacientes' ? 'Pacientes' : 'la base de datos'}</a><button type="button" class="boton boton--secundario" data-datos-otro>Importar otro archivo</button></div>`;
    agregarAlHistorial(json.conversion);
    estado = null;
    analisis.hidden = true;
    resultado.hidden = false;
    avisar('exito', 'Importación terminada.');
  });

  function reiniciar() {
    if (estado) api({ accion: 'descartar', token: estado.token });
    estado = null;
    analisis.hidden = true;
    resultado.hidden = true;
    zona.hidden = false;
    clearTimeout(temporizador);
  }

  raiz.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-datos-otro]')) { reiniciar(); entradaArchivo.click(); }
    const copiar = ev.target.closest('[data-copiar]');
    if (copiar) {
      navigator.clipboard?.writeText(copiar.dataset.copiar).then(() => avisar('exito', 'Huella SHA-256 copiada.'), () => avisar('error', 'No se pudo copiar.'));
    }
  });
  $('[data-datos-descartar]').addEventListener('click', reiniciar);

  /* ======================================================================
     Exportar
     ====================================================================== */
  const exportar = {
    origen: CONFIG.puede.exportarPacientes ? 'pacientes' : (CONFIG.paginas[0] ? `pagina:${CONFIG.paginas[0].id}` : ''),
    formato: 'csv',
    campos: new Set(),
  };
  const propiedadesExportar = $('[data-datos-exportar-propiedades]');
  const camposEl = $('[data-datos-campos]');
  const botonExportar = $('[data-datos-exportar]');

  function paginaElegida() {
    return exportar.origen.startsWith('pagina:') ? CONFIG.paginas.find((p) => `pagina:${p.id}` === exportar.origen) : null;
  }

  function camposDisponibles() {
    const pagina = paginaElegida();
    if (pagina) return pagina.columnas.map((c) => ({ valor: c.id, texto: c.nombre }));
    if (exportar.origen === 'pacientes') return Object.entries(CONFIG.camposExportar).map(([valor, texto]) => ({ valor, texto }));
    return [];
  }

  function pintarExportar() {
    propiedadesExportar.innerHTML = '';
    const origenes = [];
    if (CONFIG.puede.exportarPacientes) origenes.push({ valor: 'pacientes', texto: 'Pacientes', nota: plural(CONFIG.totalPacientes, 'paciente', 'pacientes'), grupo: 'Expediente' });
    CONFIG.paginas.forEach((p) => origenes.push({ valor: `pagina:${p.id}`, texto: p.titulo, nota: plural(p.filas, 'fila', 'filas'), grupo: 'Bases de datos del espacio' }));
    if (!origenes.length) {
      propiedadesExportar.innerHTML = '';
      camposEl.innerHTML = '<p class="datos-ayuda">No hay datos que puedas exportar: tu rol no consulta expedientes y no tienes bases de datos en el espacio de trabajo.</p>';
      botonExportar.hidden = true;
      $('[data-datos-campos-todos]').parentElement.hidden = true;
      return;
    }
    propiedad(propiedadesExportar, 'Datos', crearSelector(origenes, exportar.origen, (v) => {
      exportar.origen = v;
      if (paginaElegida() && CONFIG.formatos[exportar.formato]?.solo_pacientes) exportar.formato = 'csv';
      exportar.campos = new Set(camposDisponibles().map((c) => c.valor));
      pintarExportar();
    }, { etiqueta: 'Datos a exportar' }));
    const formatos = Object.entries(CONFIG.formatos)
      .filter(([, f]) => !(f.solo_pacientes && paginaElegida()))
      .map(([valor, f]) => ({ valor, texto: f.texto, grupo: f.grupo }));
    propiedad(propiedadesExportar, 'Formato', crearSelector(formatos, exportar.formato, (v) => { exportar.formato = v; pintarNotaExportar(); }, { etiqueta: 'Formato' }));
    pintarCampos();
    pintarNotaExportar();
  }

  function pintarCampos() {
    camposEl.innerHTML = '';
    camposDisponibles().forEach((c) => {
      camposEl.appendChild(crearCasilla(exportar.campos.has(c.valor), c.texto, (v) => {
        if (v) exportar.campos.add(c.valor); else exportar.campos.delete(c.valor);
        pintarNotaExportar();
      }));
    });
  }

  function pintarNotaExportar() {
    const nota = $('[data-datos-exportar-nota]');
    const pagina = paginaElegida();
    const textos = [];
    if (pagina) {
      textos.push(`Se exportarán ${plural(pagina.filas, 'fila', 'filas')} de «${pagina.titulo}».`);
    } else {
      textos.push(`Se exportarán ${plural(CONFIG.totalPacientes, 'paciente activo', 'pacientes activos')}. La exportación queda en el historial de conversiones y en la bitácora de cada paciente.`);
    }
    if (exportar.formato === 'fhir') textos.push('FHIR R4: Patient con AllergyIntolerance, Observation (grupo sanguíneo), Condition y MedicationStatement; la CURP usa el OID de RENAPO.');
    if (exportar.formato === 'hl7v2') textos.push('Un mensaje ADT^A04 (HL7 v2.5) por paciente, con segmentos PID, NK1, AL1, DG1 y OBX.');
    if (exportar.formato.startsWith('sql_')) textos.push('Incluye CREATE TABLE e INSERT; revisa el archivo antes de ejecutarlo en otro sistema.');
    nota.textContent = textos.join(' ');
    botonExportar.disabled = exportar.campos.size === 0;
  }

  $('[data-datos-campos-todos]').addEventListener('click', () => { exportar.campos = new Set(camposDisponibles().map((c) => c.valor)); pintarCampos(); pintarNotaExportar(); });
  $('[data-datos-campos-ninguno]').addEventListener('click', () => { exportar.campos.clear(); pintarCampos(); pintarNotaExportar(); });

  botonExportar.addEventListener('click', async () => {
    if (botonExportar.disabled) return;
    const pagina = paginaElegida();
    const orden = camposDisponibles().map((c) => c.valor).filter((c) => exportar.campos.has(c));
    botonExportar.disabled = true;
    botonExportar.textContent = 'Generando…';
    try {
      const r = await fetch(`${BASE_URL}/api/datos/exportar.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ origen: pagina ? 'pagina' : 'pacientes', pagina: pagina?.id, campos: orden, formato: exportar.formato }),
      });
      if (!r.ok || (r.headers.get('Content-Type') || '').startsWith('application/json') && !r.headers.get('X-Conversion-Id')) {
        const json = await r.json().catch(() => ({ error: 'No se pudo generar el archivo.' }));
        avisar('error', json.error || 'No se pudo generar el archivo.');
        return;
      }
      const nombre = (r.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/)?.[1] || 'exportacion';
      const blob = await r.blob();
      const url = URL.createObjectURL(blob);
      const enlace = document.createElement('a');
      enlace.href = url;
      enlace.download = nombre;
      document.body.appendChild(enlace);
      enlace.click();
      enlace.remove();
      setTimeout(() => URL.revokeObjectURL(url), 10000);
      avisar('exito', `Archivo generado: ${nombre}`);
      const historial = await api({ accion: 'historial' });
      if (historial.ok) { CONFIG.historial = historial.conversiones; pintarHistorial(); }
    } catch {
      avisar('error', 'No se pudo generar el archivo. Revisa la conexión.');
    } finally {
      botonExportar.textContent = 'Exportar';
      botonExportar.disabled = exportar.campos.size === 0;
    }
  });

  /* ======================================================================
     Historial
     ====================================================================== */
  function agregarAlHistorial(conversion) {
    CONFIG.historial.unshift(conversion);
    pintarHistorial();
  }

  function pintarHistorial() {
    const tabla = $('[data-datos-historial]');
    const lista = CONFIG.historial;
    $('[data-datos-contador]').textContent = lista.length;
    $('[data-datos-historial-vacio]').hidden = lista.length > 0;
    tabla.hidden = lista.length === 0;
    const persona = CONFIG.todoElHistorial;
    tabla.innerHTML = `<colgroup><col style="width:150px">${persona ? '<col style="width:170px">' : ''}<col style="width:120px"><col style="width:170px"><col style="width:260px"><col style="width:220px"><col style="width:220px"><col style="width:120px"><col style="width:300px"></colgroup>`
      + `<thead><tr><th scope="col">${ICONOS.reloj ?? ''} Fecha</th>${persona ? `<th scope="col">${ICONOS.usuarios ?? ''} Persona</th>` : ''}<th scope="col">${ICONOS.seleccion ?? ''} Operación</th><th scope="col">${ICONOS.aa ?? ''} Formato</th>`
      + `<th scope="col">${ICONOS.documento ?? ''} Archivo</th><th scope="col">${ICONOS.texto ?? ''} Origen</th><th scope="col">${ICONOS.texto ?? ''} Destino</th><th scope="col" class="num">${ICONOS.numero ?? ''} Filas</th><th scope="col">${ICONOS.texto ?? ''} Notas</th></tr></thead>`
      + `<tbody>${lista.map((c) => `<tr>
          <td title="${esc(c.fecha)}">${esc(c.fecha_texto)}</td>
          ${persona ? `<td>${esc(c.persona)}</td>` : ''}
          <td><span class="datos-operacion datos-operacion--${c.direccion}">${c.direccion === 'importar' ? 'Importación' : 'Exportación'}</span></td>
          <td title="${esc(c.formato_texto)}">${esc(c.formato_texto)}</td>
          <td class="datos-historial__archivo"><span title="${esc(c.archivo)}">${esc(c.archivo)}</span><button type="button" class="datos-huella" data-copiar="${esc(c.sha256)}" title="SHA-256: ${esc(c.sha256)} · clic para copiar">${esc(c.sha256.slice(0, 12))}</button></td>
          <td title="${esc(c.origen)}">${esc(c.origen)}</td>
          <td title="${esc(c.destino)}">${esc(c.destino)}</td>
          <td class="num">${c.ok.toLocaleString('es-MX')}${c.error ? ` <span class="datos-historial__error" title="${plural(c.error, 'fila con errores', 'filas con errores')}">· ${c.error.toLocaleString('es-MX')}</span>` : ''}</td>
          <td title="${esc(c.notas)}">${esc(c.notas)}</td>
        </tr>`).join('')}</tbody>`;
  }

  exportar.campos = new Set(camposDisponibles().map((c) => c.valor));
  pintarExportar();
  pintarHistorial();
})();
