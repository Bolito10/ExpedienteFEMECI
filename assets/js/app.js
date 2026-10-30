/* ==========================================================================
   Expedientes Clínicos — comportamiento común
   ========================================================================== */

/* ---------- CURP ---------- */
const CURP_REGEX = /^[A-Z][AEIOUX][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HMX](AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)[B-DF-HJ-NP-TV-Z]{3}[A-Z\d]\d$/;

/** Calcula el dígito verificador (posición 18) según RENAPO. */
function digitoVerificadorCurp(curp17) {
  const diccionario = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';
  let suma = 0;
  for (let i = 0; i < 17; i++) {
    suma += diccionario.indexOf(curp17.charAt(i)) * (18 - i);
  }
  return (10 - (suma % 10)) % 10;
}

function curpValida(curp) {
  if (!CURP_REGEX.test(curp)) return false;
  return digitoVerificadorCurp(curp.slice(0, 17)) === Number(curp.charAt(17));
}

/** Extrae fecha de nacimiento, sexo y entidad de una CURP válida. */
function datosDesdeCurp(curp) {
  const aa = curp.slice(4, 6);
  const siglo = /\d/.test(curp.charAt(16)) ? '19' : '20';
  return {
    fecha: `${siglo}${aa}-${curp.slice(6, 8)}-${curp.slice(8, 10)}`,
    sexo: curp.charAt(10),
    entidad: curp.slice(11, 13),
  };
}

/* ---------- Utilidades de formulario ---------- */
function marcarError(campoEl, mensaje) {
  const campo = campoEl.closest('.campo');
  if (!campo) return;
  campo.classList.toggle('campo--error', Boolean(mensaje));
  let ayuda = campo.querySelector('.campo__ayuda');
  if (mensaje) {
    if (!ayuda) {
      ayuda = document.createElement('span');
      ayuda.className = 'campo__ayuda';
      campo.appendChild(ayuda);
    }
    if (!ayuda.dataset.original) ayuda.dataset.original = ayuda.textContent;
    ayuda.textContent = mensaje;
  } else if (ayuda && ayuda.dataset.original !== undefined) {
    ayuda.textContent = ayuda.dataset.original;
  }
}

function rellenarSiVacio(el, valor) {
  if (el && !el.value) {
    el.value = valor;
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }
}

/* Fechas vacías con color de placeholder */
function actualizarFechasVacias() {
  document.querySelectorAll('input[type="date"]').forEach((el) => {
    el.classList.toggle('vacio', !el.value);
  });
}

/* ---------- Barra lateral ---------- */
const PANTALLA_CHICA = window.matchMedia('(max-width: 900px)');

function alternarLateral() {
  const raiz = document.documentElement;
  if (PANTALLA_CHICA.matches) {
    raiz.classList.toggle('lateral-abierta');
    return;
  }
  const oculta = raiz.classList.toggle('lateral-oculta');
  try { localStorage.setItem('lateral-oculta', oculta ? '1' : '0'); } catch (e) { /* sin almacenamiento */ }
}

document.addEventListener('click', (ev) => {
  const accion = ev.target.closest('[data-accion]')?.dataset.accion;
  if (accion === 'alternar-lateral') alternarLateral();
  if (accion === 'cerrar-lateral') document.documentElement.classList.remove('lateral-abierta');
});

/* ---------- Tabla de base de datos: filtro inmediato y orden por columna ---------- */
function iniciarBaseDatos(bd) {
  const tabla = bd.querySelector('.bd-tabla');
  const cuerpo = tabla.tBodies[0];
  const buscar = bd.querySelector('[data-bd-buscar]');
  const contador = bd.querySelector('[data-bd-contador]');
  const vacio = bd.querySelector('[data-bd-vacio]');
  const filas = () => Array.from(cuerpo.rows);

  // Filtra mientras se escribe; el botón Buscar consulta la base de datos completa.
  const normalizar = (t) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  buscar?.addEventListener('input', () => {
    const termino = normalizar(buscar.value.trim());
    let visibles = 0;
    filas().forEach((fila) => {
      const coincide = !termino || normalizar(fila.textContent).includes(termino);
      fila.hidden = !coincide;
      if (coincide) visibles++;
    });
    if (contador) contador.textContent = visibles;
    vacio.hidden = visibles > 0 || filas().length === 0;
  });

  tabla.querySelectorAll('thead tr:last-child th').forEach((th, indice) => {
    // Clic en el encabezado: menú de la propiedad (ordenar, renombrar, ocultar) si está disponible
    th.querySelector('button').addEventListener('click', () => {
      if (window.menuPropiedadPacientes) window.menuPropiedadPacientes(th, (asc) => ordenar(asc));
      else ordenar(th.getAttribute('aria-sort') !== 'ascending');
    });
    const ordenar = (ascendente) => {
      tabla.querySelectorAll('thead tr:last-child th').forEach((otro) => otro.removeAttribute('aria-sort'));
      th.setAttribute('aria-sort', ascendente ? 'ascending' : 'descending');

      const valor = (fila) => {
        const celda = fila.cells[indice];
        const texto = celda.dataset.valor ?? celda.textContent.trim();
        return th.dataset.orden === 'numero' ? Number(texto) || 0 : texto;
      };
      const ordenadas = filas().sort((a, b) => {
        const x = valor(a);
        const y = valor(b);
        const r = typeof x === 'number' ? x - y : String(x).localeCompare(String(y), 'es', { sensitivity: 'base' });
        return ascendente ? r : -r;
      });
      cuerpo.append(...ordenadas);
    };
  });

  cuerpo.querySelector('tr.nuevo')?.scrollIntoView({ block: 'nearest' });
}

/** Aplica la clase de salida y quita el elemento al terminar (o enseguida si no hay animación). */
function quitarConAnimacion(el, claseSalida) {
  el.classList.add(claseSalida);
  if (el.getAnimations().length === 0) { el.remove(); return; }
  el.addEventListener('animationend', () => el.remove(), { once: true });
  setTimeout(() => el.remove(), 400);
}

/* ---------- Menú de clic izquierdo: tarjeta del paciente ---------- */
const BASE = document.body.dataset.base ?? '';
// Versión de app.js (?v=…): se reutiliza para los scripts que se cargan después
const VERSION_APP = (document.currentScript?.src.match(/[?&]v=(\d+)/) || [])[1] || String(Date.now());
const tarjetasEnCache = new Map();
let menuAbierto = null;

function cerrarMenuFlotante() {
  if (!menuAbierto) return;
  const { tarjeta, fila } = menuAbierto;
  menuAbierto = null;
  fila?.classList.remove('seleccionado');
  quitarConAnimacion(tarjeta, 'cerrando');
}

/** Coloca la tarjeta junto al cursor sin salirse de la ventana. */
function posicionar(tarjeta, x, y) {
  const margen = 12;
  const { width, height } = tarjeta.getBoundingClientRect();
  const izquierda = x + width + margen > innerWidth ? Math.max(margen, x - width) : x;
  const arriba = Math.min(Math.max(margen, y), Math.max(margen, innerHeight - height - margen));
  tarjeta.style.left = `${izquierda}px`;
  tarjeta.style.top = `${arriba}px`;
  tarjeta.style.setProperty('--origen', `${x - izquierda}px ${y - arriba}px`);
}

async function abrirMenuPaciente(fila, x, y) {
  const id = fila.dataset.paciente;
  if (menuAbierto?.fila === fila) { cerrarMenuFlotante(); return; }
  cerrarMenuFlotante();

  let html = tarjetasEnCache.get(id);
  if (!html) {
    const respuesta = await fetch(`${BASE}/api/pacientes/tarjeta.php?id=${encodeURIComponent(id)}`);
    if (!respuesta.ok) return;
    html = await respuesta.text();
    tarjetasEnCache.set(id, html);
  }

  const plantilla = document.createElement('template');
  plantilla.innerHTML = html.trim();
  const tarjeta = plantilla.content.firstElementChild;
  tarjeta.setAttribute('role', 'dialog');
  document.body.appendChild(tarjeta);
  posicionar(tarjeta, x, y);

  fila.classList.add('seleccionado');
  menuAbierto = { tarjeta, fila };
  tarjeta.querySelector('.menu__opcion')?.focus({ preventScroll: true });
}

document.addEventListener('click', (ev) => {
  // Opciones aún no disponibles: no navegan
  const deshabilitada = ev.target.closest('.menu__opcion[aria-disabled="true"]');
  if (deshabilitada) { ev.preventDefault(); return; }

  // Clic en el nombre: abre el menú del paciente (Ctrl/⌘ + clic abre la ficha en otra pestaña)
  const celdaTitulo = ev.target.closest('.bd-tabla td.bd-tabla__celda-titulo');
  if (celdaTitulo && !(ev.ctrlKey || ev.metaKey || ev.shiftKey)) {
    ev.preventDefault();
    abrirMenuPaciente(celdaTitulo.closest('tr'), ev.clientX, ev.clientY);
    return;
  }

  // Clic en una celda editable: se edita en línea
  const editable = ev.target.closest('.bd-tabla td[data-campo]');
  if (editable) {
    cerrarMenuFlotante();
    if (!editable.classList.contains('editando')) editarCelda(editable);
    return;
  }

  if (menuAbierto && !menuAbierto.tarjeta.contains(ev.target)) cerrarMenuFlotante();
});

/* ---------- Edición en línea de la tabla (como Notion) ---------- */
const OPCIONES_EDITOR = JSON.parse(document.getElementById('opciones-editor')?.textContent || '{}');
const TOKEN_CSRF = document.querySelector('meta[name="csrf"]')?.content ?? '';
let celdaEditando = null;

function editarCelda(td) {
  if (celdaEditando) terminarEdicion(true);

  const campo = td.dataset.campo;
  const opciones = OPCIONES_EDITOR[campo];
  const original = td.dataset.valor ?? '';
  let control;

  if (opciones) {
    control = document.createElement('select');
    control.add(new Option('—', ''));
    Object.entries(opciones).forEach(([valor, texto]) => control.add(new Option(texto, valor)));
    control.value = original;
    control.addEventListener('change', () => terminarEdicion(true));
  } else {
    control = document.createElement('input');
    control.type = 'text';
    control.value = original;
    if (campo === 'curp') {
      control.maxLength = 18;
      control.spellcheck = false;
      control.addEventListener('input', () => { control.value = control.value.toUpperCase(); });
    }
  }

  control.className = 'bd-tabla__editor';
  control.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter') { ev.preventDefault(); terminarEdicion(true); }
    if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); terminarEdicion(false); }
    if (ev.key === 'Tab') {
      ev.preventDefault();
      const siguiente = celdaVecina(td, ev.shiftKey ? -1 : 1);
      terminarEdicion(true);
      if (siguiente) editarCelda(siguiente);
    }
  });
  control.addEventListener('blur', () => { if (celdaEditando === td) terminarEdicion(true); });

  td._edicion = { original, textoOriginal: td.textContent, control };
  td.classList.add('editando');
  td.textContent = '';
  td.appendChild(control);
  control.focus();
  if (control.select) control.select();
  celdaEditando = td;
}

/** Celda editable anterior/siguiente en orden de lectura (para Tab). */
function celdaVecina(td, paso) {
  const celdas = Array.from(td.closest('tbody').querySelectorAll('tr:not([hidden]) td[data-campo]'));
  return celdas[celdas.indexOf(td) + paso] ?? null;
}

async function terminarEdicion(guardar) {
  const td = celdaEditando;
  if (!td) return;
  celdaEditando = null;

  const { original, textoOriginal, control } = td._edicion;
  const campo = td.dataset.campo;
  const valor = control.value.trim();
  td.classList.remove('editando');

  if (!guardar || valor === original) {
    td.textContent = textoOriginal;
    return;
  }

  // Muestra el valor mientras se guarda; si falla, se restaura el anterior
  td.textContent = OPCIONES_EDITOR[campo] ? (OPCIONES_EDITOR[campo][valor] ?? '') : valor;
  td.classList.add('guardando');

  try {
    const respuesta = await fetch(`${BASE}/api/pacientes/actualizar.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
      body: JSON.stringify({ id: td.closest('tr').dataset.paciente, campo, valor }),
    });
    const datos = await respuesta.json();
    if (!datos.ok) throw new Error(datos.error);

    td.dataset.valor = datos.valor;
    td.textContent = datos.texto;
    td.title = datos.texto;
    td.classList.remove('guardado');
    void td.offsetWidth; // reinicia la animación
    td.classList.add('guardado');
  } catch (error) {
    td.textContent = textoOriginal;
    mostrarNotificacion('error', error.message || 'No se pudo guardar el cambio.');
  } finally {
    td.classList.remove('guardando');
  }
}

/** Muestra un aviso con el mismo estilo que los del servidor. */
function mostrarNotificacion(tipo, texto) {
  let contenedor = document.querySelector('.notificaciones');
  if (!contenedor) {
    contenedor = document.createElement('div');
    contenedor.className = 'notificaciones';
    contenedor.setAttribute('aria-live', 'polite');
    document.body.appendChild(contenedor);
  }
  const aviso = document.createElement('div');
  aviso.className = `notificacion notificacion--${tipo}`;
  aviso.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
  aviso.innerHTML = '<svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg><span></span>';
  aviso.querySelector('span').textContent = texto;
  contenedor.appendChild(aviso);
  programarCierre(aviso);
}

document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Escape') cerrarMenuFlotante();
});
window.addEventListener('resize', cerrarMenuFlotante);
document.addEventListener('scroll', (ev) => {
  if (menuAbierto && !menuAbierto.tarjeta.contains(ev.target)) cerrarMenuFlotante();
}, true);

/* ---------- Paleta de búsqueda (lupa, botón Buscar o Ctrl K) ---------- */
const ICONOS_PALETA = {
  buscar: '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
  pagina: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
  usuarioMas: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M19 8v6M16 11h6"/>',
  filtro: '<path d="M4 5h16l-6 8v6l-4-2v-4z"/>',
};
const svgPaleta = (nombre) => `<svg class="icono" viewBox="0 0 24 24" aria-hidden="true">${ICONOS_PALETA[nombre]}</svg>`;
const escaparHtml = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

let paleta = null;

function abrirPaleta() {
  if (paleta) { paleta.entrada.focus(); return; }
  cerrarMenuFlotante();

  const velo = document.createElement('div');
  velo.className = 'paleta-velo';
  const caja = document.createElement('div');
  caja.className = 'paleta';
  caja.setAttribute('role', 'dialog');
  caja.setAttribute('aria-modal', 'true');
  caja.setAttribute('aria-label', 'Buscar pacientes');
  caja.innerHTML = `
    <div class="paleta__entrada">
      <input type="text" placeholder="Buscar por nombre, CURP o folio…" aria-label="Buscar" autocomplete="off" spellcheck="false"
             role="combobox" aria-expanded="true" aria-controls="paleta-lista">
      <span class="paleta__circulo">${svgPaleta('buscar')}</span>
    </div>
    <div class="paleta__meta">
      <span data-total></span>
      <button type="button" class="paleta__chip" data-orden="recientes"><span>Ordenar por</span><span data-orden-texto>Más recientes</span></button>
    </div>
    <div class="paleta__lista" id="paleta-lista" role="listbox"></div>
    <div class="paleta__pie">
      <span><kbd class="tecla">esc</kbd> Cerrar</span>
    </div>`;
  document.body.append(velo, caja);

  paleta = {
    velo, caja,
    entrada: caja.querySelector('input'),
    lista: caja.querySelector('.paleta__lista'),
    total: caja.querySelector('[data-total]'),
    chip: caja.querySelector('.paleta__chip'),
    elementos: [],
    activo: 0,
    peticion: null,
    temporizador: null,
  };

  paleta.entrada.addEventListener('input', () => {
    clearTimeout(paleta.temporizador);
    paleta.temporizador = setTimeout(buscarEnPaleta, 140);
  });
  paleta.entrada.addEventListener('keydown', teclasPaleta);
  paleta.chip.addEventListener('click', () => {
    const porNombre = paleta.chip.dataset.orden === 'recientes';
    paleta.chip.dataset.orden = porNombre ? 'nombre' : 'recientes';
    paleta.chip.querySelector('[data-orden-texto]').textContent = porNombre ? 'Nombre A–Z' : 'Más recientes';
    buscarEnPaleta();
    paleta.entrada.focus();
  });
  velo.addEventListener('click', cerrarPaleta);
  paleta.lista.addEventListener('mousemove', (ev) => {
    const item = ev.target.closest('.paleta__item');
    if (item) marcarActivo(Number(item.dataset.indice));
  });
  paleta.lista.addEventListener('click', (ev) => {
    const item = ev.target.closest('.paleta__item');
    if (item) { ev.preventDefault(); ejecutarElemento(Number(item.dataset.indice)); }
  });

  paleta.entrada.focus();
  buscarEnPaleta();
}

function cerrarPaleta() {
  if (!paleta) return;
  paleta.peticion?.abort();
  quitarConAnimacion(paleta.velo, 'cerrando');
  quitarConAnimacion(paleta.caja, 'cerrando');
  paleta = null;
}

async function buscarEnPaleta() {
  const termino = paleta.entrada.value.trim();
  paleta.peticion?.abort();
  const control = new AbortController();
  paleta.peticion = control;

  let datos;
  try {
    const url = `${BASE}/api/pacientes/buscar.php?q=${encodeURIComponent(termino)}&orden=${paleta.chip.dataset.orden}`;
    const respuesta = await fetch(url, { signal: control.signal });
    datos = await respuesta.json();
    if (!respuesta.ok) throw new Error(datos.error);
  } catch (error) {
    if (error.name === 'AbortError' || !paleta) return;
    paleta.total.textContent = '';
    paleta.lista.innerHTML = `<p class="paleta__vacio">${escaparHtml(error.message || 'No se pudo buscar.')}</p>`;
    return;
  }
  if (!paleta) return;

  // Acciones y resultados reales, en el orden en que se muestran
  const acciones = [{ tipo: 'accion', icono: 'usuarioMas', titulo: 'Registrar paciente…', url: `${BASE}/pacientes/registro.php` }];
  if (termino) {
    acciones.push({ tipo: 'accion', icono: 'filtro', titulo: `Filtrar la tabla por «${termino}»`, url: `${BASE}/pacientes/?q=${encodeURIComponent(termino)}` });
  }
  const pacientes = datos.pacientes.map((p) => ({ tipo: 'paciente', ...p, url: `${BASE}/pacientes/ver.php?id=${p.id}` }));
  const paginas = (datos.paginas || []).map((p) => ({ tipo: 'pagina', ...p, url: `${BASE}/paginas/ver.php?id=${p.id}` }));
  paleta.elementos = [...acciones, ...pacientes, ...paginas];
  paleta.activo = 0;

  paleta.total.textContent = datos.total === 1 ? '1 resultado' : `${datos.total} resultados`;

  let html = '<div class="paleta__grupo">Navegación y acciones</div>';
  html += acciones.map((a, i) => `
    <a class="paleta__item" role="option" data-indice="${i}" href="${a.url}">
      ${svgPaleta(a.icono)}<span class="paleta__titulo">${escaparHtml(a.titulo)}</span>
    </a>`).join('');
  html += '<div class="paleta__grupo">Pacientes</div>';
  html += pacientes.length
    ? pacientes.map((p, k) => `
      <a class="paleta__item" role="option" data-indice="${acciones.length + k}" href="${p.url}">
        ${svgPaleta('pagina')}
        <span class="paleta__titulo">${escaparHtml(p.nombre)}</span>
        <span class="paleta__lado"><span class="paleta__iniciales" aria-hidden="true">${escaparHtml(p.iniciales)}</span>${escaparHtml(p.hace)}</span>
        <span class="paleta__detalle">${escaparHtml(p.detalle)}</span>
      </a>`).join('')
    : `<p class="paleta__vacio">${termino ? `Ningún paciente coincide con «${escaparHtml(termino)}».` : 'Aún no hay pacientes registrados.'}</p>`;
  if (paginas.length) {
    html += '<div class="paleta__grupo">Páginas</div>';
    html += paginas.map((p, k) => `
      <a class="paleta__item" role="option" data-indice="${acciones.length + pacientes.length + k}" href="${p.url}">
        ${typeof ico === 'function' ? ico(p.icono) : svgPaleta('pagina')}
        <span class="paleta__titulo">${escaparHtml(p.titulo)}</span>
        <span class="paleta__lado">${escaparHtml(p.hace)}</span>
        <span class="paleta__detalle">${escaparHtml(p.ruta)}</span>
      </a>`).join('');
  }
  paleta.lista.innerHTML = html;
  marcarActivo(0);
}

function marcarActivo(indice) {
  if (!paleta || !paleta.elementos.length) return;
  paleta.activo = (indice + paleta.elementos.length) % paleta.elementos.length;
  paleta.lista.querySelectorAll('.paleta__item').forEach((item) => {
    const activo = Number(item.dataset.indice) === paleta.activo;
    item.classList.toggle('activo', activo);
    item.setAttribute('aria-selected', activo);
    if (activo) item.scrollIntoView({ block: 'nearest' });
  });
}

function ejecutarElemento(indice) {
  const elemento = paleta?.elementos[indice];
  if (elemento) window.location.href = elemento.url;
}

function teclasPaleta(ev) {
  if (ev.key === 'ArrowDown') { ev.preventDefault(); marcarActivo(paleta.activo + 1); }
  if (ev.key === 'ArrowUp') { ev.preventDefault(); marcarActivo(paleta.activo - 1); }
  if (ev.key === 'Enter') { ev.preventDefault(); ejecutarElemento(paleta.activo); }
  if (ev.key === 'Escape') { ev.preventDefault(); cerrarPaleta(); }
}

document.addEventListener('click', (ev) => {
  if (ev.target.closest('[data-abrir-busqueda]')) { ev.preventDefault(); abrirPaleta(); }
});
document.addEventListener('keydown', (ev) => {
  if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k' && document.body.dataset.base !== undefined
      && !document.body.classList.contains('acceso')) {
    ev.preventDefault();
    paleta ? cerrarPaleta() : abrirPaleta();
  }
});

/* ---------- Acceso: primero el correo, luego se despliega la contraseña ---------- */
function iniciarAccesoEnPasos(form) {
  if (!form) return;
  const desplegable = form.querySelector('[data-acceso-clave]');
  const boton = form.querySelector('button[type="submit"]');
  const correo = form.querySelector('#correo');
  const clave = form.querySelector('#clave');

  (desplegable.classList.contains('abierto') ? clave : correo).focus();

  form.addEventListener('submit', (ev) => {
    if (!desplegable.classList.contains('abierto')) {
      ev.preventDefault();
      if (!correo.value.trim() || !correo.checkValidity()) {
        mostrarNotificacion('error', 'Escribe un correo válido.');
        correo.focus();
        return;
      }
      desplegable.classList.add('abierto');
      boton.textContent = boton.dataset.textoFinal;
      setTimeout(() => clave.focus(), 120);
    } else if (!clave.value) {
      ev.preventDefault();
      clave.focus();
    }
  });
}

/* ---------- Código de verificación: casillas de un dígito ---------- */
function iniciarCodigo(form) {
  if (!form) return;
  const casillas = Array.from(form.querySelectorAll('.codigo__digito'));
  let enviado = false;
  casillas[0].focus();

  const marcar = (c) => c.classList.toggle('lleno', c.value !== '');
  const enviarSiCompleto = () => {
    if (!enviado && casillas.every((c) => /^\d$/.test(c.value))) {
      enviado = true;
      form.requestSubmit();
    }
  };
  // Reparte varios dígitos (pegar o autocompletar) desde la casilla indicada
  const repartir = (desde, texto) => {
    const digitos = texto.replace(/\D/g, '').split('');
    let i = desde;
    digitos.forEach((d) => { if (i < casillas.length) { casillas[i].value = d; marcar(casillas[i]); i++; } });
    casillas[Math.min(i, casillas.length - 1)].focus();
    enviarSiCompleto();
  };

  casillas.forEach((casilla, i) => {
    casilla.addEventListener('input', () => {
      const valor = casilla.value.replace(/\D/g, '');
      if (valor.length > 1) { repartir(i, valor); return; }
      casilla.value = valor;
      marcar(casilla);
      if (valor && i < casillas.length - 1) casillas[i + 1].focus();
      enviarSiCompleto();
    });
    casilla.addEventListener('keydown', (ev) => {
      if (ev.key === 'Backspace' && !casilla.value && i > 0) {
        casillas[i - 1].value = '';
        marcar(casillas[i - 1]);
        casillas[i - 1].focus();
        ev.preventDefault();
      }
      if (ev.key === 'ArrowLeft' && i > 0) casillas[i - 1].focus();
      if (ev.key === 'ArrowRight' && i < casillas.length - 1) casillas[i + 1].focus();
    });
    casilla.addEventListener('paste', (ev) => {
      ev.preventDefault();
      repartir(i, ev.clipboardData.getData('text'));
    });
    casilla.addEventListener('focus', () => casilla.select());
  });

  form.addEventListener('submit', (ev) => {
    if (!casillas.every((c) => /^\d$/.test(c.value))) {
      ev.preventDefault();
      enviado = false;
      mostrarNotificacion('error', 'Escribe los 10 dígitos del código.');
      casillas.find((c) => !c.value)?.focus();
    }
  });
}

/* ---------- Gráfica de actividad: globo con el detalle y recorrido con flechas ---------- */
function iniciarActividad(contenedor) {
  const rejilla = contenedor.querySelector('.actividad__rejilla');
  const globo = contenedor.querySelector('.actividad__globo');
  const semanas = Array.from(rejilla.querySelectorAll('.actividad__semana'));
  const dias = semanas.map((s) => Array.from(s.children));
  let posicion = null; // [semana, día]

  const mostrar = (celda) => {
    if (!celda?.dataset.texto) { globo.hidden = true; return; }
    const caja = contenedor.getBoundingClientRect();
    const r = celda.getBoundingClientRect();
    globo.textContent = celda.dataset.texto;
    globo.style.left = `${r.left - caja.left + r.width / 2}px`;
    globo.style.top = `${r.top - caja.top}px`;
    globo.hidden = false;
  };

  const enfocar = (s, d) => {
    rejilla.querySelector('.enfocado')?.classList.remove('enfocado');
    posicion = [s, d];
    const celda = dias[s][d];
    celda.classList.add('enfocado');
    rejilla.setAttribute('aria-activedescendant', celda.id || (celda.id = `dia-${s}-${d}`));
    mostrar(celda);
  };

  rejilla.addEventListener('mouseover', (ev) => {
    const celda = ev.target.closest('.actividad__dia');
    if (celda) mostrar(celda);
  });
  rejilla.addEventListener('mouseleave', () => { if (!posicion) globo.hidden = true; });

  rejilla.addEventListener('focus', () => {
    // Empieza en el día más reciente con datos (hoy)
    if (!posicion) {
      const s = semanas.length - 1;
      const d = dias[s].findLastIndex((c) => c.dataset.texto);
      enfocar(s, Math.max(0, d));
    } else {
      enfocar(...posicion);
    }
  });
  rejilla.addEventListener('blur', () => {
    rejilla.querySelector('.enfocado')?.classList.remove('enfocado');
    globo.hidden = true;
  });
  rejilla.addEventListener('keydown', (ev) => {
    if (!posicion) return;
    let [s, d] = posicion;
    if (ev.key === 'ArrowRight') s++;
    else if (ev.key === 'ArrowLeft') s--;
    else if (ev.key === 'ArrowDown') d++;
    else if (ev.key === 'ArrowUp') d--;
    else return;
    ev.preventDefault();
    // Solo días existentes (no futuros)
    if (dias[s]?.[d]?.dataset.texto) enfocar(s, d);
  });
}

/* ---------- Barra de pestañas ----------
   Las pestañas viven en sessionStorage (se borran al cerrar el navegador o la sesión),
   porque sus títulos contienen nombres de pacientes. */
const CLAVE_PESTANAS = `femeci-pestanas-${document.body.dataset.usuario ?? ''}`;
const MAX_PESTANAS = 10;

function leerPestanas() {
  try { return JSON.parse(sessionStorage.getItem(CLAVE_PESTANAS)) ?? null; } catch (e) { return null; }
}
function guardarPestanas(estado) {
  try { sessionStorage.setItem(CLAVE_PESTANAS, JSON.stringify(estado)); } catch (e) { /* sin almacenamiento */ }
}
const nuevoId = () => Math.random().toString(36).slice(2, 10);

function iniciarPestanas() {
  const lista = document.querySelector('[data-pestanas-lista]');
  if (!lista) return;

  const actual = { url: location.pathname + location.search, titulo: document.body.dataset.titulo || document.title };
  let estado = leerPestanas();
  if (!estado?.pestanas?.length) {
    estado = { pestanas: [{ id: nuevoId(), ...actual }], activa: null };
    estado.activa = estado.pestanas[0].id;
  } else {
    // La navegación dentro de una pestaña actualiza esa pestaña
    const activa = estado.pestanas.find((p) => p.id === estado.activa) ?? estado.pestanas[0];
    Object.assign(activa, actual);
    estado.activa = activa.id;
  }
  guardarPestanas(estado);

  const ir = (pestana) => {
    estado.activa = pestana.id;
    guardarPestanas(estado);
    if (pestana.url !== location.pathname + location.search) location.href = pestana.url;
    else pintar();
  };

  const pintar = () => {
    lista.innerHTML = '';
    estado.pestanas.forEach((p) => {
      const el = document.createElement('div');
      el.className = 'pestana' + (p.id === estado.activa ? ' activa' : '');
      el.setAttribute('role', 'tab');
      el.setAttribute('aria-selected', p.id === estado.activa);
      el.tabIndex = 0;
      el.title = p.titulo;
      el.innerHTML = `<svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>
        <span class="pestana__titulo"></span>
        <button type="button" class="pestana__cerrar" aria-label="Cerrar pestaña"><svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>`;
      el.querySelector('.pestana__titulo').textContent = p.titulo;
      el.addEventListener('click', (ev) => {
        if (ev.target.closest('.pestana__cerrar')) { cerrarPestana(p.id); return; }
        ir(p);
      });
      el.addEventListener('auxclick', (ev) => { if (ev.button === 1) cerrarPestana(p.id); }); // clic central cierra
      el.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); ir(p); } });
      lista.appendChild(el);
    });
    lista.querySelector('.activa')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    pintarLateral();
  };

  // Las mismas pestañas, listadas en la barra lateral ("Pestañas abiertas")
  const lateral = document.querySelector('[data-lateral-pestanas]');
  const pintarLateral = () => {
    if (!lateral) return;
    lateral.innerHTML = '';
    estado.pestanas.forEach((p) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'lateral-pestana' + (p.id === estado.activa ? ' activa' : '');
      b.title = p.titulo;
      b.innerHTML = '<svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/></svg><span></span>';
      b.querySelector('span').textContent = p.titulo;
      b.addEventListener('click', () => ir(p));
      lateral.appendChild(b);
    });
  };

  const cerrarPestana = (id) => {
    const i = estado.pestanas.findIndex((p) => p.id === id);
    if (i < 0) return;
    const eraActiva = estado.activa === id;
    recordarEnLista('cerradas', estado.pestanas[i], 10);
    estado.pestanas.splice(i, 1);
    if (!estado.pestanas.length) {
      estado.pestanas.push({ id: nuevoId(), url: `${BASE}/pacientes/`, titulo: 'Pacientes' });
    }
    if (eraActiva) {
      ir(estado.pestanas[Math.min(i, estado.pestanas.length - 1)]);
    } else {
      guardarPestanas(estado);
      pintar();
    }
  };

  const abrirNueva = () => {
    if (estado.pestanas.length >= MAX_PESTANAS) {
      mostrarNotificacion('error', `Puedes tener hasta ${MAX_PESTANAS} pestañas abiertas.`);
      return;
    }
    const p = { id: nuevoId(), url: `${BASE}/pacientes/`, titulo: 'Pacientes' };
    estado.pestanas.push(p);
    ir(p);
  };

  document.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-pestanas-nueva]')) { ev.preventDefault(); abrirNueva(); }
  });

  // Atrás / adelante con el historial real del navegador
  const atras = document.querySelector('[data-pestanas-atras]');
  const adelante = document.querySelector('[data-pestanas-adelante]');
  atras.addEventListener('click', () => history.back());
  adelante.addEventListener('click', () => history.forward());
  if (window.navigation && 'canGoBack' in window.navigation) {
    atras.disabled = !navigation.canGoBack;
    adelante.disabled = !navigation.canGoForward;
  }

  // Al cerrar sesión se olvidan las pestañas
  document.querySelector('.barra-lateral__usuario')?.addEventListener('submit', () => {
    try { sessionStorage.removeItem(CLAVE_PESTANAS); } catch (e) { /* sin almacenamiento */ }
  });

  // Al renombrar la página abierta, su pestaña cambia de título
  window.renombrarPestanaActual = (titulo) => {
    const activa = estado.pestanas.find((p) => p.id === estado.activa);
    if (!activa) return;
    activa.titulo = titulo;
    guardarPestanas(estado);
    pintar();
  };

  pintar();
}

/* ---------- Barra lateral: grupos, historial y pestañas cerradas recientemente ----------
   Historial y cerradas viven en sessionStorage (como las pestañas) y se borran al cerrar sesión. */
const claveLista = (nombre) => `femeci-${nombre}-${document.body.dataset.usuario ?? ''}`;
function leerLista(nombre) {
  try { return JSON.parse(sessionStorage.getItem(claveLista(nombre))) || []; } catch (e) { return []; }
}
function recordarEnLista(nombre, entrada, maximo) {
  if (!entrada) return;
  const lista = leerLista(nombre).filter((x) => x.url !== entrada.url);
  lista.unshift({ url: entrada.url, titulo: entrada.titulo, cuando: Date.now() });
  try { sessionStorage.setItem(claveLista(nombre), JSON.stringify(lista.slice(0, maximo))); } catch (e) { /* lleno */ }
}

function iniciarBarraLateral() {
  if (!document.querySelector('.barra-lateral__desplazable')) return;
  // Cada página visitada entra al historial de la sesión
  recordarEnLista('historial', { url: location.pathname + location.search, titulo: document.body.dataset.titulo || document.title }, 20);

  document.querySelectorAll('.lateral-grupo__titulo').forEach((b) => b.addEventListener('click', () => {
    b.setAttribute('aria-expanded', String(b.getAttribute('aria-expanded') !== 'true'));
  }));

  const popover = document.querySelector('[data-lateral-popover]');
  const botones = document.querySelectorAll('[data-lateral-lista]');
  const cerrar = () => { popover.hidden = true; botones.forEach((b) => b.setAttribute('aria-expanded', 'false')); };
  const hace = (ms) => {
    const min = Math.round((Date.now() - ms) / 60000);
    return min < 1 ? 'ahora' : min < 60 ? `hace ${min} min` : `hace ${Math.round(min / 60)} h`;
  };
  botones.forEach((b) => b.addEventListener('click', (ev) => {
    ev.stopPropagation();
    const nombre = b.dataset.lateralLista;
    if (!popover.hidden && popover.dataset.lista === nombre) { cerrar(); return; }
    cerrar();
    popover.dataset.lista = nombre;
    if (nombre === 'papelera') {
      popover.hidden = false;
      b.setAttribute('aria-expanded', 'true');
      window.pintarPapelera?.(popover);
      return;
    }
    // "Recientes": lo visitado en la sesión y, debajo, las pestañas cerradas que se pueden reabrir
    const visitadas = leerLista('historial').filter((x) => x.url !== location.pathname + location.search).slice(0, 8);
    const cerradas = leerLista('cerradas').slice(0, 6);
    popover.dataset.lista = nombre;
    popover.innerHTML = (!visitadas.length && !cerradas.length)
      ? '<div class="lateral-popover__vacio">Aún no hay páginas recientes.</div>'
      : (visitadas.length ? '<div class="lateral-popover__titulo">Visitado en esta sesión</div>'
          + visitadas.map((x) => `<a role="menuitem" href="${escaparHtml(x.url)}"><span>${escaparHtml(x.titulo)}</span><small>${hace(x.cuando)}</small></a>`).join('') : '')
        + (cerradas.length ? '<div class="lateral-popover__titulo">Pestañas cerradas</div>'
          + cerradas.map((x, i) => `<button type="button" role="menuitem" data-reabrir="${i}"><span>${escaparHtml(x.titulo)}</span><small>${hace(x.cuando)}</small></button>`).join('') : '');
    popover.hidden = false;
    b.setAttribute('aria-expanded', 'true');
  }));
  popover.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-reabrir]');
    if (!b) return;
    // Reabrir: vuelve como pestaña nueva y sale de la lista de cerradas
    const lista = leerLista('cerradas');
    const [p] = lista.splice(Number(b.dataset.reabrir), 1);
    try { sessionStorage.setItem(claveLista('cerradas'), JSON.stringify(lista)); } catch (e) { /* lleno */ }
    const estado = leerPestanas();
    if (estado && p) {
      const nueva = { id: nuevoId(), url: p.url, titulo: p.titulo };
      estado.pestanas.push(nueva);
      estado.activa = nueva.id;
      guardarPestanas(estado);
    }
    if (p) location.href = p.url;
  });
  document.addEventListener('click', (ev) => { if (!ev.target.closest('.lateral-desplegable')) cerrar(); });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrar(); });
  document.querySelector('.barra-lateral__usuario')?.addEventListener('submit', () => {
    try { ['historial', 'cerradas'].forEach((n) => sessionStorage.removeItem(claveLista(n))); } catch (e) { /* opcional */ }
  });
}

/* ---------- Barra de ruta: Compartir (copiar enlace), menú "…", imprimir ---------- */
function iniciarBarraRuta() {
  document.querySelector('[data-compartir]')?.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(location.href);
      mostrarNotificacion('exito', 'Enlace copiado. Solo lo abren usuarios con sesión iniciada.');
    } catch (e) {
      mostrarNotificacion('error', 'No se pudo copiar el enlace.');
    }
  });

  const boton = document.querySelector('[data-menu-mas]');
  const menu = document.querySelector('.menu-mas__lista');
  if (!boton || !menu) return;
  const cerrar = () => { menu.hidden = true; boton.setAttribute('aria-expanded', 'false'); };
  boton.addEventListener('click', (ev) => {
    ev.stopPropagation();
    menu.hidden = !menu.hidden;
    boton.setAttribute('aria-expanded', String(!menu.hidden));
    if (!menu.hidden) menu.querySelector('button')?.focus();
  });
  menu.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-imprimir]')) window.print();
    cerrar();
  });
  document.addEventListener('click', (ev) => { if (!ev.target.closest('.menu-mas')) cerrar(); });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrar(); });
}

/* ---------- Mapa de México: selector de entidad, globo, ranking y lista de pacientes ---------- */
function iniciarMapa(seccion) {
  const datos = JSON.parse(document.getElementById('geo-datos').textContent);
  const boton = seccion.querySelector('[data-geo-boton]');
  const opciones = seccion.querySelector('[data-geo-opciones]');
  const nombre = seccion.querySelector('[data-geo-nombre]');
  const numero = seccion.querySelector('[data-geo-numero]');
  const nota = seccion.querySelector('[data-geo-nota]');
  const contenedorMapa = seccion.querySelector('[data-geo-mapa]');
  const globo = contenedorMapa.querySelector('.mapa__globo');
  const estados = contenedorMapa.querySelectorAll('.mapa__estado');
  const lista = seccion.querySelector('[data-geo-lista]');
  const verTodos = seccion.querySelector('[data-geo-todos]');
  const bloquePacientes = seccion.querySelector('[data-geo-pacientes]');
  const notaGeneral = nota.textContent;

  const seleccionar = (clave) => {
    estados.forEach((e) => {
      e.classList.toggle('resaltada', !!clave && e.dataset.entidad === clave);
      e.classList.toggle('atenuada', !!clave && e.dataset.entidad !== clave);
    });
    opciones.querySelectorAll('li').forEach((li) => li.setAttribute('aria-selected', String(li.dataset.entidad === (clave || ''))));
    if (!clave) {
      nombre.textContent = 'México';
      numero.textContent = datos.total;
      nota.textContent = notaGeneral;
      bloquePacientes.hidden = true;
      return;
    }
    const pacientes = datos.pacientes[clave] || [];
    nombre.textContent = datos.nombres[clave];
    numero.textContent = pacientes.length;
    nota.textContent = `(en ${datos.nombres[clave]})`;
    bloquePacientes.hidden = false;
    bloquePacientes.querySelector('[data-geo-pacientes-titulo]').textContent = `Pacientes en ${datos.nombres[clave]}`;
    const ul = bloquePacientes.querySelector('[data-geo-pacientes-lista]');
    ul.innerHTML = pacientes.length
      ? pacientes.map((p) => `<li><a href="${BASE}/pacientes/ver.php?id=${p.id}"><svg class="icono" viewBox="0 0 24 24" aria-hidden="true">${ICONOS_PALETA.pagina}</svg>${escaparHtml(p.nombre)}</a></li>`).join('')
      : '<li class="geo__vacio">Ningún paciente registrado con domicilio en esta entidad.</li>';
  };

  const cerrarOpciones = () => { opciones.hidden = true; boton.setAttribute('aria-expanded', 'false'); };
  boton.addEventListener('click', (ev) => {
    ev.stopPropagation();
    opciones.hidden = !opciones.hidden;
    boton.setAttribute('aria-expanded', String(!opciones.hidden));
    if (!opciones.hidden) opciones.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
  });
  opciones.addEventListener('click', (ev) => {
    const li = ev.target.closest('li[data-entidad]');
    if (!li) return;
    seleccionar(li.dataset.entidad || null);
    cerrarOpciones();
  });
  document.addEventListener('click', (ev) => { if (!ev.target.closest('.geo__selector')) cerrarOpciones(); });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrarOpciones(); });

  // Mapa: globo al pasar el cursor; clic selecciona (otro clic en la misma entidad vuelve a "México")
  contenedorMapa.addEventListener('mousemove', (ev) => {
    const estado = ev.target.closest('.mapa__estado');
    if (!estado) { globo.hidden = true; return; }
    const caja = contenedorMapa.getBoundingClientRect();
    globo.textContent = estado.dataset.texto;
    globo.style.left = `${ev.clientX - caja.left}px`;
    globo.style.top = `${ev.clientY - caja.top}px`;
    globo.hidden = false;
  });
  contenedorMapa.addEventListener('mouseleave', () => { globo.hidden = true; });
  contenedorMapa.addEventListener('click', (ev) => {
    const estado = ev.target.closest('.mapa__estado');
    if (!estado) return;
    seleccionar(estado.classList.contains('resaltada') ? null : estado.dataset.entidad);
  });

  lista.addEventListener('click', (ev) => {
    const li = ev.target.closest('li[data-entidad]');
    if (li) seleccionar(li.dataset.entidad);
  });
  verTodos.addEventListener('click', () => {
    const todos = lista.classList.toggle('todos');
    verTodos.setAttribute('aria-expanded', String(todos));
    verTodos.childNodes.forEach((n) => { if (n.nodeType === 3 && n.textContent.trim()) n.textContent = todos ? ' Ver los 5 principales ' : ' Ver todos los estados '; });
  });
}

/* ---------- Transcriptor por voz (Whisper local + extractor de campos) ----------
   Graba con el micrófono, envía el audio acumulado cada pocos segundos al servidor local y va
   llenando el formulario. Nunca sobrescribe lo que el usuario escribió a mano. */
function iniciarDictado(raiz) {
  const form = document.getElementById('form-paciente');
  if (!form) return;
  const panel = raiz.querySelector('[data-dictado-panel]');
  const texto = raiz.querySelector('[data-dictado-texto]');
  const estadoTexto = raiz.querySelector('[data-dictado-estado]');
  const calificar = raiz.querySelector('[data-dictado-calificar]');
  const boton = raiz.querySelector('[data-dictado-boton]');
  const globo = raiz.querySelector('[data-dictado-globo]');
  const puntos = Array.from(raiz.querySelectorAll('[data-dictado-puntos] i'));
  const MAX_SEGUNDOS = 180;
  const INTERVALO_ENVIO = 5000;

  let grabadora = null, flujo = null, contexto = null, analizador = null, cuadro = null;
  let trozos = [], tipo = '', inicio = 0, reloj = null, enVuelo = false, finalPendiente = false;
  let descartar = false, duracion = 0;
  const llenados = new Set();

  const ponerEstado = (estado) => {
    raiz.dataset.estado = estado;
    const etiqueta = { inactivo: 'Dictar', grabando: 'Terminar', procesando: 'Procesando…' }[estado];
    globo.textContent = etiqueta;
    boton.setAttribute('aria-label', etiqueta);
    boton.disabled = estado === 'procesando';
  };
  const mostrarPanel = () => { panel.hidden = false; panel.classList.remove('saliendo'); };
  const ocultarPanel = () => { if (!panel.hidden) { panel.classList.add('saliendo'); setTimeout(() => { panel.hidden = true; }, 160); } };
  const segundos = () => Math.round((Date.now() - inicio) / 1000);

  // Lo que el usuario escribe a mano ya no lo toca el dictado
  form.addEventListener('input', (ev) => { if (ev.isTrusted) delete ev.target.dataset.dictado; });
  form.addEventListener('change', (ev) => { if (ev.isTrusted) delete ev.target.dataset.dictado; });

  const nivelesDeAudio = () => {
    const datos = new Uint8Array(analizador.fftSize);
    const pintar = () => {
      analizador.getByteTimeDomainData(datos);
      let suma = 0;
      for (const v of datos) suma += ((v - 128) / 128) ** 2;
      const nivel = Math.min(1, Math.sqrt(suma / datos.length) * 6);
      puntos.forEach((p, i) => {
        const forma = [0.55, 0.85, 1, 0.85, 0.55][i];
        p.style.height = `${3 + nivel * 16 * forma * (0.75 + Math.random() * 0.5)}px`;
      });
      cuadro = requestAnimationFrame(pintar);
    };
    pintar();
  };

  const aplicar = (datos) => {
    texto.textContent = datos.texto || '';
    texto.scrollTop = texto.scrollHeight;
    Object.entries(datos.campos || {}).forEach(([nombre, valor]) => {
      const campo = form.elements[nombre];
      if (!campo || (campo.value && !campo.dataset.dictado)) return;   // respeta lo escrito a mano
      if (campo.tagName === 'SELECT' && ![...campo.options].some((o) => o.value === valor)) return;
      if (campo.value === valor) return;
      campo.value = valor;
      campo.dataset.dictado = '1';
      campo.dispatchEvent(new Event('input', { bubbles: true }));
      campo.dispatchEvent(new Event('change', { bubbles: true }));
      const contenedor = campo.closest('.campo');
      contenedor?.classList.remove('campo--dictado');
      void contenedor?.offsetWidth;
      contenedor?.classList.add('campo--dictado');
      llenados.add(nombre);
    });
  };

  const enviar = async (final) => {
    if (enVuelo) { if (final) finalPendiente = true; return; }
    if (!trozos.length) { if (final) terminar(); return; }
    enVuelo = true;
    const datos = new FormData();
    datos.append('audio', new Blob(trozos, { type: tipo }), 'dictado.webm');
    try {
      const respuesta = await fetch(`${BASE}/api/pacientes/dictado.php`, {
        method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos,
      });
      const resultado = await respuesta.json();
      if (!resultado.ok) throw new Error(resultado.error);
      if (!descartar) aplicar(resultado);
      if (resultado.duracion) duracion = resultado.duracion;
    } catch (error) {
      if (final || !texto.textContent) mostrarNotificacion('error', error.message || 'No se pudo transcribir el audio.');
    } finally {
      enVuelo = false;
      if (finalPendiente) { finalPendiente = false; enviar(true); } else if (final) terminar();
    }
  };

  const terminar = () => {
    ponerEstado('inactivo');
    if (descartar) return;
    estadoTexto.textContent = `Dictado terminado ${Math.round(duracion || segundos())} s`;
    calificar.hidden = false;
    calificar.querySelectorAll('button').forEach((b) => { b.disabled = false; b.setAttribute('aria-pressed', 'false'); });
    if (llenados.size) mostrarNotificacion('exito', `Se llenaron ${llenados.size} campos. Revísalos antes de guardar.`);
  };

  const liberar = () => {
    cancelAnimationFrame(cuadro);
    puntos.forEach((p) => { p.style.height = ''; });
    flujo?.getTracks().forEach((t) => t.stop());
    contexto?.close();
    clearInterval(reloj);
    flujo = contexto = null;
  };

  const iniciar = async () => {
    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
      mostrarNotificacion('error', 'Este navegador no permite grabar audio. Abre el expediente desde localhost o con HTTPS.');
      return;
    }
    try {
      flujo = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true } });
    } catch (error) {
      mostrarNotificacion('error', 'No se pudo usar el micrófono. Revisa el permiso del navegador.');
      return;
    }
    tipo = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm', 'audio/mp4'].find((t) => MediaRecorder.isTypeSupported(t)) || '';
    grabadora = new MediaRecorder(flujo, tipo ? { mimeType: tipo, audioBitsPerSecond: 32000 } : undefined);
    tipo = grabadora.mimeType || 'audio/webm';
    trozos = [];
    descartar = false;
    duracion = 0;
    llenados.clear();
    grabadora.ondataavailable = (ev) => { if (ev.data.size) trozos.push(ev.data); };
    grabadora.onstop = () => { liberar(); if (!descartar) { ponerEstado('procesando'); enviar(true); } };
    grabadora.start(1000);

    contexto = new AudioContext();
    analizador = contexto.createAnalyser();
    analizador.fftSize = 512;
    contexto.createMediaStreamSource(flujo).connect(analizador);
    nivelesDeAudio();

    inicio = Date.now();
    texto.textContent = '';
    calificar.hidden = true;
    estadoTexto.textContent = 'Escuchando 0 s';
    ponerEstado('grabando');
    mostrarPanel();
    reloj = setInterval(() => {
      const s = segundos();
      estadoTexto.textContent = `Escuchando ${s} s`;
      if (s >= MAX_SEGUNDOS) detener();
      else if (s > 0 && s % (INTERVALO_ENVIO / 1000) === 0) enviar(false);
    }, 1000);
  };

  const detener = () => { if (grabadora?.state === 'recording') grabadora.stop(); };

  const cerrar = () => {
    descartar = true;
    if (grabadora?.state === 'recording') grabadora.stop(); else liberar();
    ponerEstado('inactivo');
    ocultarPanel();
  };

  boton.addEventListener('click', () => {
    if (raiz.dataset.estado === 'inactivo') iniciar();
    else if (raiz.dataset.estado === 'grabando') detener();
  });
  raiz.querySelector('[data-dictado-expandir]').addEventListener('click', () => (panel.hidden ? mostrarPanel() : ocultarPanel()));
  raiz.querySelector('[data-dictado-minimizar]').addEventListener('click', ocultarPanel);
  raiz.querySelector('[data-dictado-cerrar]').addEventListener('click', cerrar);
  raiz.querySelector('[data-dictado-nuevo]').addEventListener('click', () => { if (raiz.dataset.estado === 'inactivo') iniciar(); });

  calificar.addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-calificacion]');
    if (!b) return;
    calificar.querySelectorAll('button').forEach((x) => { x.disabled = true; x.setAttribute('aria-pressed', String(x === b)); });
    try {
      await fetch(`${BASE}/api/pacientes/dictado_calificar.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
        body: JSON.stringify({ util: b.dataset.calificacion === '1', duracion, campos: llenados.size }),
      });
      mostrarNotificacion('exito', 'Gracias, tu calificación ayuda a mejorar el dictado.');
    } catch (e) {
      mostrarNotificacion('error', 'No se pudo guardar la calificación.');
    }
  });

  // Mostrar y empezar a dictar con Alt+Shift+D (o el botón "Dictar"); otra vez lo termina
  const mostrarYDictar = () => {
    if (raiz.hidden) {
      raiz.hidden = false;
      raiz.classList.add('apareciendo');
    }
    boton.click();
  };
  document.addEventListener('keydown', (ev) => {
    if (ev.altKey && ev.shiftKey && ev.key.toLowerCase() === 'd') {
      ev.preventDefault();
      mostrarYDictar();
    }
  });
  document.querySelector('[data-dictado-mostrar]')?.addEventListener('click', mostrarYDictar);
  // Cerrar el panel sin estar grabando oculta todo el transcriptor
  raiz.querySelector('[data-dictado-cerrar]')?.addEventListener('click', () => {
    if (raiz.dataset.estado === 'inactivo') setTimeout(() => { raiz.hidden = true; }, 170);
  });
  window.addEventListener('beforeunload', () => { descartar = true; liberar(); });
}

/* ---------- Historial clínico del perfil: filtros y formulario para agregar ---------- */
function iniciarHistorial(seccion) {
  // Filtro por tipo
  const filtros = seccion.querySelectorAll('[data-filtro]');
  filtros.forEach((b) => b.addEventListener('click', () => {
    filtros.forEach((x) => { x.classList.toggle('activo', x === b); x.setAttribute('aria-selected', String(x === b)); });
    seccion.querySelectorAll('.historial__evento').forEach((ev) => { ev.hidden = !!b.dataset.filtro && ev.dataset.tipo !== b.dataset.filtro; });
  }));

  const abrir = seccion.querySelector('[data-historial-abrir]');
  const form = seccion.querySelector('[data-historial-form]');
  if (!form) return;
  const mostrar = (visible) => {
    form.hidden = !visible;
    abrir.setAttribute('aria-expanded', String(visible));
    if (visible) form.querySelector('fieldset:not([disabled]) input, fieldset:not([disabled]) textarea')?.focus();
  };
  abrir.addEventListener('click', () => mostrar(true));
  seccion.querySelector('[data-historial-cancelar]').addEventListener('click', () => mostrar(false));

  // Cambiar el tipo: solo el grupo visible participa en la validación y en el envío
  const tipo = form.querySelector('[data-historial-tipo]');
  form.querySelectorAll('[data-tipo-form]').forEach((b) => b.addEventListener('click', () => {
    tipo.value = b.dataset.tipoForm;
    form.querySelectorAll('[data-tipo-form]').forEach((x) => { x.classList.toggle('activo', x === b); x.setAttribute('aria-selected', String(x === b)); });
    form.querySelectorAll('[data-grupo]').forEach((g) => {
      const activo = g.dataset.grupo === tipo.value;
      g.hidden = !activo;
      g.disabled = !activo;
    });
    actualizarFechasVacias();
  }));

  // Otro renglón de medicamento (vacío; los renglones vacíos se ignoran al guardar)
  seccion.querySelector('[data-medicamento-otro]')?.addEventListener('click', () => {
    const caja = form.querySelector('[data-medicamentos]');
    const nuevo = caja.firstElementChild.cloneNode(true);
    nuevo.querySelectorAll('input').forEach((i) => { i.value = ''; i.required = false; });
    nuevo.querySelectorAll('select').forEach((s) => { s.value = ''; s.required = false; });
    caja.appendChild(nuevo);
    nuevo.querySelector('input').focus();
  });

  form.addEventListener('submit', (ev) => {
    if (!form.checkValidity()) {
      ev.preventDefault();
      form.reportValidity();
    }
  });
  if (location.hash === '#historial' && !document.querySelector('.notificacion--error')) seccion.scrollIntoView({ block: 'start' });
  if (document.querySelector('.notificacion--error') && location.hash === '#historial') mostrar(true);
}

/* ---------- Foto de la clínica (barra lateral; solo administrador) ---------- */
function iniciarFotoClinica() {
  const entrada = document.querySelector('[data-clinica-archivo]');
  if (!entrada) return;
  entrada.addEventListener('change', async () => {
    const archivo = entrada.files[0];
    if (!archivo) return;
    const caja = entrada.closest('[data-clinica]');
    caja.classList.add('subiendo');
    const datos = new FormData();
    datos.append('foto', archivo);
    try {
      const respuesta = await fetch(`${BASE}/api/configuracion/foto_clinica.php`, {
        method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos,
      });
      const resultado = await respuesta.json();
      if (!resultado.ok) throw new Error(resultado.error);
      let img = caja.querySelector('[data-clinica-imagen]');
      if (!img) {
        img = document.createElement('img');
        img.alt = 'Foto de la clínica';
        img.dataset.clinicaImagen = '';
        caja.prepend(img);
      }
      img.src = resultado.url;
      caja.classList.remove('clinica--vacia');
      caja.querySelector('.clinica__editar span').textContent = 'Cambiar foto';
      mostrarNotificacion('exito', 'Foto de la clínica actualizada.');
    } catch (error) {
      mostrarNotificacion('error', error.message || 'No se pudo subir la foto.');
    } finally {
      caja.classList.remove('subiendo');
      entrada.value = '';
    }
  });
}

/* ---------- Asistente de IA ---------- */
// Markdown mínimo y seguro: primero se escapa el HTML y luego se dan formatos
/** Respuestas del asistente: Markdown seguro (assets/js/markdown.js). */
function markdownSeguro(texto) {
  return Markdown.render(texto);
}

// Las gráficas ya dibujadas se reutilizan mientras llega el texto (no se repite la animación en cada fragmento)
const graficasDibujadas = new Map();
/** Convierte los bloques ```grafica``` ya renderizados en gráficas interactivas. */
function montarGraficas(raiz) {
  if (typeof Graficas === 'undefined') return;
  raiz.querySelectorAll('[data-md-grafica]').forEach((caja) => {
    const clave = caja.dataset.mdGrafica;
    let seccion = graficasDibujadas.get(clave);
    if (!seccion || (seccion.isConnected && !raiz.contains(seccion))) {
      let def = null;
      try { def = JSON.parse(clave); } catch (e) { return; }
      seccion = Graficas.montar(caja, def);
      if (!seccion) { caja.textContent = 'La gráfica no tiene datos válidos.'; return; }
      graficasDibujadas.set(clave, seccion);
      if (graficasDibujadas.size > 60) graficasDibujadas.delete(graficasDibujadas.keys().next().value);
    } else {
      caja.appendChild(seccion);
    }
  });
}

// Carga el motor de la bolita aurora solo cuando se abre el asistente
let promesaAurora = null;
function cargarAurora() {
  if (promesaAurora) return promesaAurora;
  const scripts = ['vendor/aurora/react-minimo.js', 'vendor/aurora/paper-shaders.js', 'vendor/aurora/disenos.js', 'js/razonamiento.js'];
  promesaAurora = scripts.reduce((cadena, ruta) => cadena.then(() => new Promise((ok) => {
    const s = document.createElement('script');
    s.src = `${BASE}/assets/${ruta}${VERSION_APP ? `?v=${VERSION_APP}` : ""}`;
    s.onload = ok;
    s.onerror = ok;   // sin el motor, razonamiento.js usa la bolita CSS
    document.head.appendChild(s);
  })), Promise.resolve());
  return promesaAurora;
}

function iniciarAsistente() {
  const panel = document.querySelector('[data-asistente]');
  if (!panel) return;
  const enPagina = panel.dataset.modo === 'pagina';
  const boton = document.querySelector('[data-asistente-abrir]');
  const conversacion = panel.querySelector('[data-asistente-conversacion]');
  const marco = panel.querySelector('[data-asistente-marco]');
  const vacio = panel.querySelector('[data-asistente-vacio]');
  const form = panel.querySelector('[data-asistente-form]');
  const texto = panel.querySelector('[data-entrada-texto]');
  const enviar = panel.querySelector('[data-entrada-enviar]');
  const info = panel.querySelector('[data-asistente-info]');
  const ajustes = panel.querySelector('[data-asistente-panel-ajustes]');
  const adjuntosCaja = panel.querySelector('[data-entrada-adjuntos]');
  const chipHerramienta = panel.querySelector('[data-herramienta-actual]');
  const raizHtml = document.documentElement;
  const CLAVE = `femeci-asistente-${document.body.dataset.usuario ?? ''}`;
  const pacienteId = document.body.dataset.paciente ? Number(document.body.dataset.paciente) : null;
  const paginaId = document.body.dataset.pagina ? Number(document.body.dataset.pagina) : null;

  let estado = { configurado: false };
  let chats = [];
  let chatActual = null;
  let adjuntos = [];
  // Por seguridad, cada página empieza con acceso restringido y sin herramienta
  const opciones = { acceso: 'restringido', herramienta: 'ninguna', esfuerzo: 'medium' };
  let peticion = null;

  /* --- Conversaciones guardadas en la sesión del navegador (se borran al cerrar sesión) --- */
  try {
    const guardado = JSON.parse(sessionStorage.getItem(CLAVE) || 'null');
    if (guardado?.chats) { chats = guardado.chats; chatActual = guardado.actual; opciones.esfuerzo = guardado.esfuerzo || 'medium'; }
  } catch (e) { /* sin almacenamiento */ }
  const chat = () => chats.find((c) => c.id === chatActual) || null;
  const historial = () => chat()?.historial ?? [];
  const guardar = () => {
    chats = chats.filter((c) => c.historial.length).slice(0, 20);
    try { sessionStorage.setItem(CLAVE, JSON.stringify({ chats, actual: chatActual, esfuerzo: opciones.esfuerzo })); } catch (e) { /* lleno */ }
  };
  const tituloChat = panel.querySelector('[data-chat-titulo]');
  const pintarTitulo = () => { tituloChat.textContent = chat()?.titulo || 'Nuevo chat con la IA'; };

  /* --- Abrir, cerrar y cambiar el ancho (panel) --- */
  try {
    const ancho = Number(localStorage.getItem('asistente-ancho'));
    if (ancho >= 360) raizHtml.style.setProperty('--ancho-asistente', `${ancho}px`);
  } catch (e) { /* preferencia opcional */ }

  let iniciado = false;
  const preparar = async () => {
    await cargarAurora();
    if (iniciado) return;
    iniciado = true;
    await cargarEstado();
    pintarHistorial();
  };
  const abrir = async () => {
    if (!document.querySelector('[data-panel-actividad]')?.hidden) document.querySelector('[data-panel-cerrar]')?.click();
    panel.hidden = false;
    raizHtml.classList.add('asistente-abierto');
    try { sessionStorage.setItem(`${CLAVE}-abierto`, '1'); } catch (e) { /* opcional */ }
    texto.focus();
    await preparar();
  };
  const cerrar = () => {
    panel.hidden = true;
    raizHtml.classList.remove('asistente-abierto');
    try { sessionStorage.removeItem(`${CLAVE}-abierto`); } catch (e) { /* opcional */ }
  };
  if (enPagina) {
    preparar();
    texto.focus();
  } else {
    boton.addEventListener('click', abrir);
    panel.querySelector('[data-asistente-cerrar]').addEventListener('click', cerrar);
    try { if (sessionStorage.getItem(`${CLAVE}-abierto`)) abrir(); } catch (e) { /* opcional */ }

    const tirador = panel.querySelector('[data-asistente-tirador]');
    const fijarAncho = (px) => {
      const ancho = Math.round(Math.min(Math.max(px, 360), window.innerWidth * 0.6));
      raizHtml.style.setProperty('--ancho-asistente', `${ancho}px`);
      try { localStorage.setItem('asistente-ancho', String(ancho)); } catch (e) { /* opcional */ }
    };
    tirador.addEventListener('pointerdown', (ev) => {
      ev.preventDefault();
      tirador.setPointerCapture(ev.pointerId);
      raizHtml.classList.add('asistente-redimensionando');
      const derecha = panel.getBoundingClientRect().right;
      const mover = (e) => fijarAncho(derecha - e.clientX);
      const soltar = () => {
        raizHtml.classList.remove('asistente-redimensionando');
        tirador.removeEventListener('pointermove', mover);
        tirador.removeEventListener('pointerup', soltar);
      };
      tirador.addEventListener('pointermove', mover);
      tirador.addEventListener('pointerup', soltar);
    });
    tirador.addEventListener('keydown', (ev) => {
      const actual = panel.getBoundingClientRect().width;
      if (ev.key === 'ArrowLeft') { ev.preventDefault(); fijarAncho(actual + 24); }
      if (ev.key === 'ArrowRight') { ev.preventDefault(); fijarAncho(actual - 24); }
    });
  }

  /* --- Estado y configuración del proveedor --- */
  async function cargarEstado() {
    try {
      estado = await (await fetch(`${BASE}/api/asistente/configuracion.php`)).json();
    } catch (e) { estado = { configurado: false }; }
    panel.querySelector('[data-entrada-modelo]').textContent = estado.configurado ? `Modelo: ${estado.modelo}` : 'Sin modelo configurado';
    info.textContent = estado.configurado
      ? (estado.local ? 'Modelo local · nada sale del equipo' : '')
      : (estado.es_admin ? 'Sin configurar · abre la configuración' : 'Sin configurar');
    panel.querySelector('[data-asistente-ajustes]').hidden = !estado.es_admin;
    if (!iniciado && estado.esfuerzo_defecto && !sessionStorage.getItem(CLAVE)) opciones.esfuerzo = estado.esfuerzo_defecto;
    if (!estado.configurado && estado.es_admin && !historial().length) mostrarAjustes();
  }

  /* --- Configuración: la clave detecta el proveedor y carga sus modelos --- */
  const formAjustes = panel.querySelector('[data-ajustes-form]');
  const selectServicio = panel.querySelector('[data-ajuste-servicio]');
  const campoModelo = panel.querySelector('[data-ajuste-modelo]');
  const listaModelos = panel.querySelector('[data-lista-modelos]');
  const detectado = panel.querySelector('[data-ajuste-detectado]');
  const resultado = panel.querySelector('[data-ajustes-resultado]');
  const botonServicio = panel.querySelector('[data-ajuste-servicio-boton]');
  const listaServicios = panel.querySelector('[data-ajuste-servicios]');
  const cuentaModelos = panel.querySelector('[data-ajuste-cuenta]');
  let modelos = [];
  let esfuerzoAjuste = 'medium';

  // Proveedor: lista propia (sin <select> del navegador) con monograma de cada servicio
  const monograma = (nombre) => escaparHtml((nombre || '?').replace(/[^A-Za-zÁÉÍÓÚÑ0-9 ]/g, '').split(/\s+/).map((p) => p[0]).join('').slice(0, 2).toUpperCase());
  const pintarServicio = () => {
    const s = estado.servicios?.[selectServicio.value] || {};
    panel.querySelector('[data-ajuste-servicio-monograma]').innerHTML = monograma(s.nombre);
    panel.querySelector('[data-ajuste-servicio-nombre]').textContent = s.nombre ? `${s.nombre}${s.local ? ' · en este equipo' : ''}` : 'Elige un proveedor';
    listaServicios.querySelectorAll('[data-servicio]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.servicio === selectServicio.value)));
  };
  const cerrarServicios = () => { listaServicios.hidden = true; botonServicio.setAttribute('aria-expanded', 'false'); };
  const elegirServicio = (valor) => {
    if (valor === selectServicio.value) { cerrarServicios(); return; }
    selectServicio.value = valor;
    formAjustes.url.value = '';
    campoModelo.value = '';
    modelos = [];
    listaModelos.hidden = true;
    cuentaModelos.textContent = '';
    pintarServicio();
    actualizarServicio();
    cerrarServicios();
    if (estado.servicios?.[valor]?.local) cargarModelos();
  };
  botonServicio.addEventListener('click', () => {
    const abrir = listaServicios.hidden;
    listaServicios.hidden = !abrir;
    botonServicio.setAttribute('aria-expanded', String(abrir));
  });
  listaServicios.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-servicio]');
    if (b) elegirServicio(b.dataset.servicio);
  });
  document.addEventListener('click', (ev) => { if (!ev.target.closest('.desplegable-ia')) cerrarServicios(); });
  panel.querySelector('[data-ajuste-ver]').addEventListener('click', (ev) => {
    const ver = formAjustes.clave.type === 'password';
    formAjustes.clave.type = ver ? 'text' : 'password';
    ev.currentTarget.classList.toggle('activo', ver);
  });
  // Límite: contador con pasos de 5 (de 5 a 200)
  const fijarLimite = (n) => { formAjustes.limite.value = String(Math.min(200, Math.max(5, Math.round(Number(n) || 30)))); };
  panel.querySelectorAll('[data-ajuste-limite] [data-paso]').forEach((b) => b.addEventListener('click', () => fijarLimite(Number(formAjustes.limite.value) + Number(b.dataset.paso))));
  formAjustes.limite.addEventListener('change', () => fijarLimite(formAjustes.limite.value));
  formAjustes.limite.addEventListener('input', () => { formAjustes.limite.value = formAjustes.limite.value.replace(/\D/g, '').slice(0, 3); });

  // Prefijos conocidos de claves de API
  const detectarServicio = (clave) => {
    const c = clave.trim();
    if (c.startsWith('sk-or-')) return 'openrouter';
    if (c.startsWith('sk-ant-')) return 'anthropic';
    if (c.startsWith('gsk_')) return 'groq';
    if (c.startsWith('AIza')) return 'gemini';
    if (c.startsWith('sk-')) return 'openai';
    return null;
  };
  const datosAjustes = (accion) => ({
    accion, servicio: selectServicio.value, url: formAjustes.url.value, modelo: campoModelo.value.trim(), clave: formAjustes.clave.value,
    esfuerzo: esfuerzoAjuste, herramientas: formAjustes.herramientas.checked, limite: Number(formAjustes.limite.value) || 30,
    instrucciones: formAjustes.instrucciones.value,
  });
  const enviarAjustes = async (accion) => {
    const r = await fetch(`${BASE}/api/asistente/configuracion.php`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF }, body: JSON.stringify(datosAjustes(accion)),
    });
    return r.json();
  };
  const actualizarServicio = () => {
    const s = estado.servicios?.[selectServicio.value];
    if (!s) return;
    const anthropic = s.api === 'anthropic';
    panel.querySelector('[data-ajuste-url-caja]').hidden = anthropic || (selectServicio.value !== 'personalizado' && !s.local);
    if (selectServicio.value !== estado.servicio || !formAjustes.url.value) formAjustes.url.value = s.url;
    panel.querySelector('[data-ajuste-destino]').textContent = s.local
      ? 'Modelo local: las preguntas no salen de este equipo.'
      : `Las preguntas se envían a ${anthropic ? 'api.anthropic.com' : (s.url ? new URL(s.url).hostname : 'el servidor que indiques')}. Los datos de pacientes solo se envían con «Acceso al expediente» y cada envío queda en la bitácora.`;
  };
  const pintarModelos = () => {
    const termino = normalizarTexto(campoModelo.value.trim());
    const visibles = modelos.filter((m) => !termino || normalizarTexto(`${m.id} ${m.nombre}`).includes(termino)).slice(0, 80);
    listaModelos.hidden = !modelos.length;
    listaModelos.innerHTML = visibles.length
      ? visibles.map((m) => `<li role="option" data-modelo="${escaparHtml(m.id)}" aria-selected="${m.id === campoModelo.value}"><b>${escaparHtml(m.nombre || m.id)}</b><small>${escaparHtml(m.id)}${m.contexto ? ` · ${Math.round(m.contexto / 1000)}k de contexto` : ''}</small></li>`).join('')
      : '<li class="ajustes-ia__vacio">Ningún modelo coincide.</li>';
  };
  const cargarModelos = async () => {
    const boton = panel.querySelector('[data-ajustes-modelos]');
    boton.disabled = true;
    boton.classList.add('girando');
    cuentaModelos.textContent = 'Cargando…';
    try {
      const r = await enviarAjustes('modelos');
      if (!r.ok) throw new Error(r.error);
      modelos = r.modelos;
      pintarModelos();
      cuentaModelos.textContent = `${modelos.length} modelos`;
    } catch (e) {
      cuentaModelos.textContent = '';
      mostrarNotificacion('error', e.message || 'No se pudo obtener la lista de modelos.');
    } finally { boton.disabled = false; boton.classList.remove('girando'); }
  };

  function mostrarAjustes() {
    const servicios = Object.entries(estado.servicios || {});
    listaServicios.innerHTML = [['En la nube', servicios.filter(([, s]) => !s.local && s.url !== '')], ['En este equipo y otros', servicios.filter(([, s]) => s.local || s.url === '')]]
      .filter(([, l]) => l.length)
      .map(([titulo, l]) => `<div class="desplegable-ia__titulo">${titulo}</div>` + l.map(([v, s]) => `<button type="button" role="option" data-servicio="${v}"><span class="desplegable-ia__monograma">${monograma(s.nombre)}</span><span>${escaparHtml(s.nombre)}</span><svg class="desplegable-ia__marca" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 12.5 4 4 8-9"/></svg></button>`).join(''))
      .join('');
    selectServicio.value = estado.servicio || 'openrouter';
    pintarServicio();
    cerrarServicios();
    formAjustes.url.value = estado.url || '';
    campoModelo.value = estado.modelo || '';
    formAjustes.clave.value = '';
    formAjustes.clave.type = 'password';
    panel.querySelector('[data-ajuste-clave-ayuda]').textContent = estado.clave_final
      ? `Guardada y cifrada (termina en ${estado.clave_final}). Pega otra solo si quieres cambiarla.`
      : 'Al pegarla se reconoce el proveedor y se cargan sus modelos.';
    formAjustes.herramientas.checked = estado.herramientas !== false;
    fijarLimite(estado.limite || 30);
    formAjustes.instrucciones.value = estado.instrucciones || '';
    esfuerzoAjuste = estado.esfuerzo_defecto || 'medium';
    panel.querySelectorAll('[data-ajuste-esfuerzo] button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.valor === esfuerzoAjuste)));
    panel.querySelector('[data-ajustes-estado]').textContent = estado.configurado ? `Conectado · ${estado.modelo}` : 'Sin configurar';
    panel.querySelector('[data-ajustes-estado]').classList.toggle('activo', !!estado.configurado);
    const uso = estado.uso || {};
    panel.querySelector('[data-ajuste-uso]').innerHTML = [
      ['Preguntas', uso.preguntas], ['Personas', uso.personas], ['Con datos del expediente', uso.expediente], ['Acciones de la IA', uso.acciones],
    ].map(([t, n]) => `<div><dt>${t}</dt><dd>${n ?? 0}</dd></div>`).join('');
    detectado.textContent = '';
    resultado.textContent = '';
    modelos = [];
    listaModelos.hidden = true;
    cuentaModelos.textContent = '';
    actualizarServicio();
    ajustes.hidden = false;
    if (estado.configurado || estado.servicios?.[selectServicio.value]?.local) cargarModelos();
  }

  // Al pegar la clave: se detecta el proveedor y aparecen sus modelos
  formAjustes.clave.addEventListener('input', () => {
    const servicio = detectarServicio(formAjustes.clave.value);
    if (servicio && servicio !== selectServicio.value) {
      selectServicio.value = servicio;
      formAjustes.url.value = estado.servicios[servicio].url;
      campoModelo.value = '';
      pintarServicio();
      actualizarServicio();
    }
    detectado.textContent = servicio ? estado.servicios[servicio].nombre : '';
    clearTimeout(formAjustes._modelos);
    if (formAjustes.clave.value.trim().length > 20) formAjustes._modelos = setTimeout(cargarModelos, 350);
  });
  campoModelo.addEventListener('input', pintarModelos);
  campoModelo.addEventListener('focus', () => { if (modelos.length) pintarModelos(); });
  listaModelos.addEventListener('click', (ev) => {
    const li = ev.target.closest('[data-modelo]');
    if (!li) return;
    campoModelo.value = li.dataset.modelo;
    pintarModelos();
  });
  panel.querySelector('[data-ajustes-modelos]').addEventListener('click', cargarModelos);
  panel.querySelectorAll('[data-ajuste-esfuerzo] button').forEach((b) => b.addEventListener('click', () => {
    esfuerzoAjuste = b.dataset.valor;
    panel.querySelectorAll('[data-ajuste-esfuerzo] button').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
  }));
  panel.querySelector('[data-asistente-ajustes]').addEventListener('click', () => (ajustes.hidden ? mostrarAjustes() : (ajustes.hidden = true)));
  panel.querySelector('[data-ajustes-cerrar]').addEventListener('click', () => { ajustes.hidden = true; });

  formAjustes.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const r = await enviarAjustes('guardar');
    if (!r.ok) { mostrarNotificacion('error', r.error); return; }
    mostrarNotificacion('exito', 'Configuración guardada. La clave quedó cifrada en el servidor.');
    ajustes.hidden = true;
    await cargarEstado();
  });
  panel.querySelector('[data-ajustes-probar]').addEventListener('click', async (ev) => {
    const b = ev.currentTarget;
    b.disabled = true; b.textContent = 'Probando…';
    resultado.textContent = '';
    resultado.className = 'ajustes-ia__prueba';
    try {
      const g = await enviarAjustes('guardar');
      if (!g.ok) throw new Error(g.error);
      estado = { ...estado, ...g };
      const r = await enviarAjustes('probar');
      if (!r.ok) throw new Error(r.error);
      resultado.textContent = `Conectado · respondió en ${(r.ms / 1000).toFixed(1)} s`;
      resultado.classList.add('ok');
      await cargarEstado();
    } catch (e) {
      resultado.textContent = e.message || 'No se pudo conectar.';
      resultado.classList.add('error');
    } finally {
      b.disabled = false; b.textContent = 'Probar';
    }
  });

  /* --- Menús de la barra de entrada: "+" (adjuntar, dictar, herramienta), acceso y esfuerzo --- */
  const HERRAMIENTAS = { normativa: 'Normativa', pacientes: 'Lista de pacientes', paciente: 'Paciente abierto', pagina: 'Página abierta' };
  const ESFUERZOS = { low: 'Rápido', medium: 'Auto', high: 'Profundo' };
  const cerrarMenus = () => panel.querySelectorAll('[data-menu]').forEach((m) => {
    m.hidden = true;
    panel.querySelector(`[data-menu-boton="${m.dataset.menu}"]`).setAttribute('aria-expanded', 'false');
  });
  const avisarAcceso = () => {
    const destino = estado.local ? 'tu modelo local (no sale del equipo)' : (estado.proveedor === 'anthropic' ? 'Anthropic' : (estado.url || 'el proveedor'));
    agregarAviso(`Con acceso al expediente, los datos de pacientes y páginas que elijas en la herramienta se enviarán a ${destino}. Cada envío queda en la bitácora.`);
  };
  const fijarAcceso = (valor) => {
    if (opciones.acceso === valor) return;
    opciones.acceso = valor;
    if (valor === 'restringido' && ['pacientes', 'paciente', 'pagina'].includes(opciones.herramienta)) opciones.herramienta = 'ninguna';
    if (valor === 'expediente') avisarAcceso();
    pintarOpciones();
  };
  const pintarOpciones = () => {
    panel.querySelector('[data-menu-texto="esfuerzo"]').textContent = ESFUERZOS[opciones.esfuerzo];
    panel.querySelectorAll('[data-menu="esfuerzo"] [data-valor]').forEach((li) => li.setAttribute('aria-checked', String(li.dataset.valor === opciones.esfuerzo)));
    panel.querySelectorAll('[data-menu="acceso"] [data-valor]').forEach((li) => li.setAttribute('aria-checked', String(li.dataset.valor === opciones.acceso)));
    panel.querySelectorAll('[data-herramienta]').forEach((li) => {
      li.setAttribute('aria-checked', String(li.dataset.herramienta === opciones.herramienta));
      const bloqueado = ('requiereExpediente' in li.dataset && opciones.acceso !== 'expediente')
        || ('requierePaciente' in li.dataset && !pacienteId) || ('requierePagina' in li.dataset && !paginaId);
      li.setAttribute('aria-disabled', String(bloqueado));
    });
    const acceso = panel.querySelector('[data-menu-boton="acceso"]');
    acceso.classList.toggle('entrada__icono--expediente', opciones.acceso === 'expediente');
    acceso.title = opciones.acceso === 'expediente' ? 'Acceso al expediente: se pueden compartir datos con el modelo' : 'Acceso restringido: no se envía ningún dato de pacientes';
    acceso.innerHTML = panel.querySelector(`[data-menu="acceso"] [data-valor="${opciones.acceso}"] svg`).outerHTML;
    chipHerramienta.hidden = opciones.herramienta === 'ninguna';
    chipHerramienta.innerHTML = opciones.herramienta === 'ninguna' ? ''
      : `${panel.querySelector(`[data-herramienta="${opciones.herramienta}"] svg`).outerHTML}<span>${HERRAMIENTAS[opciones.herramienta]}</span><svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>`;
    chipHerramienta.title = 'Quitar la herramienta';
  };
  chipHerramienta.addEventListener('click', () => { opciones.herramienta = 'ninguna'; pintarOpciones(); texto.focus(); });
  // Otras partes de la app (sugerencias del expediente, IA en notas) le pasan una petición lista para enviar.
  // El acceso al expediente solo se activa por un clic explícito del usuario en esa acción, y se avisa como siempre.
  window.preguntarAsistente = async (pregunta, { acceso = null, herramienta = null } = {}) => {
    if (panel.hidden) await abrir();
    if (acceso) fijarAcceso(acceso);
    if (herramienta && !(herramienta === 'paciente' && !pacienteId)) opciones.herramienta = herramienta;
    pintarOpciones();
    texto.value = pregunta;
    texto.dispatchEvent(new Event('input', { bubbles: true }));
    requestAnimationFrame(() => form.requestSubmit());
  };
  panel.querySelectorAll('[data-menu-boton]').forEach((b) => b.addEventListener('click', (ev) => {
    ev.stopPropagation();
    const menu = panel.querySelector(`[data-menu="${b.dataset.menuBoton}"]`);
    const abrirlo = menu.hidden;
    cerrarMenus();
    menu.hidden = !abrirlo;
    b.setAttribute('aria-expanded', String(abrirlo));
  }));
  panel.querySelectorAll('[data-menu] li[role^="menuitem"]').forEach((li) => li.addEventListener('click', () => {
    if (li.getAttribute('aria-disabled') === 'true') return;
    const menu = li.parentElement.dataset.menu;
    if (li.dataset.herramienta) opciones.herramienta = li.dataset.herramienta;
    if (menu === 'acceso') fijarAcceso(li.dataset.valor);
    if (menu === 'esfuerzo') { opciones.esfuerzo = li.dataset.valor; guardar(); }
    if (li.dataset.accionEntrada === 'adjuntar') archivo.click();
    if (li.dataset.accionEntrada === 'dictar') dictar();
    pintarOpciones();
    cerrarMenus();
  }));
  document.addEventListener('click', (ev) => { if (!ev.target.closest('.entrada__menu')) cerrarMenus(); });

  /* --- Adjuntar archivos de texto --- */
  const archivo = panel.querySelector('[data-entrada-archivo]');
  archivo.addEventListener('change', async () => {
    const f = archivo.files[0];
    archivo.value = '';
    if (!f) return;
    if (f.size > 200 * 1024) { mostrarNotificacion('error', 'El archivo es muy grande (máximo 200 KB de texto).'); return; }
    adjuntos.push({ nombre: f.name, contenido: await f.text() });
    pintarAdjuntos();
  });
  const pintarAdjuntos = () => {
    adjuntosCaja.hidden = !adjuntos.length;
    adjuntosCaja.innerHTML = adjuntos.map((a, i) => `<span class="entrada__adjunto">${escaparHtml(a.nombre)}<button type="button" data-quitar="${i}" aria-label="Quitar"><svg class="icono" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button></span>`).join('');
    actualizarEnviar();
  };
  adjuntosCaja.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-quitar]');
    if (b) { adjuntos.splice(Number(b.dataset.quitar), 1); pintarAdjuntos(); }
  });

  /* --- Dictar la pregunta con el servicio local de voz --- */
  const mic = panel.querySelector('[data-entrada-mic]');
  let grabadora = null;
  async function dictar() {
    if (grabadora?.state === 'recording') { grabadora.stop(); return; }
    let flujo;
    try { flujo = await navigator.mediaDevices.getUserMedia({ audio: true }); }
    catch (e) { mostrarNotificacion('error', 'No se pudo usar el micrófono. Revisa el permiso del navegador.'); return; }
    const trozos = [];
    grabadora = new MediaRecorder(flujo);
    grabadora.ondataavailable = (e) => { if (e.data.size) trozos.push(e.data); };
    grabadora.onstop = async () => {
      flujo.getTracks().forEach((t) => t.stop());
      mic.hidden = true;
      const datos = new FormData();
      datos.append('audio', new Blob(trozos, { type: grabadora.mimeType }), 'pregunta.webm');
      try {
        const r = await (await fetch(`${BASE}/api/pacientes/dictado.php`, { method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos })).json();
        if (!r.ok) throw new Error(r.error);
        texto.value = (texto.value ? `${texto.value} ` : '') + r.texto;
        ajustarAltura();
        texto.focus();
      } catch (e) { mostrarNotificacion('error', e.message || 'No se pudo transcribir.'); }
    };
    grabadora.start();
    mic.hidden = false;
  }
  mic.addEventListener('click', () => grabadora?.stop());

  /* --- Sugerencias del estado vacío --- */
  const preparar_pregunta = (t) => { texto.value = t; ajustarAltura(); texto.focus(); texto.setSelectionRange(t.length, t.length); };
  panel.addEventListener('click', (ev) => {
    const s = ev.target.closest('[data-sugerencia]')?.dataset.sugerencia;
    if (!s) return;
    if (s === 'buscar') { abrirPaleta(); return; }
    if (s === 'archivo') { archivo.click(); return; }
    if (s === 'normativa') { opciones.herramienta = 'normativa'; preparar_pregunta('¿Qué debe contener una nota de evolución según la NOM-004? '); }
    if (s === 'nota_clinica') { opciones.herramienta = 'normativa'; preparar_pregunta('Redacta una nota de evolución con los apartados de la NOM-004 y créala como página a partir de estos datos: '); }
    if (s === 'base_datos_nueva') { preparar_pregunta('Crea una base de datos llamada «» con las columnas: '); }
    if (s === 'pacientes') { fijarAcceso('expediente'); opciones.herramienta = 'pacientes'; preparar_pregunta('¿Cuántos pacientes hay registrados y qué padecimientos son más frecuentes?'); }
    if (s === 'paciente') { fijarAcceso('expediente'); opciones.herramienta = 'paciente'; preparar_pregunta('Resume el expediente de este paciente.'); }
    if (s === 'resumen_paciente') {
      fijarAcceso('expediente'); opciones.herramienta = 'paciente';
      preparar_pregunta('Revisa el expediente de este paciente y resúmelo: primero las alertas importantes, luego antecedentes, tratamiento actual, evolución reciente (con gráfica de signos vitales si hay datos) y pendientes.');
      pintarOpciones(); actualizarEnviar();
      setTimeout(() => enviar.click(), 60);
      return;
    }
    if (s === 'pagina') { fijarAcceso('expediente'); opciones.herramienta = 'pagina'; preparar_pregunta('Resume esta página.'); }
    if (s === 'base_datos') { fijarAcceso('expediente'); opciones.herramienta = 'pagina'; preparar_pregunta('Ayúdame a configurar esta base de datos: ¿qué propiedades me recomiendas agregar?'); }
    pintarOpciones();
    actualizarEnviar();
  });

  /* --- Lista de chats ("Nuevo chat con la IA ▾") --- */
  const botonChats = panel.querySelector('[data-asistente-chats]');
  botonChats.addEventListener('click', () => {
    const hace = (ms) => {
      const min = Math.round((Date.now() - ms) / 60000);
      return min < 1 ? 'ahora' : min < 60 ? `hace ${min} min` : `hace ${Math.round(min / 60)} h`;
    };
    abrirMenu(botonChats, [
      { icono: 'agregar', texto: 'Nuevo chat', accion: nuevoChat },
      ...(chats.length ? ['separador', { titulo: 'En esta sesión' }] : []),
      ...chats.map((c) => ({ texto: c.titulo, detalle: hace(c.actualizado), marcado: c.id === chatActual, accion: () => {
        peticion?.abort();
        chatActual = c.id;
        guardar();
        pintarHistorial();
      } })),
    ]);
  });
  function nuevoChat() {
    peticion?.abort();
    chatActual = null;
    guardar();
    pintarHistorial();
    texto.focus();
  }
  panel.querySelector('[data-asistente-nueva]').addEventListener('click', nuevoChat);

  /* --- Conversación --- */
  const actualizarEnviar = () => { enviar.disabled = !peticion && !texto.value.trim() && !adjuntos.length; };
  const ajustarAltura = () => { texto.style.height = 'auto'; texto.style.height = `${Math.min(texto.scrollHeight, 200)}px`; actualizarEnviar(); };
  texto.addEventListener('input', ajustarAltura);
  texto.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); form.requestSubmit(); }
  });

  const alFinal = () => { conversacion.scrollTop = conversacion.scrollHeight; };
  const conversando = () => panel.classList.toggle('asistente--conversando', historial().length > 0 || !!conversacion.querySelector('.msj'));
  const agregarAviso = (t) => {
    vacio.hidden = true;
    const d = document.createElement('div');
    d.className = 'msj msj__aviso';
    d.textContent = t;
    conversacion.appendChild(d);
    conversando();
    alFinal();
  };
  const nodoUsuario = (m) => {
    const d = document.createElement('div');
    d.className = 'msj msj--usuario';
    d.textContent = m.visible ?? m.texto;
    (m.adjuntos || []).forEach((n) => {
      const a = document.createElement('div');
      a.className = 'msj__adjunto';
      a.innerHTML = '<svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></svg><span></span>';
      a.querySelector('span').textContent = n;
      d.appendChild(a);
    });
    return d;
  };
  // Icono de cada acción según la herramienta que la hizo
  const TEMA_HERRAMIENTA = {
    buscar_paginas: 'buscar', buscar_pacientes: 'buscar', leer_pagina: 'leer', leer_paciente: 'paciente',
    crear_pagina: 'escribir', escribir_nota: 'escribir', renombrar_pagina: 'escribir', mover_pagina: 'planear', mover_a_papelera: 'planear',
    agregar_columnas: 'datos', agregar_filas: 'datos', actualizar_filas: 'datos', eliminar_filas: 'datos', editar_columna: 'datos',
    registrar_paciente: 'paciente', actualizar_paciente: 'paciente', agregar_historial: 'clinica', revisar_expediente: 'clinica', crear_grafica: 'grafica',
    buscar_en_internet: 'web', leer_sitio_web: 'web', insertar_imagen: 'imagen', ver_agenda: 'agenda', agendar_cita: 'agenda',
  };
  const ICONO_IMAGEN = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><circle cx="9" cy="10" r="1.6"/><path d="m20.5 16-4.6-4.6a1.2 1.2 0 0 0-1.7 0L6 19.5"/></svg>';
  const iconoAccion = (nombre) => {
    const tema = TEMA_HERRAMIENTA[nombre] || 'idea';
    if (tema === 'grafica') return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4v16h16"/><path d="M8 16v-4M12 16V8M16 16v-6"/></svg>';
    return tema === 'imagen' ? ICONO_IMAGEN : (window.Razonamiento?.icono(tema) || '');
  };
  const nodoAccion = (a) => {
    // Con enlace, toda la acción abre lo que se creó o consultó (página, cita, expediente)
    const enlaceSeguro = typeof a.url === 'string' && (a.url.startsWith(`${BASE}/`) || a.url.startsWith('/')) && !a.url.startsWith('//');
    const n = document.createElement(enlaceSeguro ? 'a' : 'div');
    if (enlaceSeguro) { n.href = a.url; n.title = 'Abrir'; }
    n.className = `msj__accion${a.ok ? '' : ' msj__accion--error'}${enlaceSeguro ? ' msj__accion--enlace' : ''}`;
    const estado = a.ok
      ? '<path d="m6.5 12.5 3.5 3.5 7.5-8"/>'
      : '<path d="M12 7.5v5.5M12 16.5h.01"/>';
    n.innerHTML = `<span class="msj__accion-icono" aria-hidden="true">${iconoAccion(a.nombre)}</span><span class="msj__accion-texto"></span>`
      + `<svg class="msj__accion-estado" viewBox="0 0 24 24" aria-hidden="true">${estado}</svg>`;
    n.querySelector('.msj__accion-texto').textContent = a.d;
    if (a.vista) {
      // Lo que encontró la investigación: fuentes con su dominio o una galería de imágenes
      const caja = document.createElement('div');
      caja.className = 'msj__vista';
      const fuentes = (a.vista.fuentes || []).filter((f) => /^https?:\/\//.test(f.url));
      const imagenes = (a.vista.imagenes || []).filter((i) => /^https?:\/\//.test(i.url));
      if (fuentes.length) {
        caja.innerHTML = `<div class="msj__fuentes">${fuentes.map((f) => {
          let host = '';
          try { host = new URL(f.url).hostname.replace(/^www\./, ''); } catch (e) { /* sin dominio */ }
          return `<a class="msj__fuente" href="${escaparHtml(f.url)}" target="_blank" rel="noopener noreferrer" title="${escaparHtml(f.titulo || '')}">${iconoAccion('buscar_en_internet')}<span>${escaparHtml(host)}</span></a>`;
        }).join('')}</div>`;
      }
      if (imagenes.length) {
        caja.innerHTML += `<div class="msj__galeria">${imagenes.map((i) => `<a href="${escaparHtml(i.pagina || i.url)}" target="_blank" rel="noopener noreferrer"><img alt="${escaparHtml(i.titulo || '')}" loading="lazy" src="${BASE}/api/asistente/imagen.php?u=${encodeURIComponent(i.url)}"><small>${escaparHtml(i.titulo || '')}</small></a>`).join('')}</div>`;
        caja.querySelectorAll('img').forEach((img) => {
          img.addEventListener('load', () => img.classList.add('cargada'));
          img.addEventListener('error', () => img.closest('a').remove());
        });
      }
      n._vista = caja;
    }
    if (enlaceSeguro) n.insertAdjacentHTML('beforeend', '<svg class="msj__accion-ir" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg>');
    return n;
  };
  const nodoAsistente = (contenido, acciones = []) => {
    const d = document.createElement('div');
    d.className = 'msj msj--asistente';
    acciones.forEach((a) => { const n = nodoAccion(a); d.appendChild(n); if (n._vista) d.appendChild(n._vista); });
    const md = document.createElement('div');
    md.className = 'md';
    md.innerHTML = markdownSeguro(contenido || '');
    montarGraficas(md);
    d.appendChild(md);
    return d;
  };
  function pintarHistorial() {
    conversacion.querySelectorAll('.msj').forEach((n) => n.remove());
    vacio.hidden = historial().length > 0;
    historial().forEach((m) => conversacion.appendChild(m.rol === 'usuario' ? nodoUsuario(m) : nodoAsistente(m.visible ?? m.texto, m.acciones)));
    pintarTitulo();
    conversando();
    alFinal();
  }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (peticion) { peticion.abort(); return; }          // el botón también detiene la respuesta
    const pregunta = texto.value.trim();
    if (!pregunta && !adjuntos.length) return;
    if (!estado.configurado) {
      mostrarNotificacion('error', estado.es_admin ? 'Primero configura el proveedor en la configuración del asistente.' : 'El asistente aún no está configurado. Pide al administrador que lo active.');
      return;
    }
    if (!chat()) {
      chatActual = Math.random().toString(36).slice(2, 10);
      chats.unshift({ id: chatActual, titulo: (pregunta || adjuntos[0].nombre).slice(0, 60), historial: [], actualizado: Date.now() });
    }
    const actual = chat();
    // Los archivos adjuntos viajan como texto dentro de la pregunta
    const completo = pregunta + adjuntos.map((a) => `\n\n--- Archivo adjunto: ${a.nombre} ---\n${a.contenido}`).join('');
    const mensaje = { rol: 'usuario', texto: completo, visible: pregunta || '(archivo adjunto)', adjuntos: adjuntos.map((a) => a.nombre) };
    actual.historial.push(mensaje);
    actual.actualizado = Date.now();
    vacio.hidden = true;
    conversacion.appendChild(nodoUsuario(mensaje));
    pintarTitulo();
    conversando();
    texto.value = '';
    adjuntos = [];
    pintarAdjuntos();
    ajustarAltura();

    const contenedor = document.createElement('div');
    contenedor.className = 'msj msj--asistente';
    const zonaPensamiento = document.createElement('div');
    let md = document.createElement('div');
    md.className = 'md msj__escribiendo';
    contenedor.append(zonaPensamiento, md);
    conversacion.appendChild(contenedor);
    alFinal();

    let respuesta = '';
    let segmento = '';          // texto desde la última acción de herramienta
    const acciones = [];
    let cambioEspacio = false;
    let cambioPaginaActual = false;
    let razonamiento = null;
    let enCurso = null;
    peticion = new AbortController();
    enviar.disabled = false;
    enviar.classList.add('detener');
    enviar.setAttribute('aria-label', 'Detener');

    try {
      const r = await fetch(`${BASE}/api/asistente/conversar.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
        body: JSON.stringify({
          mensajes: actual.historial.map((m) => ({ rol: m.rol, texto: m.texto })),
          acceso: opciones.acceso, herramienta: opciones.herramienta, esfuerzo: opciones.esfuerzo,
          paciente_id: pacienteId, pagina_id: paginaId,
        }),
        signal: peticion.signal,
      });
      const lector = r.body.getReader();
      const decodificador = new TextDecoder();
      let pendiente = '';
      for (;;) {
        const { value, done } = await lector.read();
        if (done) break;
        pendiente += decodificador.decode(value, { stream: true });
        let fin;
        while ((fin = pendiente.indexOf('\n')) >= 0) {
          const linea = pendiente.slice(0, fin).trim();
          pendiente = pendiente.slice(fin + 1);
          if (!linea) continue;
          const evento = JSON.parse(linea);
          if (evento.t === 'pensamiento') {
            if (!razonamiento && window.Razonamiento) razonamiento = Razonamiento.crear(zonaPensamiento, marco);
            razonamiento?.agregar(evento.d);
          } else if (evento.t === 'texto') {
            razonamiento?.terminar();
            respuesta += evento.d;
            segmento += evento.d;
            md.innerHTML = markdownSeguro(segmento);
            montarGraficas(md);
            if (conversacion.scrollHeight - conversacion.scrollTop - conversacion.clientHeight < 140) alFinal();
          } else if (evento.t === 'herramienta_inicio') {
            // Paso en curso: brillo sobre el texto hasta que llega el resultado
            razonamiento?.terminar();
            enCurso?.remove();
            enCurso = document.createElement('div');
            enCurso.className = 'msj__accion msj__accion--curso';
            enCurso.innerHTML = `<span class="msj__accion-icono" aria-hidden="true">${iconoAccion(evento.nombre)}</span><span class="msj__accion-texto trShimmer"></span>`;
            enCurso.querySelector('.msj__accion-texto').textContent = evento.d;
            md.classList.remove('msj__escribiendo');
            contenedor.insertBefore(enCurso, md);
            alFinal();
          } else if (evento.t === 'herramienta') {
            // La IA hizo algo en el espacio de trabajo: se muestra la acción y el texto siguiente va debajo
            razonamiento?.terminar();
            enCurso?.remove();
            enCurso = null;
            const accion = { d: evento.d, ok: evento.ok, url: evento.url, nombre: evento.nombre, vista: evento.vista };
            acciones.push(accion);
            md.classList.remove('msj__escribiendo');
            if (!segmento) md.remove();
            const nodo = nodoAccion(accion);
            contenedor.appendChild(nodo);
            if (nodo._vista) contenedor.appendChild(nodo._vista);
            md = document.createElement('div');
            md.className = 'md msj__escribiendo';
            contenedor.appendChild(md);
            if (segmento) respuesta += '\n\n';
            segmento = '';
            if (evento.cambio) {
              cambioEspacio = true;
              if (evento.url && paginaId && evento.url.endsWith(`?id=${paginaId}`)) cambioPaginaActual = true;
            }
            alFinal();
          } else if (evento.t === 'aviso' || evento.t === 'error') {
            const n = document.createElement('div');
            n.className = evento.t === 'error' ? 'msj__error' : 'msj__aviso';
            n.textContent = evento.d;
            contenedor.appendChild(n);
          }
        }
      }
    } catch (error) {
      if (error.name !== 'AbortError') {
        const n = document.createElement('div');
        n.className = 'msj__error';
        n.textContent = 'Se perdió la conexión con el servidor.';
        contenedor.appendChild(n);
      }
    } finally {
      razonamiento?.terminar();
      enCurso?.remove();
      md.classList.remove('msj__escribiendo');
      if (!segmento && acciones.length) md.remove();
      // Las acciones viajan con el texto en el historial que se reenvía, para que el modelo sepa lo que ya hizo
      const resumenAcciones = acciones.map((a) => `- ${a.ok ? 'Hecho' : 'Falló'}: ${a.d}`).join('\n');
      const textoGuardado = respuesta.trim() || (acciones.length ? 'Listo.' : '');
      if (textoGuardado) {
        actual.historial.push({ rol: 'asistente', texto: textoGuardado, acciones,
          ...(acciones.length ? { texto: `${textoGuardado}\n\n(Acciones realizadas:\n${resumenAcciones})`, visible: textoGuardado } : {}) });
      }
      else actual.historial.pop();     // sin respuesta: la pregunta no queda en el historial que se reenvía
      guardar();
      peticion = null;
      enviar.classList.remove('detener');
      enviar.setAttribute('aria-label', 'Enviar');
      actualizarEnviar();
      alFinal();
      // Lo que la IA creó o editó aparece en la barra lateral; si cambió la página abierta, se vuelve a cargar
      if (cambioEspacio && typeof Arbol !== 'undefined') Arbol.recargar();
      if (cambioPaginaActual) {
        mostrarNotificacion('exito', 'El asistente actualizó esta página.');
        setTimeout(() => location.reload(), 900);
      }
    }
  });

  // Al cerrar sesión se olvida la conversación
  document.querySelector('.barra-lateral__usuario')?.addEventListener('submit', () => {
    try { sessionStorage.removeItem(CLAVE); sessionStorage.removeItem(`${CLAVE}-abierto`); } catch (e) { /* opcional */ }
  });

  pintarOpciones();
  pintarTitulo();
  actualizarEnviar();
}

/* ---------- Notificaciones: se ocultan solas ---------- */
function programarCierre(notificacion) {
  const espera = notificacion.classList.contains('notificacion--error') ? 6000 : 4000;
  setTimeout(() => quitarConAnimacion(notificacion, 'saliendo'), espera);
}

/* ---------- Inicialización ---------- */
document.addEventListener('DOMContentLoaded', () => {
  actualizarFechasVacias();
  document.addEventListener('change', actualizarFechasVacias);

  document.querySelectorAll('.notificacion').forEach(programarCierre);

  iniciarAccesoEnPasos(document.querySelector('[data-acceso-pasos]'));
  iniciarCodigo(document.querySelector('[data-codigo]'));
  document.querySelectorAll('[data-actividad]').forEach(iniciarActividad);
  iniciarBarraLateral();
  iniciarPestanas();
  iniciarBarraRuta();
  document.querySelectorAll('[data-geo]').forEach(iniciarMapa);
  document.querySelectorAll('[data-dictado]').forEach(iniciarDictado);
  iniciarFotoClinica();
  iniciarAsistente();
  document.querySelectorAll('[data-historial]').forEach(iniciarHistorial);

  document.querySelectorAll('[data-bd]').forEach(iniciarBaseDatos);

  const form = document.getElementById('form-paciente');
  if (!form) return;

  const curp = form.querySelector('#curp');
  curp.addEventListener('input', () => {
    curp.value = curp.value.toUpperCase().replace(/[^A-ZÑ0-9]/g, '');
    if (curp.value.length < 18) {
      marcarError(curp, '');
      return;
    }
    if (!curpValida(curp.value)) {
      marcarError(curp, 'La CURP no es válida. Revisa el formato y el dígito verificador.');
      return;
    }
    marcarError(curp, '');
    const datos = datosDesdeCurp(curp.value);
    rellenarSiVacio(form.querySelector('#fecha_nacimiento'), datos.fecha);
    rellenarSiVacio(form.querySelector('#sexo'), datos.sexo);
    rellenarSiVacio(form.querySelector('#entidad_nacimiento'), datos.entidad);
  });

  // Quita el error en cuanto el usuario corrige el campo
  form.addEventListener('input', (ev) => {
    if (ev.target !== curp && ev.target.value) marcarError(ev.target, '');
  });
  form.addEventListener('change', (ev) => {
    if (ev.target.matches('select, input[type="checkbox"], input[type="date"]')) marcarError(ev.target, '');
  });

  form.addEventListener('submit', (ev) => {
    let primero = null;

    form.querySelectorAll('[required]').forEach((el) => {
      const vacio = el.type === 'checkbox' ? !el.checked : !el.value.trim();
      if (vacio && el.type !== 'checkbox') {
        marcarError(el, 'Campo obligatorio');
        primero = primero || el;
      }
    });

    if (curp.value && !curpValida(curp.value)) {
      marcarError(curp, 'La CURP no es válida. Revisa el formato y el dígito verificador.');
      primero = primero || curp;
    }

    const correo = form.querySelector('#correo');
    if (correo.value && !correo.checkValidity()) {
      marcarError(correo, 'Correo electrónico no válido');
      primero = primero || correo;
    }

    const confirmacion = form.querySelector('#confirmacion');
    confirmacion.closest('.casilla').style.color = confirmacion.checked ? '' : 'var(--peligro)';
    if (!confirmacion.checked) primero = primero || confirmacion;

    if (primero) {
      ev.preventDefault();
      primero.focus();
    }
  });

  form.querySelector('#confirmacion').addEventListener('change', (ev) => {
    if (ev.target.checked) ev.target.closest('.casilla').style.color = '';
  });
});

/* ---------- Historial clínico desplegable (cada evento abre su detalle) ---------- */
function iniciarAcordeon(lista) {
  lista.addEventListener('click', (ev) => {
    const cabecera = ev.target.closest('.acordeon__cabecera');
    if (!cabecera) return;
    const abierto = cabecera.getAttribute('aria-expanded') === 'true';
    cabecera.setAttribute('aria-expanded', String(!abierto));
    cabecera.closest('.acordeon__item').classList.toggle('abierto', !abierto);
  });
}

/* ---------- Barra de acciones que despliega sus nombres ----------
   Con ratón: se despliega al pasar por encima. Con teclado: al enfocarla.
   En pantallas táctiles el primer toque despliega y el segundo ejecuta. */
function iniciarBarraAcciones(barra) {
  const resalte = barra.querySelector('.barra-acciones__resalte');
  const items = [...barra.querySelectorAll('.barra-acciones__item')];
  let temporizador = null;
  let activo = null;
  let tocada = false;

  const moverResalte = () => {
    if (!activo) { resalte.style.opacity = '0'; return; }
    resalte.style.left = `${activo.offsetLeft}px`;
    resalte.style.width = `${activo.offsetWidth}px`;
    resalte.style.opacity = '1';
  };
  // Mientras los nombres se despliegan, el resalte sigue al elemento
  const seguir = () => {
    const fin = performance.now() + 420;
    const paso = () => { moverResalte(); if (performance.now() < fin) requestAnimationFrame(paso); };
    requestAnimationFrame(paso);
  };
  const expandir = () => { clearTimeout(temporizador); barra.classList.add('expandida'); seguir(); };
  const contraer = () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
      barra.classList.remove('expandida');
      activo = null;
      tocada = false;
      items.forEach((i) => i.classList.remove('resaltado'));
      seguir();
    }, 90);
  };
  const resaltar = (item) => {
    activo = item;
    items.forEach((i) => i.classList.toggle('resaltado', i === item));
    moverResalte();
  };

  barra.addEventListener('pointerenter', (ev) => { if (ev.pointerType === 'mouse') expandir(); });
  barra.addEventListener('pointerleave', (ev) => { if (ev.pointerType === 'mouse') contraer(); });
  barra.addEventListener('transitionend', moverResalte);   // al terminar de desplegarse, el resalte se ajusta al elemento
  barra.addEventListener('focusin', () => expandir());
  barra.addEventListener('focusout', (ev) => { if (!barra.contains(ev.relatedTarget)) contraer(); });
  items.forEach((item) => {
    item.addEventListener('pointerenter', () => { clearTimeout(temporizador); resaltar(item); });
    item.addEventListener('focus', () => resaltar(item));
    item.addEventListener('pointerdown', (ev) => {
      if (ev.pointerType !== 'mouse' && !barra.classList.contains('expandida')) item.dataset.primerToque = '1';
    });
    item.addEventListener('click', (ev) => {
      if (item.dataset.primerToque && !tocada) {
        delete item.dataset.primerToque;
        ev.preventDefault();
        ev.stopImmediatePropagation();
        tocada = true;
        expandir();
        resaltar(item);
        return;
      }
      delete item.dataset.primerToque;
      const accion = item.dataset.barraAccion;
      if (accion === 'imprimir') (window.abrirImpresion ? window.abrirImpresion(item) : window.print());
      if (accion === 'preguntar-ia') {
        // Abre el asistente y le pide la revisión del expediente abierto
        document.querySelector('[data-asistente-abrir]')?.click();
        setTimeout(() => {
          const b = document.createElement('button');
          b.dataset.sugerencia = 'resumen_paciente';
          b.hidden = true;
          document.querySelector('.asistente')?.appendChild(b);
          b.click();
          b.remove();
        }, 250);
      }
      if (accion === 'agregar-historial') {
        document.querySelector('[data-historial-abrir]')?.click();
        document.querySelector('[data-historial-form]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }, true);
  });
  // Un toque fuera la vuelve a contraer
  document.addEventListener('pointerdown', (ev) => { if (tocada && !barra.contains(ev.target)) contraer(); });
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.acordeon').forEach(iniciarAcordeon);
  document.querySelectorAll('[data-barra-acciones]').forEach(iniciarBarraAcciones);
  iniciarPerfilPaciente();
});

/* Notas, carpetas y bases de datos del expediente (perfil del paciente y sección Pacientes) */
async function crearPaginaPaciente(tipo, pacienteId) {
  try {
    const r = await fetch(`${BASE}/api/pacientes/paginas.php`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
      body: JSON.stringify({ tipo, paciente_id: pacienteId || null }),
    });
    const res = await r.json();
    if (!r.ok) throw new Error(res.error || 'No se pudo crear.');
    location.href = res.url;
  } catch (e) { mostrarNotificacion('error', e.message); }
}
document.addEventListener('click', (ev) => {
  const b = ev.target.closest('[data-pagina-paciente]');
  if (b) { crearPaginaPaciente(b.dataset.paginaPaciente, b.dataset.paciente); return; }
  const menu = ev.target.closest('[data-pagina-paciente-menu]');
  if (menu && typeof abrirMenu === 'function') {
    abrirMenu(menu, [
      ...(menu.dataset.registrar !== '0' ? [{ icono: 'usuario', texto: 'Registrar paciente', accion: () => { location.href = `${BASE}/pacientes/registro.php`; } }, 'separador'] : []),
      { titulo: 'Crear en Pacientes' },
      { icono: 'documento', texto: 'Nota', accion: () => crearPaginaPaciente('nota') },
      { icono: 'tabla', texto: 'Base de datos', accion: () => crearPaginaPaciente('base_datos') },
      { icono: 'carpeta', texto: 'Carpeta', accion: () => crearPaginaPaciente('carpeta') },
      ...(menu.dataset.importar === '1' ? ['separador',
        { titulo: 'Datos' },
        { icono: 'tabla', texto: 'Importar', detalle: 'CSV, Excel, SQL, HL7, FHIR', accion: () => { location.href = `${BASE}/datos/`; } },
        { icono: 'documento', texto: 'Exportar', accion: () => { location.href = `${BASE}/datos/#exportar`; } },
        { icono: 'reloj', texto: 'Historial de conversiones', accion: () => { location.href = `${BASE}/datos/#historial`; } }] : []),
      'separador',
      { icono: 'candado', texto: 'Verificar documento impreso', detalle: 'Por su huella', accion: () => { location.href = `${BASE}/pacientes/verificar.php`; } },
    ]);
  }
});

/* ---------- Perfil del paciente ---------- */
function iniciarPerfilPaciente() {
  if (new URLSearchParams(location.search).get('resumir') === '1' && document.querySelector('[data-foto-paciente]')) {
    history.replaceState(null, '', location.pathname + location.search.replace(/[?&]resumir=1/, '').replace(/^&/, '?') + location.hash);
    setTimeout(() => document.querySelector('[data-barra-accion="preguntar-ia"]')?.click(), 400);
  }
  document.querySelector('[data-imprimir]')?.addEventListener('click', (ev) => (window.abrirImpresion ? window.abrirImpresion(ev.currentTarget) : window.print()));

  // Acciones rápidas: la tarjeta aparece al acercar el ratón al borde izquierdo del contenido
  const lateral = document.querySelector('[data-tarjeta-lateral]');
  if (lateral) {
    const contenido = document.querySelector('.contenido');
    const colocar = () => lateral.style.setProperty('--tarjeta-izquierda', `${Math.round(contenido?.getBoundingClientRect().left ?? 0)}px`);
    colocar();
    window.addEventListener('resize', colocar);
    new ResizeObserver(colocar).observe(document.body);
    let temporizador = null;
    const abrir = () => { clearTimeout(temporizador); lateral.classList.add('abierta'); lateral.querySelector('.tarjeta-lateral__asa').setAttribute('aria-expanded', 'true'); };
    const cerrar = () => { temporizador = setTimeout(() => { lateral.classList.remove('abierta'); lateral.querySelector('.tarjeta-lateral__asa').setAttribute('aria-expanded', 'false'); }, 260); };
    lateral.addEventListener('mouseenter', abrir);
    lateral.addEventListener('mouseleave', cerrar);
    lateral.querySelector('.tarjeta-lateral__asa').addEventListener('click', () => (lateral.classList.contains('abierta') ? cerrar() : abrir()));
    lateral.addEventListener('focusin', abrir);
    lateral.addEventListener('focusout', (ev) => { if (!lateral.contains(ev.relatedTarget)) cerrar(); });
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') { clearTimeout(temporizador); lateral.classList.remove('abierta'); } });
  }

  // Evolución de signos vitales
  const fuente = document.getElementById('grafica-signos');
  const caja = document.querySelector('[data-grafica="signos"]');
  if (fuente && caja && typeof Graficas !== 'undefined') {
    const d = JSON.parse(fuente.textContent);
    Graficas.montar(caja.parentElement, { tipo: 'lineas', titulo: 'Evolución por consulta',
      categorias: d.categorias.map((c) => c.etiqueta), series: d.series.map((s) => ({ nombre: s.nombre, valores: s.valores })) });
    caja.remove();
  }

  // Foto: clic para elegir; se sube y se reemplaza sin recargar
  const foto = document.querySelector('[data-foto-paciente]');
  const archivo = foto?.querySelector('[data-foto-archivo]');
  if (foto && archivo) {
    const id = foto.dataset.fotoPaciente;
    const quitar = async () => {
      const datos = new FormData();
      datos.append('paciente_id', id);
      datos.append('accion', 'quitar');
      const r = await fetch(`${BASE}/api/pacientes/foto.php`, { method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos });
      if (r.ok) location.reload();
    };
    foto.querySelector('[data-foto-cambiar]').addEventListener('click', (ev) => {
      if (foto.classList.contains('hoja-paciente__foto--con') && typeof abrirMenu === 'function') {
        abrirMenu(ev.currentTarget, [
          { icono: 'imagen', texto: 'Cambiar foto', accion: () => archivo.click() },
          { icono: 'papelera', texto: 'Quitar foto', peligro: true, accion: quitar },
        ]);
      } else {
        archivo.click();
      }
    });
    archivo.addEventListener('change', async () => {
      const f = archivo.files[0];
      if (!f) return;
      const datos = new FormData();
      datos.append('paciente_id', id);
      datos.append('foto', f);
      foto.classList.add('subiendo');
      try {
        const r = await fetch(`${BASE}/api/pacientes/foto.php`, { method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos });
        const res = await r.json();
        if (!r.ok) throw new Error(res.error);
        let img = foto.querySelector('img');
        if (!img) { img = document.createElement('img'); foto.prepend(img); foto.querySelector('.hoja-paciente__iniciales')?.remove(); }
        img.src = res.url;
        img.alt = 'Foto del paciente';
        foto.classList.add('hoja-paciente__foto--con');
        foto.querySelector('[data-foto-cambiar] span').textContent = 'Cambiar';
        mostrarNotificacion('exito', 'Foto actualizada.');
      } catch (e) {
        mostrarNotificacion('error', e.message || 'No se pudo subir la foto.');
      } finally {
        foto.classList.remove('subiendo');
        archivo.value = '';
      }
    });
  }

  // Propiedades: clic en el valor para editarlo (texto, área o lista de opciones)
  document.querySelectorAll('[data-propiedades-paciente]').forEach((lista) => {
    const id = Number(lista.dataset.propiedadesPaciente);
    const guardar = async (dd, valor) => {
      const r = await fetch(`${BASE}/api/pacientes/actualizar.php`, {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
        body: JSON.stringify({ id, campo: dd.dataset.campo, valor }),
      });
      const res = await r.json();
      if (!r.ok || !res.ok) throw new Error(res.error || 'No se pudo guardar.');
      const texto = res.texto ?? res.valor ?? valor;
      dd.innerHTML = texto ? escaparHtml(texto) : '<span class="propiedad-paciente__vacio">Agregar</span>';
      dd.classList.add('guardado');
      setTimeout(() => dd.classList.remove('guardado'), 900);
    };
    const editar = (dd) => {
      if (dd.querySelector('input, textarea')) return;
      const actual = dd.querySelector('.propiedad-paciente__vacio') ? '' : dd.textContent.trim();
      if (dd.dataset.edicion === 'opciones') {
        const opciones = JSON.parse(dd.dataset.opciones || '[]');
        abrirMenu(dd, [
          ...opciones.map((o) => ({ texto: o.texto, marcado: o.texto === actual, accion: () => guardar(dd, o.valor).catch((e) => mostrarNotificacion('error', e.message)) })),
          'separador',
          { icono: 'cerrar', texto: 'Sin valor', accion: () => guardar(dd, '').catch((e) => mostrarNotificacion('error', e.message)) },
        ]);
        return;
      }
      const campo = document.createElement(dd.dataset.edicion === 'area' ? 'textarea' : 'input');
      campo.className = 'propiedad-paciente__campo';
      campo.value = actual;
      if (campo.tagName === 'TEXTAREA') campo.rows = 3;
      dd.innerHTML = '';
      dd.appendChild(campo);
      campo.focus();
      let terminado = false;
      const cerrar = async (confirmar) => {
        if (terminado) return;
        terminado = true;
        const valor = campo.value.trim();
        if (!confirmar || valor === actual) {
          dd.innerHTML = actual ? escaparHtml(actual) : '<span class="propiedad-paciente__vacio">Agregar</span>';
          return;
        }
        try { await guardar(dd, valor); } catch (e) {
          mostrarNotificacion('error', e.message);
          dd.innerHTML = actual ? escaparHtml(actual) : '<span class="propiedad-paciente__vacio">Agregar</span>';
        }
      };
      campo.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') { ev.preventDefault(); cerrar(false); }
        if (ev.key === 'Enter' && (campo.tagName === 'INPUT' || ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); cerrar(true); }
      });
      campo.addEventListener('blur', () => cerrar(true));
    };
    lista.addEventListener('click', (ev) => {
      const dd = ev.target.closest('dd[data-campo]');
      if (dd && !ev.target.closest('input, textarea')) editar(dd);
    });
    lista.addEventListener('keydown', (ev) => {
      const dd = ev.target.closest('dd[data-campo]');
      if (dd && ev.target === dd && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); editar(dd); }
    });
  });
}

/* ---------- Crear cuenta en tres pasos ---------- */
function iniciarRegistro(form) {
  const datos = JSON.parse(document.getElementById('registro-datos').textContent);
  const pasos = [...form.querySelectorAll('[data-paso]')];
  const imagenes = [...document.querySelectorAll('[data-banner]')];
  let actual = Number(form.dataset.pasoInicial) || 1;
  let capa = 0;

  const ponerBanner = (paso, animar) => {
    const url = datos.banners[(paso - 1) % datos.banners.length];
    if (!animar) { imagenes[0].style.backgroundImage = `url("${url}")`; return; }
    capa = 1 - capa;
    imagenes[capa].style.backgroundImage = `url("${url}")`;
    imagenes.forEach((img, i) => img.classList.toggle('activa', i === capa));
  };

  const mostrar = (paso, animar = true) => {
    const direccion = paso > actual ? 'adelante' : 'atras';
    actual = paso;
    pasos.forEach((p) => {
      const visible = Number(p.dataset.paso) === paso;
      p.hidden = !visible;
      p.classList.toggle(`entra-${direccion}`, visible && animar);
      if (!visible) p.classList.remove('entra-adelante', 'entra-atras');
    });
    ponerBanner(paso, animar);
    if (paso === 3) pintarPermisos();
    pasos[paso - 1].querySelector('input:not([type="hidden"]):not([type="file"]):not([disabled]), select')?.focus({ preventScroll: true });
  };

  // Lo que podrá hacer según el rol elegido
  const rolElegido = () => form.querySelector('input[name="rol"]:checked')?.value || form.querySelector('input[type="hidden"][name="rol"]')?.value || '';
  const pintarPermisos = () => {
    const rol = rolElegido();
    form.querySelector('[data-rol-nombre]').textContent = (datos.roles[rol] || '').toLowerCase();
    form.querySelector('[data-permisos]').innerHTML = (datos.permisos[rol] || []).map((p) => `
      <li><span class="registro__lista-icono"><svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg></span>${escaparHtml(p)}</li>`).join('');
  };

  // Cédula obligatoria para médico
  const cedula = form.querySelector('[data-cedula-campo]');
  const notaCedula = form.querySelector('[data-cedula-nota]');
  const actualizarCedula = () => {
    const obligatoria = form.querySelector('input[name="rol"]:checked')?.dataset.cedula === '1';
    cedula.required = obligatoria;
    notaCedula.textContent = obligatoria ? '(obligatoria para médico)' : '(opcional)';
  };
  form.querySelectorAll('input[name="rol"]').forEach((r) => r.addEventListener('change', actualizarCedula));
  cedula.addEventListener('input', () => { cedula.value = cedula.value.replace(/\D/g, ''); });
  actualizarCedula();

  // Validación del paso antes de avanzar
  const validarPaso = (paso) => {
    const seccion = pasos[paso - 1];
    for (const campo of seccion.querySelectorAll('input:not([type="hidden"]):not([disabled])')) {
      if (campo.type === 'radio') {
        if (campo.required && !seccion.querySelector(`input[name="${campo.name}"]:checked`)) {
          mostrarNotificacion('error', 'Elige tu rol en el establecimiento.');
          return false;
        }
        continue;
      }
      if (campo.name === 'cedula' && campo.value && !/^\d{6,10}$/.test(campo.value)) {
        campo.focus();
        mostrarNotificacion('error', 'La cédula profesional debe tener de 6 a 10 dígitos.');
        return false;
      }
      if (!campo.checkValidity()) {
        campo.focus();
        campo.classList.add('invalido');
        mostrarNotificacion('error', campo.type === 'email' ? 'Escribe un correo de trabajo válido.' : 'Completa este campo para continuar.');
        return false;
      }
    }
    return true;
  };
  form.addEventListener('input', (ev) => ev.target.classList?.remove('invalido'));
  form.querySelectorAll('[data-siguiente]').forEach((b) => b.addEventListener('click', () => { if (validarPaso(actual)) mostrar(actual + 1); }));
  form.querySelectorAll('[data-anterior]').forEach((b) => b.addEventListener('click', () => mostrar(actual - 1)));
  // Enter en un campo avanza al siguiente paso en lugar de enviar
  form.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && actual < 3 && ev.target.matches('input')) {
      ev.preventDefault();
      pasos[actual - 1].querySelector('[data-siguiente]').click();
    }
  });
  form.addEventListener('submit', (ev) => {
    const clave = form.querySelector('#r-clave');
    const confirmar = form.querySelector('#r-confirmar');
    if (!validarPaso(3)) { ev.preventDefault(); return; }
    if (clave.value !== confirmar.value) {
      ev.preventDefault();
      confirmar.focus();
      mostrarNotificacion('error', 'Las contraseñas no coinciden.');
    }
  });

  // Vista previa de la foto de perfil
  const entrada = form.querySelector('[data-foto-entrada]');
  const vista = form.querySelector('[data-foto-vista]');
  let urlVista = null;
  entrada.addEventListener('change', () => {
    const archivo = entrada.files[0];
    if (urlVista) URL.revokeObjectURL(urlVista);
    if (!archivo) { vista.textContent = '?'; return; }
    if (archivo.size > 5 * 1024 * 1024) { mostrarNotificacion('error', 'La foto pesa más de 5 MB.'); entrada.value = ''; return; }
    urlVista = URL.createObjectURL(archivo);
    vista.innerHTML = `<img src="${urlVista}" alt="">`;
  });

  // Iniciales en la vista previa mientras no haya foto
  const iniciales = () => {
    if (entrada.files[0]) return;
    const n = form.querySelector('#r-nombre').value.trim().charAt(0);
    const a = form.querySelector('#r-apellidos').value.trim().charAt(0);
    vista.textContent = (n + a).toUpperCase() || '?';
  };
  form.querySelector('#r-nombre').addEventListener('input', iniciales);
  form.querySelector('#r-apellidos').addEventListener('input', iniciales);
  iniciales();

  mostrar(actual, false);
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-registro]').forEach(iniciarRegistro);
});


/* ---------- Secciones de la barra lateral que se pliegan (se recuerda en este navegador) ---------- */
function iniciarSeccionesPlegables() {
  const clave = `femeci-lateral-plegadas-${document.body.dataset.usuario ?? ''}`;
  let plegadas = [];
  try { plegadas = JSON.parse(localStorage.getItem(clave) || '[]'); } catch (e) { /* opcional */ }
  document.querySelectorAll('[data-plegable]').forEach((seccion) => {
    const boton = seccion.querySelector('.lateral-seccion__alternar');
    const fijar = (plegada) => {
      seccion.classList.toggle('plegada', plegada);
      boton.setAttribute('aria-expanded', String(!plegada));
    };
    fijar(plegadas.includes(seccion.dataset.plegable));
    boton.addEventListener('click', () => {
      const plegada = !seccion.classList.contains('plegada');
      fijar(plegada);
      plegadas = plegadas.filter((p) => p !== seccion.dataset.plegable);
      if (plegada) plegadas.push(seccion.dataset.plegable);
      try { localStorage.setItem(clave, JSON.stringify(plegadas)); } catch (e) { /* opcional */ }
    });
  });
}
document.addEventListener('DOMContentLoaded', iniciarSeccionesPlegables);

/* ---------- Pacientes por estado: panel que se despliega con el botón "i" ---------- */
function iniciarPanelGeo() {
  const panel = document.querySelector('[data-geo-panel]');
  const boton = document.querySelector('[data-geo-alternar]');
  if (!panel || !boton) return;
  const fijar = (abierto, desplazar) => {
    panel.classList.toggle('abierto', abierto);
    boton.setAttribute('aria-expanded', String(abierto));
    boton.classList.toggle('activo', abierto);
    if (abierto && desplazar) setTimeout(() => panel.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
  };
  boton.addEventListener('click', () => fijar(!panel.classList.contains('abierto'), true));
  // "Pacientes por estado" en la barra lateral lleva a #mapa: abre el panel
  const revisarAncla = () => { if (location.hash === '#mapa') fijar(true, true); };
  window.addEventListener('hashchange', revisarAncla);
  revisarAncla();
}
document.addEventListener('DOMContentLoaded', iniciarPanelGeo);
