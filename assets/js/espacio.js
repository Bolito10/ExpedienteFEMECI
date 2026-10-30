/* ==========================================================================
   Expedientes Clínicos — espacio de trabajo (como Notion)
   Árbol de páginas en la barra lateral, editor por bloques, bases de datos,
   compartir, actividad y presencia. Usa BASE, TOKEN_CSRF, escaparHtml,
   mostrarNotificacion y quitarConAnimacion de app.js.
   ========================================================================== */

const ICONOS_JS = (() => { try { return JSON.parse(document.getElementById('iconos-js').textContent); } catch (e) { return {}; } })();
/* Trazos extra para los menús (mismo estilo de línea que app/vistas/plantilla/iconos.php) */
const TRAZOS_MENU = {
  copiar: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
  duplicar: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2M14 11.5v5M11.5 14h5"/>',
  cortar: '<circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><path d="M8 7.5 20 18M8 16.5 20 6"/>',
  arriba: '<path d="M12 19V5M6 11l6-6 6 6"/>',
  abajo: '<path d="M12 5v14M6 13l6 6 6-6"/>',
  izquierda: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
  derecha: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  insertar_arriba: '<path d="M4 5h16M12 10v9M7.5 14.5h9"/>',
  insertar_abajo: '<path d="M4 19h16M12 5v9M7.5 9.5h9"/>',
  columna_izq: '<rect x="12" y="4" width="8" height="16" rx="1.5"/><path d="M6 9v6M3 12h6"/>',
  columna_der: '<rect x="4" y="4" width="8" height="16" rx="1.5"/><path d="M18 9v6M15 12h6"/>',
  convertir: '<path d="M4 9h13l-3-3M20 15H7l3 3"/>',
  paleta: '<path d="M12 3a9 9 0 1 0 0 18c1 0 1.6-.7 1.6-1.6 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.6 1.6-1.6h1.9a4.7 4.7 0 0 0 4.7-4.7C21 6.4 17 3 12 3z"/><circle cx="7.5" cy="11.5" r="1"/><circle cx="10" cy="7.5" r="1"/><circle cx="14.5" cy="7.5" r="1"/>',
  descargar: '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
  exportar: '<path d="M12 15V4M7 9l5-5 5 5M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"/>',
  externo: '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4"/>',
  abrir: '<path d="M5 12h12M12 6l6 6-6 6"/>',
  pestana: '<rect x="3" y="5" width="18" height="15" rx="2"/><path d="M3 9.5h18M9 5v4.5"/>',
  mover: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 13h7M13.5 10.5 16 13l-2.5 2.5"/>',
  pie: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M7 20h10"/>',
  chispa: '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 15l.7 2.3L22 18l-2.3.7L19 21l-.7-2.3L16 18l2.3-.7z"/>',
  orden_asc: '<path d="M7 20V4M3.5 7.5 7 4l3.5 3.5M13 6h4M13 12h6M13 18h8"/>',
  orden_desc: '<path d="M7 4v16M3.5 16.5 7 20l3.5-3.5M13 6h8M13 12h6M13 18h4"/>',
  filtro: '<path d="M4 5h16l-6 7.5V19l-4 1.5v-8z"/>',
  ocultar: '<path d="M3 3l18 18M10.6 5.1A9.6 9.6 0 0 1 12 5c6 0 9.5 7 9.5 7a17 17 0 0 1-3 3.9M6.6 6.6A17 17 0 0 0 2.5 12S6 19 12 19a9.6 9.6 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
  ojo: '<path d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z"/><circle cx="12" cy="12" r="3"/>',
  fijar: '<path d="M9 4h6l-1 6 4 3v2H6v-2l4-3z"/><path d="M12 15v6"/>',
  markdown: '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M6 15V9l2.5 3L11 9v6M16 9v6M14 13l2 2 2-2"/>',
  ventana: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 8.5h18"/>',
  candado_abierto: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/>',
};
const ico = (nombre) => ICONOS_JS[nombre] || (TRAZOS_MENU[nombre] ? `<svg class="icono" viewBox="0 0 24 24" aria-hidden="true">${TRAZOS_MENU[nombre]}</svg>` : '');
const USUARIO_ID = Number(document.body.dataset.usuario || 0);
const PAGINA_ID = document.body.dataset.pagina ? Number(document.body.dataset.pagina) : null;
const TIPOS = {
  carpeta: { icono: 'r_carpeta', nueva: 'Nueva carpeta', texto: 'Carpeta' },
  nota: { icono: 'r_documento', nueva: 'Nueva página', texto: 'Página' },
  base_datos: { icono: 'r_tabla', nueva: 'Nueva base de datos', texto: 'Base de datos' },
};
const COLORES_AVATAR = ['#dcd6ec', '#cddff3', '#d3e8dc', '#efdccb', '#ead3e0', '#e4dfc7', '#cfe6e6', '#e0d5f1'];
const colorAvatar = (id) => COLORES_AVATAR[id % COLORES_AVATAR.length];
/** Iniciales como en PHP: nombre + primer apellido ("José Luis Pérez" → "JP"). */
const inicialesDe = (nombre) => {
  const partes = String(nombre || '?').trim().split(/\s+/);
  const ultima = partes.length > 2 ? partes[partes.length - 2] : (partes[1] || '');
  return (partes[0].charAt(0) + ultima.charAt(0)).toUpperCase();
};
const avatarHtml = (id, nombre, clase = '', foto = null) => `<span class="avatar ${clase}" style="--avatar:${colorAvatar(id)}" aria-hidden="true">${foto ? `<img src="${escaparHtml(foto)}" alt="">` : escaparHtml(inicialesDe(nombre))}</span>`;
const tituloDe = (n) => n.titulo || TIPOS[n.tipo].nueva;
const haceTexto = (texto) => (texto === 'hace un momento' ? 'Ahora mismo' : texto.charAt(0).toUpperCase() + texto.slice(1));

/** POST JSON con el token CSRF. Lanza Error con el mensaje del servidor si falla. */
async function api(ruta, cuerpo) {
  const r = await fetch(`${BASE}/api/${ruta}`, {
    method: cuerpo ? 'POST' : 'GET',
    headers: cuerpo ? { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF } : {},
    body: cuerpo ? JSON.stringify(cuerpo) : undefined,
  });
  let datos = {};
  try { datos = await r.json(); } catch (e) { /* sin cuerpo */ }
  if (!r.ok || datos.ok === false) {
    const error = new Error(datos.error || 'No se pudo completar la acción.');
    error.estado = r.status;
    throw error;
  }
  return datos;
}

/* ---------- Popover flotante (uno a la vez), anclado a un botón o a un punto ---------- */
let popoverActual = null;

function cerrarPopover() {
  if (!popoverActual) return;
  const { el, ancla, alCerrar, extras } = popoverActual;
  popoverActual = null;
  ancla?.setAttribute?.('aria-expanded', 'false');
  (extras || []).forEach((x) => x.remove());
  quitarConAnimacion(el, 'cerrando');
  alCerrar?.();
}

/**
 * Abre un popover junto a `ancla` (un elemento o anclaEnPunto(x, y)). `contenido` es HTML o un nodo.
 * opciones: { clase, alinear: 'izquierda'|'derecha', arriba: bool, alCerrar, rol, separacion }
 */
function abrirPopover(ancla, contenido, opciones = {}) {
  cerrarPopover();
  const el = document.createElement('div');
  el.className = `popover ${opciones.clase || ''}`;
  el.setAttribute('role', opciones.rol || 'dialog');
  if (typeof contenido === 'string') el.innerHTML = contenido; else el.appendChild(contenido);
  document.body.appendChild(el);
  popoverActual = { el, ancla, alCerrar: opciones.alCerrar, extras: [] };
  ancla?.setAttribute?.('aria-expanded', 'true');
  colocarPopover(el, ancla, opciones);
  return el;
}

/** Ancla virtual en un punto de la pantalla (menús de clic derecho). */
const anclaEnPunto = (x, y) => ({ enPunto: true, getBoundingClientRect: () => new DOMRect(x, y, 0, 0) });

function colocarPopover(el, ancla, opciones = {}) {
  const r = ancla.getBoundingClientRect();
  const sep = opciones.separacion ?? (ancla.enPunto ? 2 : 6);
  const ancho = el.offsetWidth;
  const alto = el.offsetHeight;
  let x = opciones.alinear === 'derecha' ? r.right - ancho : r.left;
  x = Math.max(8, Math.min(x, window.innerWidth - ancho - 8));
  let y = opciones.arriba ? r.top - alto - sep : r.bottom + sep;
  let haciaArriba = !!opciones.arriba;
  if (!opciones.arriba && y + alto > window.innerHeight - 8) { y = r.top - alto - sep; haciaArriba = true; }
  if (haciaArriba && y < 8) { y = opciones.arriba ? r.bottom + sep : Math.max(8, window.innerHeight - alto - 8); haciaArriba = false; }
  el.style.left = `${Math.round(x)}px`;
  el.style.top = `${Math.round(Math.max(8, y))}px`;
  el.style.transformOrigin = `${opciones.alinear === 'derecha' ? 'right' : 'left'} ${haciaArriba ? 'bottom' : 'top'}`;
}

document.addEventListener('pointerdown', (ev) => {
  if (!popoverActual) return;
  const { el, ancla, extras } = popoverActual;
  if (el.contains(ev.target) || extras.some((x) => x.contains(ev.target)) || ancla?.contains?.(ev.target)) return;
  cerrarPopover();
}, true);
document.addEventListener('keydown', (ev) => {
  if (ev.key !== 'Escape' || !popoverActual) return;
  ev.stopPropagation();
  ev.preventDefault();
  if (popoverActual.alEscape?.()) return;
  const volver = popoverActual.volverA;
  cerrarPopover();
  volver?.focus?.({ preventScroll: true });
}, true);
window.addEventListener('resize', () => { if (popoverActual?.extras.length) cerrarPopover(); });

const SVG_MARCA = '<svg class="icono menu-flotante__marca" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>';
const SVG_SUBMENU = '<svg class="icono menu-flotante__flecha" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>';

/** Quita opciones vacías y separadores sobrantes (al inicio, al final, repetidos o pegados a un título). */
function limpiarItems(lista) {
  const salida = [];
  (lista || []).filter(Boolean).forEach((item) => {
    const previo = salida[salida.length - 1];
    if (item === 'separador' && (!previo || previo === 'separador' || previo.titulo)) return;
    salida.push(item);
  });
  while (salida.length && (salida[salida.length - 1] === 'separador' || salida[salida.length - 1].titulo)) salida.pop();
  return salida;
}

/**
 * Menú de opciones en un popover, con submenús, búsqueda y teclado (flechas, Inicio/Fin, Entrar, Esc, letra inicial).
 * items (arreglo o función que lo devuelve):
 *   { icono, muestra: {color, fondo}, texto, detalle, accion, peligro, marcado, deshabilitado,
 *     submenu: items | () => items, mantener: true (no cierra; vuelve a pintar el menú) }
 *   { campo: true, valor, placeholder, etiqueta, tipo, maximo, alGuardar(valor), alCambiar(valor) }
 *   { titulo } · { nota } · 'separador' · null/false (se ignoran)
 * opciones: { alinear, arriba, clase, buscar: 'placeholder', alCerrar, volverA, seleccionar: true }
 */
function abrirMenu(ancla, items, opciones = {}) {
  const fuente = typeof items === 'function' ? items : () => items;
  const pila = [];            // submenús abiertos: [{ el, boton }] (índice = nivel del menú que lo abrió)
  let guardarCampo = null;
  let cancelarCampo = false;
  let cierreSub = null;
  let raiz = null;

  const botonesDe = (caja) => [...caja.querySelectorAll(':scope > button')].filter((b) => !b.disabled && !b.hidden);
  const cerrarSubDesde = (nivel) => {
    clearTimeout(cierreSub);
    while (pila.length > nivel) {
      const s = pila.pop();
      s.el.remove();
      if (popoverActual) popoverActual.extras = popoverActual.extras.filter((x) => x !== s.el);
      s.boton.setAttribute('aria-expanded', 'false');
      s.boton.classList.remove('abierto');
    }
  };

  const abrirSub = (boton, item, nivel, enfocar) => {
    clearTimeout(cierreSub);
    if (pila[nivel]?.boton === boton) { if (enfocar) botonesDe(pila[nivel].el.firstElementChild)[0]?.focus({ preventScroll: true }); return; }
    cerrarSubDesde(nivel);
    if (!popoverActual) return;
    const sub = typeof item.submenu === 'function' ? item.submenu() : item.submenu;
    const el = document.createElement('div');
    el.className = 'popover popover--menu popover--sub';
    el.setAttribute('role', 'menu');
    el.appendChild(crearLista(sub, nivel + 1));
    el.addEventListener('mouseenter', () => clearTimeout(cierreSub));
    document.body.appendChild(el);
    popoverActual.extras.push(el);
    pila[nivel] = { el, boton };
    boton.setAttribute('aria-expanded', 'true');
    boton.classList.add('abierto');
    const r = boton.getBoundingClientRect();
    const ancho = el.offsetWidth;
    const alto = el.offsetHeight;
    let x = r.right + 2;
    if (x + ancho > window.innerWidth - 8) x = Math.max(8, r.left - ancho - 2);
    const y = Math.max(8, Math.min(r.top - 5, window.innerHeight - alto - 8));
    el.style.left = `${Math.round(x)}px`;
    el.style.top = `${Math.round(y)}px`;
    el.style.transformOrigin = `${x < r.left ? 'right' : 'left'} top`;
    if (enfocar) botonesDe(el.firstElementChild)[0]?.focus({ preventScroll: true });
  };

  const crearLista = (lista, nivel) => {
    const caja = document.createElement('div');
    caja.className = 'menu-flotante';
    limpiarItems(lista).forEach((item) => {
      if (item === 'separador') { caja.insertAdjacentHTML('beforeend', '<div class="menu-flotante__separador" role="separator"></div>'); return; }
      if (item.titulo) { caja.insertAdjacentHTML('beforeend', `<div class="menu-flotante__titulo">${escaparHtml(item.titulo)}</div>`); return; }
      if (item.nota) { caja.insertAdjacentHTML('beforeend', `<div class="menu-flotante__nota">${escaparHtml(item.nota)}</div>`); return; }
      if (item.campo) {
        const entrada = document.createElement('input');
        entrada.className = 'menu-flotante__entrada';
        entrada.type = item.tipo || 'text';
        entrada.value = item.valor ?? '';
        entrada.placeholder = item.placeholder || '';
        entrada.maxLength = item.maximo || 200;
        entrada.setAttribute('aria-label', item.etiqueta || item.placeholder || 'Valor');
        entrada.dataset.campo = '1';
        if (item.tipo === 'number') entrada.step = 'any';
        const original = entrada.value;
        if (item.alGuardar) guardarCampo = () => { const v = entrada.value.trim(); if (v && v !== original) item.alGuardar(v); };
        if (item.alCambiar) entrada.addEventListener('input', () => item.alCambiar(entrada.value));
        entrada.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter') { ev.preventDefault(); cerrarPopover(); }
          if (ev.key === 'ArrowDown') { ev.preventDefault(); botonesDe(caja)[0]?.focus(); }
        });
        caja.appendChild(entrada);
        return;
      }
      const b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', item.marcado !== undefined ? 'menuitemradio' : 'menuitem');
      if (item.marcado !== undefined) b.setAttribute('aria-checked', String(!!item.marcado));
      if (item.peligro) b.className = 'peligro';
      b.disabled = !!item.deshabilitado;
      b.dataset.texto = normalizarTexto(item.texto || '');
      const icono = item.muestra
        ? `<span class="menu-muestra${item.muestra.fondo ? ' menu-muestra--fondo' : ''}" data-color="${escaparHtml(item.muestra.color || '')}" aria-hidden="true">A</span>`
        : (item.icono ? ico(item.icono) : '');
      b.innerHTML = `${icono}<span>${escaparHtml(item.texto)}</span>${item.detalle ? `<small>${escaparHtml(item.detalle)}</small>` : ''}${item.marcado ? SVG_MARCA : ''}${item.submenu ? SVG_SUBMENU : ''}`;
      b.addEventListener('mousemove', () => {
        if (document.activeElement !== b && !document.activeElement?.dataset?.campo && !document.activeElement?.dataset?.buscar) b.focus({ preventScroll: true });
      });
      if (item.submenu) {
        b.setAttribute('aria-haspopup', 'menu');
        b.setAttribute('aria-expanded', 'false');
        let espera = null;
        b.addEventListener('mouseenter', () => { clearTimeout(espera); espera = setTimeout(() => abrirSub(b, item, nivel, false), 120); });
        b.addEventListener('mouseleave', () => clearTimeout(espera));
        b.addEventListener('click', () => abrirSub(b, item, nivel, true));
      } else {
        b.addEventListener('mouseenter', () => {
          if (pila.length > nivel) { clearTimeout(cierreSub); cierreSub = setTimeout(() => cerrarSubDesde(nivel), 160); }
        });
        b.addEventListener('click', () => {
          if (item.mantener) {
            item.accion?.();
            const indice = botonesDe(caja).indexOf(b);
            repintar(nivel === 0 ? indice : -1);
            return;
          }
          cerrarPopover();
          item.accion?.();
        });
      }
      caja.appendChild(b);
    });
    if (!caja.children.length) caja.insertAdjacentHTML('beforeend', '<div class="menu-flotante__nota">Sin opciones</div>');
    caja.addEventListener('keydown', (ev) => teclas(ev, caja, nivel));
    return caja;
  };

  const teclas = (ev, caja, nivel) => {
    if (ev.target.dataset?.campo || ev.target.dataset?.buscar) return;
    const botones = botonesDe(caja);
    if (!botones.length) return;
    const i = botones.indexOf(document.activeElement);
    const ir = (j) => { ev.preventDefault(); botones[(j + botones.length) % botones.length]?.focus({ preventScroll: false }); };
    if (ev.key === 'ArrowDown' || (ev.key === 'Tab' && !ev.shiftKey)) { ir(i + 1); return; }
    if (ev.key === 'ArrowUp' || (ev.key === 'Tab' && ev.shiftKey)) { ir(i < 0 ? -1 : i - 1); return; }
    if (ev.key === 'Home') { ir(0); return; }
    if (ev.key === 'End') { ir(-1); return; }
    if (ev.key === 'ArrowRight' && document.activeElement?.getAttribute('aria-haspopup') === 'menu') { ev.preventDefault(); document.activeElement.click(); return; }
    if (ev.key === 'ArrowLeft' && nivel > 0) { ev.preventDefault(); const padre = pila[nivel - 1]?.boton; cerrarSubDesde(nivel - 1); padre?.focus(); return; }
    // Letra inicial: salta a la siguiente opción que empieza así (o la escribe en la búsqueda)
    if (ev.key.length === 1 && /\S/.test(ev.key) && !ev.ctrlKey && !ev.metaKey && !ev.altKey) {
      const buscar = nivel === 0 && raiz.querySelector('[data-buscar]');
      if (buscar) { ev.preventDefault(); buscar.focus(); buscar.value += ev.key; buscar.dispatchEvent(new Event('input')); return; }
      const letra = normalizarTexto(ev.key);
      const orden = [...botones.slice(i + 1), ...botones.slice(0, i + 1)];
      const destino = orden.find((b) => b.dataset.texto.startsWith(letra));
      if (destino) { ev.preventDefault(); destino.focus(); }
    }
  };

  const filtrar = (caja, termino) => {
    const t = normalizarTexto(termino.trim());
    caja.querySelectorAll(':scope > button').forEach((b) => { b.hidden = !!t && !b.dataset.texto.includes(t); });
    caja.querySelectorAll(':scope > .menu-flotante__titulo, :scope > .menu-flotante__separador').forEach((x) => { x.hidden = !!t; });
    let vacio = caja.querySelector(':scope > .menu-flotante__sin-resultados');
    const hay = botonesDe(caja).length > 0;
    if (!hay && !vacio) caja.insertAdjacentHTML('beforeend', '<div class="menu-flotante__nota menu-flotante__sin-resultados">Sin resultados</div>');
    if (hay && vacio) vacio.remove();
  };

  const construir = () => {
    const caja = crearLista(fuente(), 0);
    if (opciones.buscar) {
      const buscar = document.createElement('input');
      buscar.className = 'menu-flotante__entrada menu-flotante__buscar';
      buscar.placeholder = opciones.buscar;
      buscar.setAttribute('aria-label', opciones.buscar);
      buscar.dataset.buscar = '1';
      buscar.addEventListener('input', () => filtrar(caja, buscar.value));
      buscar.addEventListener('keydown', (ev) => {
        if (ev.key === 'ArrowDown') { ev.preventDefault(); botonesDe(caja)[0]?.focus(); }
        if (ev.key === 'Enter') { ev.preventDefault(); botonesDe(caja)[0]?.click(); }
      });
      caja.prepend(buscar);
    }
    return caja;
  };

  const repintar = (indice) => {
    cerrarSubDesde(0);
    const nueva = construir();
    raiz.replaceWith(nueva);
    raiz = nueva;
    const botones = botonesDe(raiz);
    (botones[Math.min(Math.max(indice, 0), botones.length - 1)] || raiz.querySelector('input'))?.focus({ preventScroll: true });
  };

  raiz = construir();
  const el = abrirPopover(ancla, raiz, {
    ...opciones, rol: 'menu', clase: `popover--menu ${opciones.clase || ''}`,
    alCerrar: () => { clearTimeout(cierreSub); if (!cancelarCampo) guardarCampo?.(); opciones.alCerrar?.(); },
  });
  popoverActual.volverA = opciones.volverA || null;
  popoverActual.alEscape = () => {
    if (pila.length) { const s = pila[pila.length - 1]; cerrarSubDesde(pila.length - 1); s.boton.focus(); return true; }
    if (document.activeElement?.dataset?.campo) cancelarCampo = true;
    return false;
  };
  const campo = raiz.querySelector('[data-campo], [data-buscar]');
  if (campo) {
    campo.focus({ preventScroll: true });
    if (opciones.seleccionar) campo.select();
  } else {
    botonesDe(raiz)[0]?.focus({ preventScroll: true });
  }
  return el;
}

/** Confirmación con el estilo de la app (sin diálogos nativos del navegador). */
function confirmar(ancla, { titulo, texto, boton, alinear }) {
  return new Promise((resolver) => {
    let respondido = false;
    const el = abrirPopover(ancla, `
      <div class="confirmar">
        <p class="confirmar__titulo">${escaparHtml(titulo)}</p>
        <p class="confirmar__texto">${escaparHtml(texto)}</p>
        <div class="confirmar__botones">
          <button type="button" class="boton boton--sutil" data-no>Cancelar</button>
          <button type="button" class="boton boton--peligro" data-si>${escaparHtml(boton)}</button>
        </div>
      </div>`, { alinear, alCerrar: () => { if (!respondido) resolver(false); } });
    el.querySelector('[data-no]').addEventListener('click', () => cerrarPopover());
    el.querySelector('[data-si]').addEventListener('click', () => { respondido = true; cerrarPopover(); resolver(true); });
    el.querySelector('[data-no]').focus();
  });
}

/* ---------- Crear páginas ---------- */
async function crearPagina(datos) {
  try {
    const r = await api('paginas/paginas.php', { accion: 'crear', ...datos });
    location.href = r.url;
  } catch (e) { mostrarNotificacion('error', e.message); }
}

/** Menú "¿Qué quieres crear?": página, carpeta o base de datos. */
function menuNuevaPagina(ancla, destino, opciones = {}) {
  abrirMenu(ancla, [
    { icono: 'documento', texto: 'Página', accion: () => crearPagina({ tipo: 'nota', ...destino }) },
    { icono: 'carpeta', texto: 'Carpeta', accion: () => crearPagina({ tipo: 'carpeta', ...destino }) },
    { icono: 'tabla', texto: 'Base de datos', accion: () => crearPagina({ tipo: 'base_datos', ...destino }) },
  ], opciones);
}

document.addEventListener('click', (ev) => {
  const directa = ev.target.closest('[data-pagina-nueva]');
  if (directa) {
    ev.preventDefault();
    const padre = directa.dataset.padre ? Number(directa.dataset.padre) : undefined;
    crearPagina({ tipo: directa.dataset.paginaNueva, padre_id: padre, seccion: directa.dataset.seccion });
    return;
  }
  const menu = ev.target.closest('[data-pagina-menu-nueva]');
  if (menu) {
    ev.preventDefault();
    const padre = menu.dataset.padre ? Number(menu.dataset.padre) : undefined;
    menuNuevaPagina(menu, { padre_id: padre, seccion: menu.dataset.seccion });
  }
});

/* ---------- Árbol de páginas en la barra lateral ---------- */
const Arbol = {
  datos: null,
  abiertos: new Set(),
  clave: `femeci-arbol-abiertos-${USUARIO_ID}`,

  iniciar() {
    const fuente = document.getElementById('arbol-paginas');
    if (!fuente) return;
    this.datos = JSON.parse(fuente.textContent);
    try { this.abiertos = new Set(JSON.parse(localStorage.getItem(this.clave) || '[]')); } catch (e) { /* opcional */ }
    // La rama de la página abierta queda desplegada
    if (PAGINA_ID) this.rutaHasta(PAGINA_ID)?.slice(0, -1).forEach((id) => this.abiertos.add(id));
    this.pintar();
    this.iniciarEventos();
  },

  guardarAbiertos() { try { localStorage.setItem(this.clave, JSON.stringify([...this.abiertos])); } catch (e) { /* opcional */ } },

  /** Ids desde la raíz hasta la página (o null si no está en el árbol). */
  rutaHasta(id) {
    const buscar = (nodos, camino) => {
      for (const n of nodos) {
        if (n.id === id) return [...camino, n.id];
        const r = buscar(n.hijos, [...camino, n.id]);
        if (r) return r;
      }
      return null;
    };
    return buscar(this.datos.privado, []) || buscar(this.datos.compartido, []);
  },

  buscar(id) {
    let encontrado = null;
    const recorrer = (nodos, padre, seccion) => nodos.forEach((n, i) => {
      if (n.id === id) encontrado = { nodo: n, padre, hermanos: nodos, indice: i, seccion };
      recorrer(n.hijos, n, seccion);
    });
    recorrer(this.datos.privado, null, 'privado');
    recorrer(this.datos.compartido, null, 'compartido');
    return encontrado;
  },

  esFavorita(id) { return this.datos.favoritos.some((f) => f.id === id); },

  async recargar() {
    try {
      const r = await api('paginas/paginas.php');
      this.datos = r.arbol;
      this.pintar();
      return r;
    } catch (e) { return null; }
  },

  pintar() {
    ['privado', 'compartido', 'favoritos'].forEach((seccion) => {
      const caja = document.querySelector(`[data-arbol="${seccion}"]`);
      if (!caja) return;
      caja.innerHTML = this.datos[seccion].map((n) => this.nodoHtml(n, 0, seccion)).join('');
    });
    const favoritos = document.querySelector('[data-seccion-arbol="favoritos"]');
    if (favoritos) favoritos.hidden = !this.datos.favoritos.length;
  },

  nodoHtml(n, nivel, seccion) {
    const contenedor = n.tipo !== 'base_datos' && seccion !== 'favoritos';
    const abierto = contenedor && this.abiertos.has(n.id);
    const editable = n.nivel !== 'ver' && seccion !== 'favoritos';
    const activo = n.id === PAGINA_ID;
    return `
      <div class="arbol__nodo" data-id="${n.id}" data-tipo="${n.tipo}" data-seccion="${seccion}" role="treeitem"${contenedor ? ` aria-expanded="${abierto}"` : ''}>
        <a class="arbol__fila${activo ? ' activo' : ''}" href="${BASE}/paginas/ver.php?id=${n.id}" style="--nivel:${nivel}"${editable ? ' draggable="true"' : ''}${activo ? ' aria-current="page"' : ''}>
          <span class="arbol__icono">${ico(TIPOS[n.tipo].icono)}${contenedor ? `<button type="button" class="arbol__alternar" data-alternar aria-label="${abierto ? 'Contraer' : 'Expandir'}">${ico('chevron')}</button>` : ''}</span>
          <span class="arbol__titulo">${escaparHtml(tituloDe(n))}</span>
          <span class="arbol__acciones">
            <button type="button" data-nodo-menu title="Eliminar, renombrar y más">${ico('mas')}</button>
            ${contenedor && editable ? `<button type="button" data-nodo-nueva title="Agregar una página dentro">${ico('agregar')}</button>` : ''}
          </span>
        </a>
        ${contenedor ? `<div class="arbol__hijos" role="group"${abierto ? '' : ' hidden'}>${n.hijos.length
          ? n.hijos.map((h) => this.nodoHtml(h, nivel + 1, seccion)).join('')
          : `<div class="arbol__vacio" style="--nivel:${nivel + 1}">No hay páginas dentro</div>`}</div>` : ''}
      </div>`;
  },

  alternar(nodoEl) {
    const id = Number(nodoEl.dataset.id);
    const abrir = nodoEl.getAttribute('aria-expanded') !== 'true';
    nodoEl.setAttribute('aria-expanded', String(abrir));
    nodoEl.querySelector(':scope > .arbol__hijos').hidden = !abrir;
    if (abrir) this.abiertos.add(id); else this.abiertos.delete(id);
    this.guardarAbiertos();
  },

  renombrarEnLinea(nodoEl) {
    const fila = nodoEl.querySelector(':scope > .arbol__fila');
    const titulo = fila.querySelector('.arbol__titulo');
    const info = this.buscar(Number(nodoEl.dataset.id));
    if (!info) return;
    const entrada = document.createElement('input');
    entrada.className = 'arbol__renombrar';
    entrada.value = info.nodo.titulo;
    entrada.placeholder = TIPOS[info.nodo.tipo].nueva;
    entrada.maxLength = 200;
    titulo.replaceWith(entrada);
    fila.draggable = false;
    entrada.focus();
    entrada.select();
    let hecho = false;
    const terminar = async (guardar) => {
      if (hecho) return;
      hecho = true;
      if (guardar && entrada.value.trim() !== info.nodo.titulo) {
        try {
          const r = await api('paginas/paginas.php', { accion: 'renombrar', id: info.nodo.id, titulo: entrada.value });
          if (info.nodo.id === PAGINA_ID) Hoja.tituloCambiado(r.titulo, true);
        } catch (e) { mostrarNotificacion('error', e.message); }
      }
      await this.recargar();
    };
    entrada.addEventListener('keydown', (ev) => {
      ev.stopPropagation();
      if (ev.key === 'Enter') { ev.preventDefault(); terminar(true); }
      if (ev.key === 'Escape') { ev.preventDefault(); terminar(false); }
    });
    entrada.addEventListener('click', (ev) => ev.preventDefault());
    entrada.addEventListener('blur', () => terminar(true));
  },

  /** Menú de una página del árbol ("…" o clic derecho): ver menuPagina. */
  menuNodo(ancla, nodoEl) {
    const id = Number(nodoEl.dataset.id);
    const fila = nodoEl.querySelector(':scope > .arbol__fila');
    fila.classList.add('con-menu');
    // En Favoritos se renombra en su lugar original del árbol (si está a la vista)
    const enArbol = nodoEl.dataset.seccion === 'favoritos'
      ? [...document.querySelectorAll(`[data-arbol="privado"] .arbol__nodo[data-id="${id}"], [data-arbol="compartido"] .arbol__nodo[data-id="${id}"]`)].find((n) => n.offsetParent)
      : nodoEl;
    menuPagina(ancla, id, { nodoEl: enArbol, alCerrar: () => fila.classList.remove('con-menu') });
  },

  /* Arrastrar y soltar: dentro de otra página, antes o después de una hermana, o a la raíz de una sección */
  zona: null,
  limpiarZona() {
    document.querySelectorAll('.arbol__fila--antes, .arbol__fila--despues, .arbol__fila--dentro, .lateral-seccion--soltar')
      .forEach((el) => el.classList.remove('arbol__fila--antes', 'arbol__fila--despues', 'arbol__fila--dentro', 'lateral-seccion--soltar'));
    this.zona = null;
  },

  iniciarEventos() {
    const lateral = document.querySelector('.barra-lateral__desplazable');
    let arrastrado = null;

    // Clic derecho en una página del árbol: el mismo menú que "…", donde está el puntero
    lateral.addEventListener('contextmenu', (ev) => {
      const nodoEl = ev.target.closest('.arbol__nodo');
      if (!nodoEl || ev.shiftKey || ev.target.closest('input')) return;
      ev.preventDefault();
      const r = nodoEl.querySelector(':scope > .arbol__fila').getBoundingClientRect();
      this.menuNodo(ev.clientX || ev.clientY ? anclaEnPunto(ev.clientX, ev.clientY) : anclaEnPunto(r.left + 20, r.bottom), nodoEl);
    });

    lateral.addEventListener('click', (ev) => {
      const nodoEl = ev.target.closest('.arbol__nodo');
      if (!nodoEl) return;
      if (ev.target.closest('[data-alternar]')) { ev.preventDefault(); this.alternar(nodoEl); return; }
      if (ev.target.closest('[data-nodo-menu]')) { ev.preventDefault(); this.menuNodo(ev.target.closest('[data-nodo-menu]'), nodoEl); return; }
      if (ev.target.closest('[data-nodo-nueva]')) {
        ev.preventDefault();
        const id = Number(nodoEl.dataset.id);
        this.abiertos.add(id);
        this.guardarAbiertos();
        menuNuevaPagina(ev.target.closest('[data-nodo-nueva]'), { padre_id: id });
      }
    });

    lateral.addEventListener('dragstart', (ev) => {
      const nodoEl = ev.target.closest('.arbol__nodo');
      if (!nodoEl) return;
      arrastrado = Number(nodoEl.dataset.id);
      ev.dataTransfer.effectAllowed = 'move';
      ev.dataTransfer.setData('text/plain', String(arrastrado));
      nodoEl.classList.add('arrastrando');
    });
    lateral.addEventListener('dragend', () => {
      document.querySelectorAll('.arbol__nodo.arrastrando').forEach((n) => n.classList.remove('arrastrando'));
      this.limpiarZona();
      arrastrado = null;
    });
    lateral.addEventListener('dragover', (ev) => {
      if (!arrastrado) return;
      const fila = ev.target.closest('.arbol__fila');
      const seccionEl = ev.target.closest('[data-seccion-arbol]');
      this.limpiarZona();
      if (fila) {
        const nodoEl = fila.closest('.arbol__nodo');
        const destino = Number(nodoEl.dataset.id);
        if (nodoEl.dataset.seccion === 'favoritos' || destino === arrastrado) return;
        // No se puede soltar dentro de sí misma ni de sus descendientes
        if (this.rutaHasta(destino)?.includes(arrastrado)) return;
        const r = fila.getBoundingClientRect();
        const y = (ev.clientY - r.top) / r.height;
        const puedeDentro = nodoEl.dataset.tipo !== 'base_datos' && this.buscar(destino)?.nodo.nivel !== 'ver';
        const tipo = y < 0.28 ? 'antes' : y > 0.72 ? 'despues' : (puedeDentro ? 'dentro' : (y < 0.5 ? 'antes' : 'despues'));
        ev.preventDefault();
        fila.classList.add(`arbol__fila--${tipo}`);
        this.zona = { tipo, destino, seccion: nodoEl.dataset.seccion };
      } else if (seccionEl && ['privado', 'compartido'].includes(seccionEl.dataset.seccionArbol)) {
        ev.preventDefault();
        seccionEl.classList.add('lateral-seccion--soltar');
        this.zona = { tipo: 'raiz', seccion: seccionEl.dataset.seccionArbol };
      }
    });
    lateral.addEventListener('dragleave', (ev) => { if (!lateral.contains(ev.relatedTarget)) this.limpiarZona(); });
    lateral.addEventListener('drop', async (ev) => {
      if (!arrastrado || !this.zona) return;
      ev.preventDefault();
      const { tipo, destino, seccion } = this.zona;
      const id = arrastrado;
      this.limpiarZona();
      const cuerpo = { accion: 'mover', id };
      if (tipo === 'raiz') {
        Object.assign(cuerpo, { padre_id: null, seccion });
      } else if (tipo === 'dentro') {
        Object.assign(cuerpo, { padre_id: destino });
        this.abiertos.add(destino);
        this.guardarAbiertos();
      } else {
        const info = this.buscar(destino);
        const siguiente = tipo === 'antes' ? destino : info.hermanos[info.indice + 1]?.id;
        Object.assign(cuerpo, { padre_id: info.padre ? info.padre.id : null, seccion: info.seccion, antes_de: siguiente === id ? info.hermanos[info.indice + 2]?.id : siguiente });
      }
      try {
        await api('paginas/paginas.php', cuerpo);
        await this.recargar();
        if (id === PAGINA_ID) actualizarPrivacidad();
      } catch (e) { mostrarNotificacion('error', e.message); }
    });
  },
};

/* ---------- Acciones comunes de página ---------- */
async function copiarEnlace(url = location.href) {
  try {
    await navigator.clipboard.writeText(url);
    mostrarNotificacion('exito', 'Enlace copiado. Solo lo abren las personas con acceso.');
  } catch (e) { mostrarNotificacion('error', 'No se pudo copiar el enlace.'); }
}

async function alternarFavorita(id, valor) {
  try {
    await api('paginas/paginas.php', { accion: 'favorita', id, valor });
    if (id === PAGINA_ID) {
      document.querySelectorAll('[data-favorita]').forEach((b) => {
        b.classList.toggle('activo', valor);
        b.setAttribute('aria-pressed', String(valor));
        b.title = valor ? 'Quitar de favoritos' : 'Agregar a favoritos';
      });
    }
    await Arbol.recargar();
  } catch (e) { mostrarNotificacion('error', e.message); }
}

async function moverAPapelera(id) {
  try {
    await api('paginas/paginas.php', { accion: 'papelera', id });
    if (id === PAGINA_ID) { location.reload(); return; }
    mostrarNotificacion('exito', 'La página se movió a la papelera. Puedes restaurarla desde «Papelera».');
    await Arbol.recargar();
  } catch (e) { mostrarNotificacion('error', e.message); }
}

/** Etiqueta "Privada / Compartida" de la barra de ruta según el árbol actual. */
function actualizarPrivacidad() {
  const texto = document.querySelector('[data-privacidad-texto]');
  if (!texto || !PAGINA_ID) return;
  const info = Arbol.buscar(PAGINA_ID);
  if (!info) return;
  const compartida = info.seccion === 'compartido';
  texto.textContent = compartida ? 'Compartida' : 'Privada';
  texto.previousElementSibling?.remove();
  texto.insertAdjacentHTML('beforebegin', ico(compartida ? 'usuarios' : 'candado'));
}

document.addEventListener('click', async (ev) => {
  if (ev.target.closest('[data-copiar-enlace]')) { copiarEnlace(); return; }
  if (ev.target.closest('[data-favorita]') && PAGINA_ID) {
    alternarFavorita(PAGINA_ID, !ev.target.closest('[data-favorita]').classList.contains('activo'));
    return;
  }
  if (ev.target.closest('[data-pagina-papelera]') && PAGINA_ID) { moverAPapelera(PAGINA_ID); return; }
  if (ev.target.closest('[data-pagina-restaurar]') && PAGINA_ID) {
    try { await api('paginas/paginas.php', { accion: 'restaurar', id: PAGINA_ID }); location.reload(); } catch (e) { mostrarNotificacion('error', e.message); }
    return;
  }
  const eliminar = ev.target.closest('[data-pagina-eliminar]');
  if (eliminar && PAGINA_ID) {
    const ok = await confirmar(eliminar, {
      titulo: '¿Eliminar esta página para siempre?',
      texto: 'Se borrará con todo lo que contiene. Esta acción no se puede deshacer.',
      boton: 'Eliminar', alinear: 'derecha',
    });
    if (!ok) return;
    try { await api('paginas/paginas.php', { accion: 'eliminar', id: PAGINA_ID }); location.href = `${BASE}/pacientes/`; } catch (e) { mostrarNotificacion('error', e.message); }
  }
});

/* ---------- Utilidades de los menús: copiar, descargar, pestañas y asistente ---------- */
async function copiarTexto(texto, mensaje = 'Copiado al portapapeles.') {
  try {
    await navigator.clipboard.writeText(texto);
    mostrarNotificacion('exito', mensaje);
  } catch (e) { mostrarNotificacion('error', 'No se pudo copiar.'); }
}

/** Descarga un archivo armado en el navegador (exportar). */
function descargarArchivo(nombre, contenido, tipo = 'text/plain') {
  const url = URL.createObjectURL(new Blob([contenido], { type: `${tipo};charset=utf-8` }));
  const a = document.createElement('a');
  a.href = url;
  a.download = nombre;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
}
const nombreDeArchivo = (titulo) => String(titulo || '').replace(/[\\/:*?"<>|]+/g, '').replace(/\s+/g, ' ').trim().slice(0, 80) || 'pagina';

/** Abre la URL en una pestaña nueva de la barra de pestañas de la app (app.js). */
function abrirEnPestana(url, titulo) {
  const destino = new URL(url, location.href);
  const ruta = destino.pathname + destino.search;
  if (typeof leerPestanas !== 'function' || typeof guardarPestanas !== 'function') { window.open(destino.href, '_blank', 'noopener'); return; }
  const estado = leerPestanas() || { pestanas: [], activa: null };
  const maximo = typeof MAX_PESTANAS === 'number' ? MAX_PESTANAS : 10;
  if (estado.pestanas.length >= maximo) { mostrarNotificacion('error', `Puedes tener hasta ${maximo} pestañas abiertas.`); return; }
  const pestana = { id: Math.random().toString(36).slice(2, 10), url: ruta, titulo };
  estado.pestanas.push(pestana);
  estado.activa = pestana.id;
  guardarPestanas(estado);
  location.href = ruta;
}

const asistenteDisponible = () => !!document.querySelector('[data-asistente-abrir]');
/** Abre el panel del asistente con el texto citado en la caja de pregunta. */
function preguntarIA(texto) {
  const boton = document.querySelector('[data-asistente-abrir]');
  if (!boton) return;
  boton.click();
  const area = document.querySelector('[data-asistente] [data-entrada-texto]');
  if (!area) return;
  const cita = texto.trim().split('\n').map((l) => `> ${l}`).join('\n');
  area.value = `Sobre este fragmento de «${document.body.dataset.titulo || 'la nota'}»:\n${cita}\n\n`;
  area.dispatchEvent(new Event('input', { bubbles: true }));
  requestAnimationFrame(() => {
    area.focus();
    area.setSelectionRange(area.value.length, area.value.length);
    area.scrollTop = area.scrollHeight;
  });
}

/* ---------- Exportar a Markdown, texto o CSV (se arma en el navegador) ---------- */
const escaparCeldaMd = (t) => String(t ?? '').replace(/\|/g, '\\|').replace(/\n/g, ' ');
const LISTAS_BLOQUE = ['lista', 'numerada', 'tarea'];

function graficaComoFilas(g) {
  const series = g?.series || [];
  return {
    encabezado: ['Categoría', ...series.map((s) => s.nombre)],
    filas: (g?.categorias || []).map((c, i) => [c, ...series.map((s) => (s.valores[i] ?? ''))]),
  };
}

function bloquesAMarkdown(bloques) {
  const salida = [];
  let n = 0;
  let previo = null;
  (bloques || []).forEach((b) => {
    n = b.t === 'numerada' ? n + 1 : 0;
    const x = b.x || '';
    let linea;
    if (b.t === 'h1') linea = `# ${x}`;
    else if (b.t === 'h2') linea = `## ${x}`;
    else if (b.t === 'h3') linea = `### ${x}`;
    else if (b.t === 'lista') linea = `- ${x}`;
    else if (b.t === 'numerada') linea = `${n}. ${x}`;
    else if (b.t === 'tarea') linea = `- [${b.c ? 'x' : ' '}] ${x}`;
    else if (b.t === 'cita') linea = x.split('\n').map((l) => `> ${l}`).join('\n');
    else if (b.t === 'separador') linea = '---';
    else if (b.t === 'imagen') linea = `![${x}](${location.origin}${urlImagenNota(b.src)})`;
    else if (b.t === 'grafica') {
      const { encabezado, filas } = graficaComoFilas(b.g);
      linea = `${b.g?.titulo ? `**${b.g.titulo}**\n\n` : ''}| ${encabezado.map(escaparCeldaMd).join(' | ')} |\n|${encabezado.map((_, i) => (i ? ' ---: ' : ' --- ')).join('|')}|\n`
        + filas.map((f) => `| ${f.map(escaparCeldaMd).join(' | ')} |`).join('\n') + (x ? `\n\n${x}` : '');
    } else {
      if (!x.trim()) { previo = null; return; }
      linea = x.replace(/\n/g, '  \n');
    }
    const juntos = previo && LISTAS_BLOQUE.includes(b.t) && LISTAS_BLOQUE.includes(previo);
    salida.push((salida.length && !juntos ? '\n' : '') + linea);
    previo = b.t;
  });
  return `${salida.join('\n')}\n`;
}

function bloquesATexto(bloques) {
  const salida = [];
  let n = 0;
  let previo = null;
  (bloques || []).forEach((b) => {
    n = b.t === 'numerada' ? n + 1 : 0;
    const x = b.x || '';
    let linea;
    if (b.t === 'lista') linea = `• ${x}`;
    else if (b.t === 'numerada') linea = `${n}. ${x}`;
    else if (b.t === 'tarea') linea = `[${b.c ? 'x' : ' '}] ${x}`;
    else if (b.t === 'cita') linea = x.split('\n').map((l) => `  ${l}`).join('\n');
    else if (b.t === 'separador') linea = '----------';
    else if (b.t === 'imagen') linea = `[Imagen${x ? `: ${x}` : ''}]`;
    else if (b.t === 'grafica') {
      const { encabezado, filas } = graficaComoFilas(b.g);
      linea = `${b.g?.titulo ? `${b.g.titulo}\n` : ''}${[encabezado, ...filas].map((f) => f.join('\t')).join('\n')}${x ? `\n${x}` : ''}`;
    } else {
      if (!x.trim()) { previo = null; return; }
      linea = x;
    }
    const juntos = previo && LISTAS_BLOQUE.includes(b.t) && LISTAS_BLOQUE.includes(previo);
    salida.push((salida.length && !juntos ? '\n' : '') + linea);
    previo = b.t;
  });
  return `${salida.join('\n')}\n`;
}

/** Valor de una celda como texto: formateado (Markdown, texto) o crudo (CSV). */
function valorExportado(col, v, crudo = false) {
  if (v === null || v === undefined || v === '') return '';
  if (col.tipo === 'casilla') return v ? 'Sí' : 'No';
  if (col.tipo === 'fecha' && !crudo) { const [a, m, d] = String(v).split('-'); return `${d}/${m}/${a}`; }
  if (col.tipo === 'numero' && !crudo) return Number(v).toLocaleString('es-MX');
  return String(v);
}
function tablaAFilas(d, crudo = false) {
  return [d.columnas.map((c) => c.nombre), ...d.filas.map((f) => d.columnas.map((c) => valorExportado(c, f.valores?.[c.id], crudo)))];
}
function tablaAMarkdown(d) {
  const [encabezado, ...filas] = tablaAFilas(d);
  return `| ${encabezado.map(escaparCeldaMd).join(' | ')} |\n|${d.columnas.map((c) => (c.tipo === 'numero' ? ' ---: ' : ' --- ')).join('|')}|\n${filas.map((f) => `| ${f.map(escaparCeldaMd).join(' | ')} |`).join('\n')}\n`;
}
function tablaACsv(d) {
  const celda = (t) => (/[",;\n\r]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t);
  return `﻿${tablaAFilas(d, true).map((f) => f.map(celda).join(',')).join('\r\n')}\r\n`;
}
function tablaATexto(d) {
  return `${tablaAFilas(d).map((f) => f.join('\t')).join('\n')}\n`;
}

async function exportarPagina(id, formato) {
  let d;
  try {
    if (id === PAGINA_ID && Editor.raiz) {
      d = { tipo: 'nota', titulo: Hoja.titulo?.textContent.trim() || '', bloques: Editor.serializar() };
    } else if (id === PAGINA_ID && Bdp.raiz) {
      d = { tipo: 'base_datos', titulo: Hoja.titulo?.textContent.trim() || '', columnas: Bdp.datos.columnas, filas: Bdp.filasVisibles?.() || Bdp.datos.filas };
    } else {
      d = await api(`paginas/paginas.php?contenido=${encodeURIComponent(id)}`);
    }
  } catch (e) { mostrarNotificacion('error', e.message); return; }
  const titulo = d.titulo || TIPOS[d.tipo].nueva;
  const md = formato === 'markdown';
  let cuerpo;
  if (d.tipo === 'nota') cuerpo = md ? bloquesAMarkdown(d.bloques) : bloquesATexto(d.bloques);
  else if (d.tipo === 'base_datos') cuerpo = formato === 'csv' ? tablaACsv(d) : md ? tablaAMarkdown(d) : tablaATexto(d);
  else cuerpo = (d.hijos || []).map((h) => (md ? `- [${tituloDe(h)}](${location.origin}${BASE}/paginas/ver.php?id=${h.id})` : `• ${tituloDe(h)}`)).join('\n') || (md ? '_Sin páginas dentro._' : 'Sin páginas dentro.');
  if (formato === 'csv') { descargarArchivo(`${nombreDeArchivo(titulo)}.csv`, cuerpo, 'text/csv'); return; }
  const contenido = md ? `# ${titulo}\n\n${cuerpo}` : `${titulo}\n${'='.repeat(Math.min(titulo.length, 60))}\n\n${cuerpo}`;
  descargarArchivo(`${nombreDeArchivo(titulo)}.${md ? 'md' : 'txt'}`, contenido, md ? 'text/markdown' : 'text/plain');
}

/* ---------- Menú de una página (barra lateral, encabezado y lista de páginas dentro) ---------- */
const ICONO_LINEA = { carpeta: 'carpeta', nota: 'documento', base_datos: 'tabla' };

function nodoDePagina(id) {
  const info = Arbol.datos ? Arbol.buscar(id) : null;
  if (info) return { ...info.nodo, seccion: info.seccion, padreId: info.padre?.id ?? null };
  const favorita = Arbol.datos?.favoritos.find((f) => f.id === id);
  if (favorita) return { ...favorita };
  if (id === PAGINA_ID) return { id, tipo: document.body.dataset.paginaTipo || 'nota', titulo: Hoja.titulo?.textContent.trim() || '', nivel: Hoja.editable ? 'editar' : 'ver' };
  return null;
}

async function renombrarPagina(id, titulo) {
  try {
    const r = await api('paginas/paginas.php', { accion: 'renombrar', id, titulo });
    if (id === PAGINA_ID) Hoja.tituloCambiado(r.titulo, true);
    document.querySelectorAll(`[data-hoja-hijos] a[href$="id=${id}"] .hoja__hijo-titulo`).forEach((t) => { t.textContent = r.titulo || TIPOS[nodoDePagina(id)?.tipo || 'nota'].nueva; });
    await Arbol.recargar();
  } catch (e) { mostrarNotificacion('error', e.message); }
}

async function duplicarPagina(id) {
  try {
    const r = await api('paginas/paginas.php', { accion: 'duplicar', id });
    if (id === PAGINA_ID) { location.href = r.url; return; }
    await Arbol.recargar();
    mostrarNotificacion('exito', r.raiz ? 'Se creó una copia en Privado (no puedes agregar páginas en la carpeta original).' : 'Se creó una copia debajo de la original.');
  } catch (e) { mostrarNotificacion('error', e.message); }
}

/** "Mover a…": raíz de Privado o Compartido, o dentro de otra página (con búsqueda). */
function menuMoverA(ancla, id) {
  if (!Arbol.datos) return;
  const n = nodoDePagina(id);
  const destinos = [];
  const vistos = new Set();
  const recorrer = (nodos, ruta) => nodos.forEach((x) => {
    if (x.id === id || vistos.has(x.id)) return;   // ni ella misma ni lo que contiene
    vistos.add(x.id);
    if (x.tipo !== 'base_datos' && x.nivel !== 'ver') destinos.push({ ...x, ruta });
    recorrer(x.hijos, [...ruta, tituloDe(x)]);
  });
  recorrer(Arbol.datos.privado, []);
  recorrer(Arbol.datos.compartido, []);
  const padre = n?.padreId ?? null;
  const mover = async (padreId, seccion, nombre) => {
    try {
      await api('paginas/paginas.php', { accion: 'mover', id, padre_id: padreId, seccion });
      if (padreId) { Arbol.abiertos.add(padreId); Arbol.guardarAbiertos(); }
      if (id === PAGINA_ID) { location.reload(); return; }
      await Arbol.recargar();
      mostrarNotificacion('exito', `Se movió a «${nombre}».`);
    } catch (e) { mostrarNotificacion('error', e.message); }
  };
  abrirMenu(ancla, [
    { titulo: 'Al inicio de una sección' },
    { icono: 'candado', texto: 'Privado', marcado: padre === null && n?.seccion === 'privado' ? true : undefined, accion: () => mover(null, 'privado', 'Privado') },
    { icono: 'usuarios', texto: 'Compartido', marcado: padre === null && n?.seccion === 'compartido' ? true : undefined, accion: () => mover(null, 'compartido', 'Compartido') },
    destinos.length ? { titulo: 'Dentro de una página' } : null,
    ...destinos.map((d) => ({
      icono: ICONO_LINEA[d.tipo], texto: tituloDe(d), detalle: d.ruta[d.ruta.length - 1] || '',
      marcado: d.id === padre ? true : undefined, accion: () => mover(d.id, undefined, tituloDe(d)),
    })),
  ], { buscar: 'Mover la página a…', clase: 'menu-mover' });
}

function menuPagina(ancla, id, contexto = {}) {
  const n = nodoDePagina(id);
  if (!n) return;
  const url = `${BASE}/paginas/ver.php?id=${id}`;
  const titulo = tituloDe(n);
  const actual = id === PAGINA_ID;
  const editable = n.nivel === 'editar' || n.nivel === 'completo';
  const completo = n.nivel === 'completo';
  const favorita = Arbol.datos ? Arbol.esFavorita(id) : !!document.querySelector('[data-favorita].activo');
  const formatos = n.tipo === 'base_datos'
    ? [['markdown', 'Markdown', '.md', 'markdown'], ['csv', 'CSV', '.csv', 'tabla'], ['texto', 'Texto', '.txt', 'texto']]
    : [['markdown', 'Markdown', '.md', 'markdown'], ['texto', 'Texto', '.txt', 'texto']];
  const renombrar = () => {
    if (contexto.nodoEl?.isConnected) { Arbol.renombrarEnLinea(contexto.nodoEl); return; }
    if (actual && Hoja.editable && Hoja.titulo) {
      Hoja.titulo.focus();
      const rango = document.createRange();
      rango.selectNodeContents(Hoja.titulo);
      getSelection().removeAllRanges();
      getSelection().addRange(rango);
      return;
    }
    abrirMenu(ancla, [{ campo: true, valor: n.titulo || '', placeholder: TIPOS[n.tipo].nueva, etiqueta: 'Nuevo nombre', maximo: 200, alGuardar: (v) => renombrarPagina(id, v) }], { seleccionar: true, clase: 'menu-renombrar' });
  };
  abrirMenu(ancla, [
    { titulo },
    !actual && { icono: 'abrir', texto: 'Abrir', accion: () => { location.href = url; } },
    { icono: 'pestana', texto: 'Abrir en pestaña nueva', accion: () => abrirEnPestana(url, titulo) },
    { icono: 'externo', texto: 'Abrir en otra ventana', accion: () => window.open(url, '_blank', 'noopener') },
    'separador',
    { icono: 'estrella', texto: favorita ? 'Quitar de favoritos' : 'Agregar a favoritos', accion: () => alternarFavorita(id, !favorita) },
    { icono: 'enlace', texto: 'Copiar enlace', accion: () => copiarEnlace(`${location.origin}${url}`) },
    n.nivel && { icono: 'duplicar', texto: 'Duplicar', accion: () => duplicarPagina(id) },
    editable && { icono: 'editar', texto: 'Renombrar', accion: renombrar },
    completo && Arbol.datos && { icono: 'mover', texto: 'Mover a…', accion: () => menuMoverA(ancla, id) },
    { icono: 'exportar', texto: 'Exportar', submenu: formatos.map(([f, nombre, ext, icono]) => ({ icono, texto: nombre, detalle: ext, accion: () => exportarPagina(id, f) })) },
    n.tipo !== 'base_datos' && editable && { icono: 'agregar', texto: 'Agregar dentro', submenu: Object.entries(TIPOS).map(([tipo, t]) => ({
      icono: ICONO_LINEA[tipo], texto: t.texto, accion: () => { Arbol.abiertos.add(id); Arbol.guardarAbiertos(); crearPagina({ tipo, padre_id: id }); },
    })) },
    completo && 'separador',
    completo && { icono: 'papelera', texto: 'Mover a la papelera', peligro: true, accion: () => moverAPapelera(id) },
  ], { clase: 'menu-pagina', alCerrar: contexto.alCerrar });
}

// Clic derecho en el encabezado de la página y en la lista de páginas que contiene
document.addEventListener('contextmenu', (ev) => {
  if (ev.shiftKey || !PAGINA_ID) return;
  if (ev.target.closest('input, textarea, [contenteditable="true"], [contenteditable="plaintext-only"], .popover')) return;
  const hijo = ev.target.closest('[data-hoja-hijos] a');
  if (hijo) {
    const id = Number(new URL(hijo.href).searchParams.get('id'));
    if (!id) return;
    ev.preventDefault();
    hijo.classList.add('con-menu');
    menuPagina(anclaEnPunto(ev.clientX, ev.clientY), id, { alCerrar: () => hijo.classList.remove('con-menu') });
    return;
  }
  if (ev.target.closest('.barra-superior')) {
    ev.preventDefault();
    menuPagina(anclaEnPunto(ev.clientX, ev.clientY), PAGINA_ID);
  }
});

/* ---------- Papelera (barra lateral) ---------- */
window.pintarPapelera = async (popover) => {
  popover.innerHTML = '<div class="lateral-popover__titulo">Papelera</div><div class="lateral-popover__vacio">Cargando…</div>';
  try {
    const r = await api('paginas/paginas.php');
    popover.innerHTML = '<div class="lateral-popover__titulo">Papelera</div>' + (r.papelera.length
      ? r.papelera.map((p) => `
        <div class="papelera__fila">
          <a role="menuitem" href="${BASE}/paginas/ver.php?id=${p.id}">${ico(TIPOS[p.tipo].icono)}<span>${escaparHtml(tituloDe(p))}</span></a>
          <button type="button" class="boton-icono" data-restaurar="${p.id}" title="Restaurar">${ico('reabrir')}</button>
        </div>`).join('')
      : '<div class="lateral-popover__vacio">La papelera está vacía.</div>');
  } catch (e) {
    popover.innerHTML = `<div class="lateral-popover__vacio">${escaparHtml(e.message)}</div>`;
  }
};
document.addEventListener('click', async (ev) => {
  const b = ev.target.closest('[data-restaurar]');
  if (!b) return;
  ev.stopPropagation();
  try {
    const id = Number(b.dataset.restaurar);
    await api('paginas/paginas.php', { accion: 'restaurar', id });
    if (id === PAGINA_ID) { location.reload(); return; }
    b.closest('.papelera__fila').remove();
    mostrarNotificacion('exito', 'Página restaurada.');
    await Arbol.recargar();
  } catch (e) { mostrarNotificacion('error', e.message); }
});

/* ---------- Título de la página ---------- */
const Hoja = {
  el: null,
  titulo: null,
  editable: false,

  iniciar() {
    this.el = document.querySelector('[data-hoja]');
    if (!this.el) return;
    this.titulo = this.el.querySelector('[data-hoja-titulo]');
    this.editable = this.el.dataset.editable === '1';
    if (!this.editable) return;
    let temporizador = null;
    const guardar = async () => {
      const valor = this.titulo.textContent.replace(/\s+/g, ' ').trim();
      try {
        const r = await api('paginas/paginas.php', { accion: 'renombrar', id: PAGINA_ID, titulo: valor });
        this.tituloCambiado(r.titulo, false);
        Arbol.recargar();
      } catch (e) { mostrarNotificacion('error', e.message); }
    };
    this.titulo.addEventListener('input', () => {
      if (!this.titulo.textContent.trim()) this.titulo.textContent = '';
      marcarEditando();
      clearTimeout(temporizador);
      temporizador = setTimeout(guardar, 500);
    });
    this.titulo.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        Editor.enfocarInicio?.();
      }
    });
    this.titulo.addEventListener('paste', (ev) => {
      ev.preventDefault();
      document.execCommand('insertText', false, (ev.clipboardData.getData('text/plain') || '').replace(/\s+/g, ' '));
    });
  },

  /** Refleja un título nuevo en la pestaña, las migas y el título de la ventana. */
  tituloCambiado(titulo, actualizarEncabezado) {
    const texto = titulo || TIPOS[document.body.dataset.paginaTipo]?.nueva || 'Nueva página';
    if (actualizarEncabezado && this.titulo) this.titulo.textContent = titulo;
    document.querySelectorAll('[data-miga-titulo]').forEach((m) => { m.textContent = texto; });
    document.title = `${texto} · Expedientes Clínicos`;
    document.body.dataset.titulo = texto;
    window.renombrarPestanaActual?.(texto);
    marcarEdicionAhora();
  },
};

function marcarEdicionAhora() {
  const el = document.querySelector('[data-ultima-edicion]');
  if (el) { el.textContent = 'ahora mismo'; el.dataset.fecha = new Date().toISOString(); }
}

/* ---------- Editor por bloques (notas) ---------- */
const BLOQUES = {
  p: { texto: 'Texto', icono: 'texto', placeholder: 'Escribe «/» para ver los comandos, o Espacio para preguntar a la IA…', clave: 'texto parrafo' },
  h1: { texto: 'Encabezado 1', icono: 'titulo', placeholder: 'Encabezado 1', clave: 'h1 titulo encabezado' },
  h2: { texto: 'Encabezado 2', icono: 'titulo', placeholder: 'Encabezado 2', clave: 'h2 subtitulo encabezado' },
  h3: { texto: 'Encabezado 3', icono: 'titulo', placeholder: 'Encabezado 3', clave: 'h3 encabezado' },
  lista: { texto: 'Lista con viñetas', icono: 'vinetas', placeholder: 'Lista', clave: 'lista viñetas puntos' },
  numerada: { texto: 'Lista numerada', icono: 'lista_numerada', placeholder: 'Lista', clave: 'lista numerada numeros' },
  tarea: { texto: 'Lista de tareas', icono: 'casilla', placeholder: 'Tarea', clave: 'tarea pendiente casilla checklist' },
  cita: { texto: 'Cita', icono: 'comillas', placeholder: 'Cita', clave: 'cita quote' },
  separador: { texto: 'Separador', icono: 'separador', placeholder: '', clave: 'separador linea divisor' },
  imagen: { texto: 'Imagen', icono: 'imagen', placeholder: 'Agrega un pie de imagen', clave: 'imagen foto figura subir' },
  grafica: { texto: 'Gráfica', icono: 'grafica', placeholder: 'Agrega una nota a la gráfica', clave: 'grafica chart barras lineas dona datos' },
};
const BLOQUES_FIJOS = ['separador', 'imagen', 'grafica'];   // no se convierten en otro tipo
const NOMBRES_GRAFICA = { barras: 'Barras', barras_apiladas: 'Barras apiladas', barras_horizontales: 'Barras horizontales', lineas: 'Líneas', area: 'Área', dona: 'Dona' };
const ATAJOS = [
  [/^#\s/, 'h1'], [/^##\s/, 'h2'], [/^###\s/, 'h3'], [/^[-*]\s/, 'lista'], [/^1[.)]\s/, 'numerada'],
  [/^\[\s?\]\s/, 'tarea'], [/^[>"]\s/, 'cita'],
];
const normalizarTexto = (t) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
/** Colores de bloque (paleta apagada de Notion); el fondo se guarda como "fondo_<color>". */
const COLORES_BLOQUE = { gris: 'Gris', cafe: 'Café', naranja: 'Naranja', amarillo: 'Amarillo', verde: 'Verde', azul: 'Azul', morado: 'Morado', rosa: 'Rosa', rojo: 'Rojo' };
const esColorBloque = (c) => typeof c === 'string' && !!COLORES_BLOQUE[c.replace(/^fondo_/, '')];
/** Identificador corto de un bloque para los enlaces directos (#b-xxxxxxxx). */
/** URL para mostrar una imagen de nota: pasa por el endpoint autenticado (data-src guarda la ruta assets/subidas/…). */
const urlImagenNota = (src) => `${BASE}/api/paginas/imagen.php?f=${encodeURIComponent(String(src || '').replace(/^assets\/subidas\//, ''))}`;
const idBloque = () => [...crypto.getRandomValues(new Uint8Array(8))].map((n) => 'abcdefghijklmnopqrstuvwxyz0123456789'[n % 36]).join('');

const Editor = {
  raiz: null,
  editable: false,
  version: 0,
  temporizador: null,
  guardando: false,
  pendiente: false,
  bloqueado: false,
  menu: null,

  iniciar() {
    this.raiz = document.querySelector('[data-editor-bloques]');
    if (!this.raiz) return;
    // En una nota, la hoja es la página; debajo de una sección (Pacientes, Agenda, expediente) es su propia caja
    const hoja = this.raiz.closest('[data-hoja], [data-hoja-seccion]') || document.querySelector('[data-hoja]');
    this.paginaId = Number(this.raiz.dataset.pagina || 0) || PAGINA_ID;
    this.seccion = this.raiz.dataset.seccion || null;
    this.editable = hoja.dataset.editable === '1';
    this.version = Number(hoja.dataset.version);
    let bloques = [];
    try { bloques = JSON.parse(document.getElementById('editor-datos').textContent); } catch (e) { /* vacío */ }
    if (!bloques.length) bloques = [{ t: 'p', x: '' }];
    bloques.forEach((b) => this.raiz.appendChild(this.crearBloque(b)));
    this.numerar();
    this.actualizarVacio();
    this.raiz.addEventListener('contextmenu', (ev) => this.alMenuContextual(ev));
    this.irAlBloqueDelEnlace();
    window.addEventListener('hashchange', () => this.irAlBloqueDelEnlace());
    if (!this.editable) return;
    // Sin conexión: la nota se guarda en este equipo y se envía al volver (assets/js/modulos/sin_conexion.js)
    this.recuperarCopiaLocal();
    const reintentar = () => { if (this.sinConexion && !this.guardando) this.guardar(); };
    window.addEventListener('online', reintentar);
    document.addEventListener('femeci:conexion', reintentar);

    this.raiz.addEventListener('keydown', (ev) => this.teclas(ev));
    this.raiz.addEventListener('input', (ev) => this.alEscribir(ev));
    this.raiz.addEventListener('paste', (ev) => this.alPegar(ev));
    this.raiz.addEventListener('click', (ev) => {
      const casilla = ev.target.closest('.bloque__casilla');
      if (casilla) {
        const bloque = casilla.closest('.bloque');
        bloque.dataset.c = bloque.dataset.c === '1' ? '0' : '1';
        casilla.setAttribute('aria-checked', String(bloque.dataset.c === '1'));
        this.programarGuardado();
      }
      const mas = ev.target.closest('[data-bloque-mas]');
      if (mas) this.agregarDebajo(mas.closest('.bloque'));
      const agarre = ev.target.closest('[data-bloque-agarre]');
      if (agarre && !this.arrastrando) this.menuBloque(agarre, agarre.closest('.bloque'));
    });
    this.raiz.addEventListener('pointerdown', (ev) => this.iniciarArrastre(ev));
    // Clic en el espacio bajo el último bloque: enfoca el último o crea uno nuevo
    (this.seccion ? this.raiz.closest('.notas-seccion') : document.querySelector('.contenido__pagina')).addEventListener('click', (ev) => {
      if (!this.seccion && ev.target.closest('.hoja, .opciones-vacia, .barra-superior, .popover, .aviso-papelera')) return;
      const ultimo = this.raiz.lastElementChild;
      if (ev.clientY < ultimo.getBoundingClientRect().bottom) return;
      if (ultimo.dataset.t !== 'p' || this.texto(ultimo)) this.insertarDespues(ultimo, { t: 'p', x: '' });
      this.enfocar(this.raiz.lastElementChild, 'fin');
    });
    window.addEventListener('beforeunload', (ev) => {
      if (this.temporizador || this.guardando) { this.guardar(true); ev.preventDefault(); }
    });
  },

  crearBloque(b) {
    // Un tipo desconocido (versión más nueva, importación) se muestra como párrafo en lugar de romper la nota
    if (!b || !BLOQUES[b.t]) b = { ...(b || {}), t: 'p', x: String(b?.x ?? '') };
    const el = document.createElement('div');
    el.className = 'bloque';
    el.dataset.t = b.t;
    el.dataset.i = b.i || idBloque();
    if (b.i) el.dataset.iFijo = '1';   // el enlace directo ya está guardado
    if (esColorBloque(b.color) && !BLOQUES_FIJOS.includes(b.t)) el.dataset.color = b.color;
    if (b.t === 'tarea') el.dataset.c = b.c ? '1' : '0';
    const controles = this.editable
      ? `<span class="bloque__controles" contenteditable="false"><button type="button" tabindex="-1" data-bloque-mas title="Clic para agregar un bloque debajo">${ico('agregar')}</button><button type="button" tabindex="-1" data-bloque-agarre title="Arrastra para mover · clic para abrir el menú">${ico('agarre')}</button></span>`
      : '';
    if (b.t === 'separador') {
      el.innerHTML = `${controles}<hr class="bloque__separador" tabindex="${this.editable ? 0 : -1}">`;
      return el;
    }
    if (b.t === 'imagen') {
      el.dataset.src = b.src || '';
      if (b.tam === 's' || b.tam === 'm') el.dataset.tam = b.tam;
      el.innerHTML = `${controles}<figure class="bloque__imagen"><img src="${escaparHtml(urlImagenNota(b.src))}" alt="" loading="lazy" draggable="false">`
        + `<figcaption class="bloque__texto" data-placeholder="${escaparHtml(BLOQUES.imagen.placeholder)}"${this.editable ? ' contenteditable="plaintext-only"' : ''} spellcheck="true"></figcaption></figure>`;
      el.querySelector('.bloque__texto').textContent = b.x || '';
      el.querySelector('img').alt = b.x || '';
      return el;
    }
    if (b.t === 'grafica') {
      el.dataset.g = JSON.stringify(b.g || {});
      el.innerHTML = `${controles}<figure class="bloque__grafica"><div class="bloque__grafica-lienzo" contenteditable="false"></div>`
        + `<figcaption class="bloque__texto" data-placeholder="${escaparHtml(BLOQUES.grafica.placeholder)}"${this.editable ? ' contenteditable="plaintext-only"' : ''} spellcheck="true"></figcaption></figure>`;
      el.querySelector('.bloque__texto').textContent = b.x || '';
      const lienzo = el.querySelector('.bloque__grafica-lienzo');
      const pintar = () => {
        if (typeof Graficas === 'undefined') { setTimeout(pintar, 30); return; }
        if (!Graficas.montar(lienzo, b.g)) lienzo.textContent = 'La gráfica no tiene datos válidos.';
      };
      pintar();
      return el;
    }
    el.innerHTML = `${controles}${b.t === 'tarea' ? `<span class="bloque__casilla" role="checkbox" aria-checked="${b.c ? 'true' : 'false'}" contenteditable="false"></span>` : ''}<div class="bloque__texto" data-placeholder="${escaparHtml(BLOQUES[b.t].placeholder)}"${this.editable ? ' contenteditable="plaintext-only"' : ''} spellcheck="true"></div>`;
    el.querySelector('.bloque__texto').textContent = b.x || '';
    return el;
  },

  texto(bloque) { return bloque.querySelector('.bloque__texto')?.textContent ?? ''; },
  editableDe(bloque) { return bloque.querySelector('.bloque__texto') || bloque.querySelector('.bloque__separador'); },

  /** Datos de un bloque tal como se guardan. */
  datosDe(b) {
    const d = { t: b.dataset.t, x: b.dataset.t === 'separador' ? '' : this.texto(b) };
    if (b.dataset.i) d.i = b.dataset.i;
    if (b.dataset.color) d.color = b.dataset.color;
    if (b.dataset.t === 'tarea') d.c = b.dataset.c === '1';
    if (b.dataset.t === 'imagen') { d.src = b.dataset.src; if (b.dataset.tam) d.tam = b.dataset.tam; }
    if (b.dataset.t === 'grafica') { try { d.g = JSON.parse(b.dataset.g); } catch (e) { d.g = {}; } }
    return d;
  },

  serializar() {
    return [...this.raiz.querySelectorAll(':scope > .bloque')].map((b) => this.datosDe(b));
  },

  numerar() {
    let n = 0;
    [...this.raiz.children].forEach((b) => {
      n = b.dataset.t === 'numerada' ? n + 1 : 0;
      if (n) b.dataset.n = n; else delete b.dataset.n;
    });
  },

  actualizarVacio() {
    const vacia = [...this.raiz.children].every((b) => b.dataset.t === 'p' && !this.texto(b).trim());
    const opciones = document.querySelector('[data-opciones-vacia]');
    if (opciones) opciones.hidden = !vacia;
  },

  /* Copia local de la nota (solo mientras no se pudo guardar en el servidor) */
  claveCopia() { return `femeci-nota-${USUARIO_ID}-${this.paginaId || this.seccion}`; },

  /** Debajo de una sección la nota se crea al escribir por primera vez. */
  async asegurarPagina() {
    if (this.paginaId || !this.seccion) return this.paginaId;
    const r = await api('paginas/paginas.php', { accion: 'seccion', seccion: this.seccion });
    this.paginaId = r.id;
    this.version = r.version;
    this.raiz.dataset.pagina = r.id;
    return r.id;
  },
  guardarCopiaLocal() {
    try { localStorage.setItem(this.claveCopia(), JSON.stringify({ version: this.version, fecha: Date.now(), bloques: this.serializar() })); } catch (e) { /* sin espacio */ }
  },
  borrarCopiaLocal() { try { localStorage.removeItem(this.claveCopia()); } catch (e) { /* opcional */ } },
  recuperarCopiaLocal() {
    let copia = null;
    try { copia = JSON.parse(localStorage.getItem(this.claveCopia()) || 'null'); } catch (e) { /* sin almacenamiento */ }
    if (!Array.isArray(copia?.bloques) || !copia.bloques.length) return;
    if (JSON.stringify(copia.bloques) === JSON.stringify(this.serializar())) { this.borrarCopiaLocal(); return; }
    const usarCopia = () => {
      this.raiz.replaceChildren(...copia.bloques.map((b) => this.crearBloque(b)));
      this.programarGuardado();
    };
    // Nadie la cambió desde entonces: se recupera sola
    if (copia.version === this.version) {
      usarCopia();
      mostrarNotificacion('exito', 'Se recuperaron cambios de esta página que no alcanzaron a guardarse.');
      return;
    }
    // La página cambió después en otra sesión: se ofrece la copia sin pisar nada por sí sola
    const aviso = document.createElement('div');
    aviso.className = 'aviso-borrador';
    aviso.setAttribute('role', 'status');
    aviso.innerHTML = '<span>Hay una copia de esta página que no se guardó en este equipo, pero la página cambió después en otra sesión.</span>'
      + '<button type="button" class="boton boton--sutil" data-copia-usar>Usar mi copia</button><button type="button" class="boton boton--sutil" data-copia-descartar>Descartar copia</button>';
    aviso.querySelector('[data-copia-usar]').addEventListener('click', () => { usarCopia(); aviso.remove(); });
    aviso.querySelector('[data-copia-descartar]').addEventListener('click', () => { this.borrarCopiaLocal(); aviso.remove(); });
    this.raiz.before(aviso);
  },

  /* Guardado automático con control de versión */
  programarGuardado() {
    this.numerar();
    this.actualizarVacio();
    marcarEditando();
    if (this.bloqueado) return;
    clearTimeout(this.temporizador);
    this.temporizador = setTimeout(() => this.guardar(), 700);
  },

  async guardar(alSalir = false) {
    clearTimeout(this.temporizador);
    this.temporizador = null;
    if (this.guardando && !alSalir) { this.pendiente = true; return; }
    this.guardando = true;
    if (alSalir || this.sinConexion) this.guardarCopiaLocal();
    try {
      await this.asegurarPagina();
      const cuerpo = JSON.stringify({ accion: 'guardar', id: this.paginaId, version: this.version, bloques: this.serializar() });
      const r = await fetch(`${BASE}/api/paginas/paginas.php`, {
        method: 'POST', keepalive: alSalir && cuerpo.length < 60000,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF }, body: cuerpo,
      });
      const datos = await r.json();
      if (!r.ok) {
        if (r.status === 409) this.bloqueado = true;
        throw new Error(datos.error);
      }
      this.version = datos.version;
      this.raiz.querySelectorAll(':scope > .bloque').forEach((b) => { b.dataset.iFijo = '1'; });
      marcarEdicionAhora();
      if (this.sinConexion) mostrarNotificacion('exito', 'Se guardaron los cambios que estaban en este equipo.');
      this.sinConexion = false;
      this.borrarCopiaLocal();
    } catch (e) {
      if (e.sinConexion) {
        // Sin servidor: lo escrito queda en este equipo y se reintenta al volver la conexión (un solo aviso)
        this.guardarCopiaLocal();
        if (!this.sinConexion && !alSalir) mostrarNotificacion('error', 'Sin conexión: los cambios de esta página se guardan en este equipo y se enviarán al volver la conexión.');
        this.sinConexion = true;
        return;
      }
      if (!alSalir) mostrarNotificacion('error', e.message || 'No se pudieron guardar los cambios.');
    } finally {
      this.guardando = false;
      if (this.pendiente) { this.pendiente = false; this.guardar(); }
    }
  },

  /* Cursor */
  enfocar(bloque, donde = 'fin') {
    const el = this.editableDe(bloque);
    if (!el) return;
    el.focus({ preventScroll: false });
    if (!el.isContentEditable) return;
    const rango = document.createRange();
    const sel = getSelection();
    if (typeof donde === 'number') {
      const nodo = el.firstChild;
      if (nodo) rango.setStart(nodo, Math.min(donde, nodo.length)); else rango.setStart(el, 0);
      rango.collapse(true);
    } else {
      rango.selectNodeContents(el);
      rango.collapse(donde === 'inicio');
    }
    sel.removeAllRanges();
    sel.addRange(rango);
  },
  enfocarInicio() { if (Editor.raiz?.firstElementChild) Editor.enfocar(Editor.raiz.firstElementChild, 'inicio'); },

  /** Posición del cursor dentro del texto del bloque. */
  posicion(el) {
    const sel = getSelection();
    if (!sel.rangeCount || !el.contains(sel.anchorNode)) return null;
    const rango = sel.getRangeAt(0);
    const previo = rango.cloneRange();
    previo.selectNodeContents(el);
    previo.setEnd(rango.startContainer, rango.startOffset);
    return { inicio: previo.toString().length, colapsado: rango.collapsed, fin: previo.toString().length + rango.toString().length };
  },

  /** ¿El cursor está en la primera (o última) línea visual del bloque? */
  enLinea(el, primera) {
    const sel = getSelection();
    if (!sel.rangeCount) return true;
    const r = sel.getRangeAt(0).getClientRects()[0];
    if (!r) return true;
    const caja = el.getBoundingClientRect();
    const alto = parseFloat(getComputedStyle(el).lineHeight) || 24;
    return primera ? r.top - caja.top < alto * 0.8 : caja.bottom - r.bottom < alto * 0.8;
  },

  insertarDespues(bloque, datos) {
    const nuevo = this.crearBloque(datos);
    bloque.after(nuevo);
    return nuevo;
  },

  convertir(bloque, tipo) {
    const texto = this.texto(bloque);
    const nuevo = this.crearBloque({ t: tipo, x: tipo === 'separador' ? '' : texto, c: false, i: bloque.dataset.i, color: bloque.dataset.color });
    if (bloque.dataset.iFijo !== '1') delete nuevo.dataset.iFijo;
    bloque.replaceWith(nuevo);
    if (tipo === 'separador') {
      const siguiente = nuevo.nextElementSibling || this.insertarDespues(nuevo, { t: 'p', x: '' });
      this.enfocar(siguiente, 'inicio');
    } else {
      this.enfocar(nuevo, 'fin');
    }
    this.programarGuardado();
    return nuevo;
  },

  agregarDebajo(bloque) {
    const nuevo = this.insertarDespues(bloque, { t: 'p', x: '' });
    this.enfocar(nuevo, 'inicio');
    document.execCommand('insertText', false, '/');
  },

  teclas(ev) {
    if (this.menu && this.teclasMenu(ev)) return;
    const bloque = ev.target.closest('.bloque');
    if (!bloque) return;
    const el = this.editableDe(bloque);

    // IA en la nota: Ctrl+J, o Espacio al inicio de un renglón vacío (como en Notion)
    if (typeof this.abrirIaEnLinea === 'function' && this.editable) {
      if ((ev.ctrlKey || ev.metaKey) && !ev.shiftKey && !ev.altKey && ev.key.toLowerCase() === 'j') { ev.preventDefault(); this.abrirIaEnLinea(bloque); return; }
      if (ev.key === ' ' && !ev.ctrlKey && !ev.metaKey && bloque.dataset.t === 'p' && !this.texto(bloque)) { ev.preventDefault(); this.abrirIaEnLinea(bloque); return; }
    }

    // Atajos de bloque: Ctrl+D duplica, Ctrl+Mayús+↑/↓ lo mueve
    if ((ev.ctrlKey || ev.metaKey) && !ev.altKey) {
      if (!ev.shiftKey && ev.key.toLowerCase() === 'd') { ev.preventDefault(); this.duplicar(bloque); return; }
      if (ev.shiftKey && (ev.key === 'ArrowUp' || ev.key === 'ArrowDown')) { ev.preventDefault(); this.mover(bloque, ev.key === 'ArrowUp' ? -1 : 1); return; }
    }

    // Separador enfocado: Enter crea párrafo, Retroceso lo borra
    if (el.matches('.bloque__separador')) {
      if (ev.key === 'Enter') { ev.preventDefault(); this.enfocar(this.insertarDespues(bloque, { t: 'p', x: '' }), 'inicio'); this.programarGuardado(); }
      if (ev.key === 'Backspace' || ev.key === 'Delete') {
        ev.preventDefault();
        const previo = bloque.previousElementSibling || bloque.nextElementSibling;
        bloque.remove();
        if (!this.raiz.children.length) this.raiz.appendChild(this.crearBloque({ t: 'p', x: '' }));
        this.enfocar(previo || this.raiz.firstElementChild, 'fin');
        this.programarGuardado();
      }
      if (ev.key === 'ArrowUp' && bloque.previousElementSibling) { ev.preventDefault(); this.enfocar(bloque.previousElementSibling, 'fin'); }
      if (ev.key === 'ArrowDown' && bloque.nextElementSibling) { ev.preventDefault(); this.enfocar(bloque.nextElementSibling, 'inicio'); }
      return;
    }

    const pos = this.posicion(el);
    const texto = this.texto(bloque);
    const tipo = bloque.dataset.t;

    if (tipo === 'imagen' || tipo === 'grafica') {
      if (ev.key === 'Enter' && !ev.isComposing) { ev.preventDefault(); this.enfocar(this.insertarDespues(bloque, { t: 'p', x: '' }), 'inicio'); this.programarGuardado(); return; }
      if (ev.key === 'Backspace' && pos && pos.colapsado && pos.inicio === 0) { ev.preventDefault(); if (bloque.previousElementSibling) this.enfocar(bloque.previousElementSibling, 'fin'); return; }
      if (ev.key === 'Delete' && pos && pos.colapsado && pos.inicio === texto.length) { ev.preventDefault(); return; }
    }
    if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
      ev.preventDefault();
      const listas = ['lista', 'numerada', 'tarea'];
      if (listas.includes(tipo) && !texto) { this.convertir(bloque, 'p'); return; }
      const corte = pos ? pos.inicio : texto.length;
      const resto = texto.slice(pos ? pos.fin : texto.length);
      el.textContent = texto.slice(0, corte);
      const siguienteTipo = listas.includes(tipo) ? tipo : (tipo === 'cita' && resto ? 'cita' : 'p');
      const nuevo = this.insertarDespues(bloque, { t: siguienteTipo, x: resto });
      this.enfocar(nuevo, 'inicio');
      this.programarGuardado();
      return;
    }

    if (ev.key === 'Backspace' && pos && pos.colapsado && pos.inicio === 0) {
      if (tipo !== 'p') { ev.preventDefault(); const n = this.convertir(bloque, 'p'); this.enfocar(n, 'inicio'); return; }
      const previo = bloque.previousElementSibling;
      if (!previo) return;
      ev.preventDefault();
      if (previo.dataset.t === 'separador') { previo.remove(); this.programarGuardado(); return; }
      if (previo.dataset.t === 'imagen' || previo.dataset.t === 'grafica') { if (!texto) { bloque.remove(); this.programarGuardado(); } this.enfocar(previo, 'fin'); return; }
      const largo = this.texto(previo).length;
      this.editableDe(previo).textContent = this.texto(previo) + texto;
      bloque.remove();
      this.enfocar(previo, largo);
      this.programarGuardado();
      return;
    }

    if (ev.key === 'Delete' && pos && pos.colapsado && pos.inicio === texto.length) {
      const siguiente = bloque.nextElementSibling;
      if (!siguiente) return;
      ev.preventDefault();
      if (siguiente.dataset.t === 'imagen' || siguiente.dataset.t === 'grafica') return;
      if (siguiente.dataset.t !== 'separador') el.textContent = texto + this.texto(siguiente);
      siguiente.remove();
      this.enfocar(bloque, texto.length);
      this.programarGuardado();
      return;
    }

    if (ev.key === 'ArrowUp' && !ev.shiftKey && bloque.previousElementSibling && this.enLinea(el, true)) {
      ev.preventDefault();
      this.enfocar(bloque.previousElementSibling, 'fin');
    }
    if (ev.key === 'ArrowDown' && !ev.shiftKey && bloque.nextElementSibling && this.enLinea(el, false)) {
      ev.preventDefault();
      this.enfocar(bloque.nextElementSibling, 'inicio');
    }
  },

  alEscribir(ev) {
    const bloque = ev.target.closest('.bloque');
    if (!bloque) return;
    const el = this.editableDe(bloque);
    const texto = this.texto(bloque);
    if (!texto && el.innerHTML) el.innerHTML = '';   // deja vacío real para que se vea el placeholder

    // Atajos de Markdown al inicio del bloque
    if (bloque.dataset.t === 'p') {
      if (texto === '---') { el.textContent = ''; this.convertir(bloque, 'separador'); return; }
      for (const [patron, tipo] of ATAJOS) {
        if (patron.test(texto)) {
          el.textContent = texto.replace(patron, '');
          this.convertir(bloque, tipo);   // deja el cursor en el bloque nuevo
          return;
        }
      }
    }

    // Menú de comandos con "/"
    const pos = this.posicion(el);
    if (pos) {
      const antes = texto.slice(0, pos.inicio);
      const barra = antes.lastIndexOf('/');
      if (barra >= 0 && (barra === 0 || /\s/.test(antes[barra - 1])) && !/\s/.test(antes.slice(barra + 1)) && antes.length - barra <= 20) {
        this.abrirMenuComandos(bloque, barra, antes.slice(barra + 1));
      } else {
        this.cerrarMenuComandos();
      }
    }
    this.programarGuardado();
  },

  alPegar(ev) {
    const bloque = ev.target.closest('.bloque');
    if (!bloque || bloque.dataset.t === 'separador') return;
    const archivo = [...(ev.clipboardData.files || [])].find((a) => a.type.startsWith('image/'));
    if (archivo) { ev.preventDefault(); this.subirImagen(archivo, bloque); return; }
    const texto = (ev.clipboardData.getData('text/plain') || '').replace(/\r/g, '');
    if (!texto.includes('\n')) return;   // una línea: lo pega el navegador como texto plano
    ev.preventDefault();
    this.insertarLineas(bloque, texto.split('\n'));
  },

  /** Inserta varias líneas a partir del cursor: la primera en el bloque actual y el resto como bloques. */
  insertarLineas(bloque, lineas) {
    const el = this.editableDe(bloque);
    const pos = this.posicion(el) || { inicio: this.texto(bloque).length, fin: this.texto(bloque).length };
    const texto = this.texto(bloque);
    const resto = texto.slice(pos.fin);
    el.textContent = texto.slice(0, pos.inicio) + lineas[0];
    let ultimo = bloque;
    lineas.slice(1).forEach((linea) => {
      let tipo = 'p';
      let x = linea;
      if (linea.trim() === '---') { tipo = 'separador'; x = ''; }
      for (const [patron, t] of ATAJOS) if (patron.test(linea)) { tipo = t; x = linea.replace(patron, ''); break; }
      ultimo = this.insertarDespues(ultimo, { t: tipo, x });
    });
    if (resto && ultimo.dataset.t !== 'separador') this.editableDe(ultimo).textContent += resto;
    this.enfocar(ultimo, 'fin');
    this.programarGuardado();
  },

  /** Agrega párrafos al final (anotador por voz, importar archivo). */
  agregarAlFinal(lineas) {
    let ultimo = this.raiz.lastElementChild;
    if (ultimo && ultimo.dataset.t === 'p' && !this.texto(ultimo)) {
      this.editableDe(ultimo).textContent = lineas.shift() ?? '';
    }
    this.insertarLineas(this.raiz.lastElementChild, ['', ...lineas].slice(lineas.length ? 0 : 1).length ? ['', ...lineas] : ['']);
    ultimo = this.raiz.lastElementChild;
    this.enfocar(ultimo, 'fin');
  },

  /* Imágenes: selector de archivo, pegar o soltar; se suben y quedan como bloque */
  elegirImagen(bloque, reemplazar = false) {
    const entrada = document.createElement('input');
    entrada.type = 'file';
    entrada.accept = 'image/jpeg,image/png,image/webp,image/gif';
    entrada.addEventListener('change', () => { if (entrada.files[0]) this.subirImagen(entrada.files[0], bloque, reemplazar); });
    entrada.click();
  },

  async subirImagen(archivo, bloque, reemplazar = false) {
    if (archivo.size > 8 * 1024 * 1024) { mostrarNotificacion('error', 'La imagen pesa más de 8 MB.'); return; }
    const datos = new FormData();
    datos.append('imagen', archivo);
    datos.append('pagina_id', await this.asegurarPagina());
    const espera = reemplazar ? bloque : this.insertarDespues(bloque, { t: 'p', x: 'Subiendo imagen…' });
    espera.classList.add('bloque--subiendo');
    try {
      const r = await fetch(`${BASE}/api/paginas/imagen.php`, { method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos });
      const res = await r.json();
      if (!r.ok) throw new Error(res.error || 'No se pudo subir la imagen.');
      const nuevo = this.crearBloque({ t: 'imagen', x: reemplazar ? this.texto(bloque) : '', src: res.ruta, i: reemplazar ? bloque.dataset.i : undefined });
      espera.replaceWith(nuevo);
      if (!reemplazar && bloque.dataset.t === 'p' && !this.texto(bloque)) bloque.remove();
      if (!nuevo.nextElementSibling) this.insertarDespues(nuevo, { t: 'p', x: '' });
      this.enfocar(nuevo, 'fin');
      this.programarGuardado();
    } catch (e) {
      if (reemplazar) espera.classList.remove('bloque--subiendo'); else espera.remove();
      mostrarNotificacion('error', e.message);
    }
  },

  /* Gráficas: se crean desde una base de datos del espacio (o la IA las arma con datos) */
  cambiarGrafica(bloque, cambios) {
    const g = { ...JSON.parse(bloque.dataset.g || '{}'), ...cambios };
    const nuevo = this.crearBloque({ t: 'grafica', x: this.texto(bloque), g, i: bloque.dataset.i });
    bloque.replaceWith(nuevo);
    this.programarGuardado();
  },

  async nuevaGrafica(bloque) {
    const plano = (lista) => (lista || []).flatMap((p) => [p, ...plano(p.hijos)]);
    const vistas = new Set();
    const bases = [...plano(Arbol.datos?.privado), ...plano(Arbol.datos?.compartido)].filter((p) => p.tipo === 'base_datos' && !vistas.has(p.id) && vistas.add(p.id));
    const r = bloque.getBoundingClientRect();
    const ancla = anclaEnPunto(r.left, r.bottom);
    if (!bases.length) {
      mostrarNotificacion('error', 'Crea primero una base de datos o pide al asistente una gráfica con tus datos.');
      return;
    }
    abrirMenu(ancla, [
      { titulo: 'Graficar una base de datos' },
      ...bases.map((b) => ({ icono: 'tabla', texto: b.titulo || 'Sin título', accion: () => this.graficarBase(bloque, b.id) })),
    ], { buscar: bases.length > 8 ? 'Buscar una base de datos…' : undefined, clase: 'menu-editor' });
  },

  async graficarBase(bloque, baseId) {
    try {
      const r = await fetch(`${BASE}/api/paginas/paginas.php?grafica=${encodeURIComponent(baseId)}`);
      const datos = await r.json();
      if (!r.ok) throw new Error(datos.error || 'No se pudo leer la base de datos.');
      const nuevo = this.crearBloque({ t: 'grafica', x: '', g: datos.grafica });
      if (bloque.dataset.t === 'p' && !this.texto(bloque)) bloque.replaceWith(nuevo); else bloque.after(nuevo);
      if (!nuevo.nextElementSibling) this.insertarDespues(nuevo, { t: 'p', x: '' });
      this.programarGuardado();
    } catch (e) { mostrarNotificacion('error', e.message); }
  },

  /* Menú de comandos "/" */
  abrirMenuComandos(bloque, barra, filtro) {
    const termino = normalizarTexto(filtro);
    const ia = typeof this.abrirIaEnLinea === 'function' ? [['ia', { texto: 'Preguntar a la IA', icono: 'chispa', clave: 'ia asistente preguntar resumir escribir' }]] : [];
    const opciones = [...ia, ...Object.entries(BLOQUES)].filter(([, b]) => !termino || normalizarTexto(`${b.texto} ${b.clave}`).includes(termino));
    if (!opciones.length) { this.cerrarMenuComandos(); return; }
    if (!this.menu) {
      const el = document.createElement('div');
      el.className = 'popover popover--menu menu-comandos';
      el.setAttribute('role', 'listbox');
      document.body.appendChild(el);
      this.menu = { el, activo: 0 };
      el.addEventListener('mousedown', (ev) => ev.preventDefault());
      el.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-tipo]');
        if (b) this.elegirComando(b.dataset.tipo);
      });
    }
    Object.assign(this.menu, { bloque, barra, largo: filtro.length + 1, opciones: opciones.map(([t]) => t) });
    this.menu.activo = Math.min(this.menu.activo, opciones.length - 1);
    this.menu.el.innerHTML = '<div class="menu-flotante"><div class="menu-flotante__titulo">Bloques básicos</div>'
      + opciones.map(([t, b], i) => `<button type="button" role="option" data-tipo="${t}" class="${i === this.menu.activo ? 'activo' : ''}"><span class="menu-comandos__icono">${ico(b.icono)}</span><span>${escaparHtml(b.texto)}</span>${t.startsWith('h') ? `<small>${t.replace('h', '#'.repeat(Number(t[1])) + ' ').replace(/[0-9]/g, '')}</small>` : ''}</button>`).join('')
      + '</div>';
    const sel = getSelection();
    const r = sel.rangeCount ? sel.getRangeAt(0).getBoundingClientRect() : bloque.getBoundingClientRect();
    const alto = this.menu.el.offsetHeight;
    const y = r.bottom + 6 + alto > window.innerHeight ? r.top - alto - 6 : r.bottom + 6;
    this.menu.el.style.left = `${Math.max(8, Math.min(r.left, window.innerWidth - 300))}px`;
    this.menu.el.style.top = `${Math.max(8, y)}px`;
  },

  cerrarMenuComandos() {
    if (!this.menu) return;
    this.menu.el.remove();
    this.menu = null;
  },

  teclasMenu(ev) {
    const m = this.menu;
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      ev.preventDefault();
      m.activo = (m.activo + (ev.key === 'ArrowDown' ? 1 : -1) + m.opciones.length) % m.opciones.length;
      m.el.querySelectorAll('[data-tipo]').forEach((b, i) => { b.classList.toggle('activo', i === m.activo); if (i === m.activo) b.scrollIntoView({ block: 'nearest' }); });
      return true;
    }
    if (ev.key === 'Enter') { ev.preventDefault(); this.elegirComando(m.opciones[m.activo]); return true; }
    if (ev.key === 'Escape') { ev.preventDefault(); this.cerrarMenuComandos(); return true; }
    return false;
  },

  elegirComando(tipo) {
    const { bloque, barra, largo } = this.menu;
    this.cerrarMenuComandos();
    const el = this.editableDe(bloque);
    const texto = this.texto(bloque);
    el.textContent = texto.slice(0, barra) + texto.slice(barra + largo);
    if (tipo === 'ia') { this.abrirIaEnLinea(bloque); return; }
    if (tipo === 'imagen') { this.elegirImagen(bloque); return; }
    if (tipo === 'grafica') { this.nuevaGrafica(bloque); return; }
    if (tipo === 'separador') {
      if (!this.texto(bloque)) { this.convertir(bloque, 'separador'); return; }
      const sep = this.insertarDespues(bloque, { t: 'separador', x: '' });
      this.enfocar(this.insertarDespues(sep, { t: 'p', x: '' }), 'inicio');
      this.programarGuardado();
      return;
    }
    this.convertir(bloque, tipo);
  },

  /* Menú contextual: clic derecho sobre un bloque, una selección, una imagen o una gráfica */
  alMenuContextual(ev) {
    if (ev.shiftKey) return;   // Mayús + clic derecho: menú del navegador (revisión ortográfica)
    const bloque = ev.target.closest('.bloque');
    if (!bloque || bloque.parentElement !== this.raiz) return;
    ev.preventDefault();
    this.cerrarMenuComandos();
    const r = bloque.getBoundingClientRect();
    const ancla = ev.clientX || ev.clientY ? anclaEnPunto(ev.clientX, ev.clientY) : anclaEnPunto(r.left + 24, r.top + 22);
    const sel = getSelection();
    const texto = sel.rangeCount && !sel.isCollapsed && this.raiz.contains(sel.anchorNode) ? sel.toString() : '';
    if (texto.trim() && this.puntoEnSeleccion(sel, ev.clientX, ev.clientY)) {
      this.menuSeleccion(ancla, sel.getRangeAt(0).cloneRange(), texto);
      return;
    }
    this.menuBloque(ancla, bloque);
  },

  puntoEnSeleccion(sel, x, y) {
    if (!x && !y) return true;
    return [...sel.getRangeAt(0).getClientRects()].some((r) => x >= r.left - 3 && x <= r.right + 3 && y >= r.top - 3 && y <= r.bottom + 3);
  },

  menuSeleccion(ancla, rango, texto) {
    const nodo = rango.commonAncestorContainer;
    const host = (nodo.nodeType === 1 ? nodo : nodo.parentElement)?.closest('.bloque__texto');
    const restaurar = () => { if (!host) return; host.focus({ preventScroll: true }); const s = getSelection(); s.removeAllRanges(); s.addRange(rango); };
    const corto = texto.trim().replace(/\s+/g, ' ');
    const largo = [...corto].length;
    abrirMenu(ancla, [
      { titulo: `Selección · ${largo} ${largo === 1 ? 'carácter' : 'caracteres'}` },
      { icono: 'copiar', texto: 'Copiar', detalle: 'Ctrl+C', accion: () => copiarTexto(texto, 'Texto copiado.') },
      this.editable && host && { icono: 'cortar', texto: 'Cortar', detalle: 'Ctrl+X', accion: async () => {
        try { await navigator.clipboard.writeText(texto); } catch (e) { mostrarNotificacion('error', 'No se pudo copiar.'); return; }
        restaurar();
        document.execCommand('delete');
      } },
      'separador',
      { icono: 'buscar', texto: 'Buscar en la nota', accion: () => Buscador.abrir(corto.slice(0, 80)) },
      asistenteDisponible() && { icono: 'chispa', texto: 'Preguntar a la IA sobre la selección', accion: () => preguntarIA(texto) },
      this.editable && { icono: 'documento', texto: 'Crear nota con la selección', detalle: 'dentro de esta', accion: () => this.notaDesdeTexto(texto) },
    ], { clase: 'menu-editor', volverA: { focus: restaurar } });
  },

  /** Crea una página dentro de esta con el texto (la primera línea es el título). */
  async notaDesdeTexto(texto) {
    const lineas = texto.replace(/\r/g, '').split('\n');
    const titulo = (lineas.find((l) => l.trim()) || '').trim().slice(0, 80);
    const bloques = lineas.map((linea) => {
      if (linea.trim() === '---') return { t: 'separador', x: '' };
      for (const [patron, t] of ATAJOS) if (patron.test(linea)) return { t, x: linea.replace(patron, '') };
      return { t: 'p', x: linea };
    });
    try {
      await api('paginas/paginas.php', { accion: 'crear', tipo: 'nota', padre_id: await this.asegurarPagina(), titulo, bloques });
      Arbol.abiertos.add(this.paginaId);
      Arbol.guardarAbiertos();
      await Arbol.recargar();
      mostrarNotificacion('exito', `Se creó la nota «${titulo || 'Nueva página'}» dentro de esta página.`);
    } catch (e) { mostrarNotificacion('error', e.message); }
  },

  /* Menú del bloque (clic derecho o clic en el agarre) */
  menuBloque(ancla, bloque) {
    const t = bloque.dataset.t;
    const ed = this.editable;
    const texto = t === 'separador' ? '' : this.texto(bloque);
    const fijo = BLOQUES_FIJOS.includes(t);
    const color = bloque.dataset.color || '';
    const nombreColor = !color ? '' : color.startsWith('fondo_') ? `Fondo ${(COLORES_BLOQUE[color.slice(6)] || '').toLowerCase()}` : (COLORES_BLOQUE[color] || '');
    const insertar = (donde) => () => Object.entries(BLOQUES).map(([k, b]) => ({ icono: b.icono, texto: b.texto, accion: () => this.insertar(bloque, k, donde) }));
    bloque.classList.add('bloque--menu');
    abrirMenu(ancla, [
      { titulo: BLOQUES[t].texto },
      ...(t === 'imagen' ? [...this.itemsImagen(bloque), 'separador'] : []),
      ...(t === 'grafica' ? [...this.itemsGrafica(bloque), 'separador'] : []),
      ed && !fijo && { icono: 'convertir', texto: 'Convertir en', submenu: () => Object.entries(BLOQUES).filter(([k]) => !BLOQUES_FIJOS.includes(k)).map(([k, b]) => ({
        icono: b.icono, texto: b.texto, marcado: k === t, accion: () => { if (k !== t) this.convertir(bloque, k); },
      })) },
      ed && !fijo && { icono: 'paleta', texto: 'Color', detalle: nombreColor, submenu: () => this.itemsColor(bloque) },
      ed && { icono: 'insertar_arriba', texto: 'Insertar arriba', submenu: insertar('arriba') },
      ed && { icono: 'insertar_abajo', texto: 'Insertar abajo', submenu: insertar('abajo') },
      'separador',
      ed && { icono: 'duplicar', texto: 'Duplicar', detalle: 'Ctrl+D', accion: () => this.duplicar(bloque) },
      ed && { icono: 'arriba', texto: 'Mover arriba', detalle: 'Ctrl+Mayús+↑', deshabilitado: !bloque.previousElementSibling?.classList.contains('bloque'), accion: () => this.mover(bloque, -1) },
      ed && { icono: 'abajo', texto: 'Mover abajo', detalle: 'Ctrl+Mayús+↓', deshabilitado: !bloque.nextElementSibling?.classList.contains('bloque'), accion: () => this.mover(bloque, 1) },
      'separador',
      (ed || bloque.dataset.iFijo === '1') && { icono: 'enlace', texto: 'Copiar enlace al bloque', accion: () => this.copiarEnlaceBloque(bloque) },
      texto.trim() && { icono: 'copiar', texto: t === 'imagen' ? 'Copiar pie de imagen' : 'Copiar texto', accion: () => copiarTexto(texto, 'Texto copiado.') },
      { icono: 'buscar', texto: 'Buscar en la nota', accion: () => Buscador.abrir() },
      texto.trim() && asistenteDisponible() && { icono: 'chispa', texto: 'Preguntar a la IA sobre este bloque', accion: () => preguntarIA(texto) },
      'separador',
      ed && { icono: 'papelera', texto: 'Eliminar', peligro: true, accion: () => this.eliminar(bloque) },
    ], { clase: 'menu-editor', alCerrar: () => bloque.classList.remove('bloque--menu') });
  },

  itemsColor(bloque) {
    const actual = bloque.dataset.color || '';
    const fijar = (c) => () => {
      if (c) bloque.dataset.color = c; else delete bloque.dataset.color;
      this.programarGuardado();
    };
    return [
      { titulo: 'Color de texto' },
      { muestra: { color: '' }, texto: 'Predeterminado', marcado: !actual, accion: fijar('') },
      ...Object.entries(COLORES_BLOQUE).map(([c, n]) => ({ muestra: { color: c }, texto: n, marcado: actual === c, accion: fijar(c) })),
      { titulo: 'Color de fondo' },
      ...Object.entries(COLORES_BLOQUE).map(([c, n]) => ({ muestra: { color: c, fondo: true }, texto: `Fondo ${n.toLowerCase()}`, marcado: actual === `fondo_${c}`, accion: fijar(`fondo_${c}`) })),
    ];
  },

  itemsImagen(bloque) {
    const url = urlImagenNota(bloque.dataset.src);
    const ext = (bloque.dataset.src.match(/\.(\w+)$/) || [null, 'jpg'])[1];
    const pie = this.texto(bloque);
    return [
      { icono: 'externo', texto: 'Abrir original', accion: () => window.open(url, '_blank', 'noopener') },
      { icono: 'descargar', texto: 'Descargar', detalle: `.${ext}`, accion: () => {
        const a = document.createElement('a');
        a.href = url;
        a.download = `${nombreDeArchivo(pie || document.body.dataset.titulo || 'imagen')}.${ext}`;
        document.body.appendChild(a);
        a.click();
        a.remove();
      } },
      this.editable && { icono: 'reabrir', texto: 'Reemplazar', accion: () => this.elegirImagen(bloque, true) },
      this.editable && { icono: 'pie', texto: pie ? 'Editar pie de imagen' : 'Agregar pie de imagen', accion: () => this.enfocar(bloque, 'fin') },
      this.editable && { titulo: 'Tamaño' },
      ...(this.editable ? [['s', 'Pequeña'], ['m', 'Mediana'], ['', 'Ancho completo']].map(([tam, texto]) => ({
        texto, marcado: (bloque.dataset.tam || '') === tam,
        accion: () => { if (tam) bloque.dataset.tam = tam; else delete bloque.dataset.tam; this.programarGuardado(); },
      })) : []),
    ];
  },

  itemsGrafica(bloque) {
    let g = {};
    try { g = JSON.parse(bloque.dataset.g || '{}'); } catch (e) { /* sin datos */ }
    const ed = this.editable;
    const { encabezado, filas } = graficaComoFilas(g);
    return [
      ed && { icono: 'grafica', texto: 'Tipo de gráfica', detalle: NOMBRES_GRAFICA[g.tipo] || '', submenu: () => Object.entries(NOMBRES_GRAFICA).map(([k, n]) => ({
        texto: n, marcado: (g.tipo || 'barras') === k, accion: () => this.cambiarGrafica(bloque, { tipo: k }),
      })) },
      { icono: 'tabla', texto: 'Ver datos como tabla', submenu: () => [
        ed && { icono: 'tabla', texto: 'En una base de datos nueva', detalle: 'dentro de esta página', accion: () => this.graficaABase(g) },
        ed && { icono: 'vinetas', texto: 'Como lista debajo de la gráfica', accion: () => this.graficaALista(bloque, g) },
        { icono: 'copiar', texto: 'Copiar datos', detalle: 'para hoja de cálculo', accion: () => copiarTexto([encabezado, ...filas].map((f) => f.join('\t')).join('\n'), 'Datos copiados. Puedes pegarlos en una hoja de cálculo.') },
      ] },
    ];
  },

  async graficaABase(g) {
    const { encabezado, filas } = graficaComoFilas(g);
    try {
      if (this.temporizador) await this.guardar();
      const r = await api('paginas/paginas.php', {
        accion: 'crear', tipo: 'base_datos', padre_id: await this.asegurarPagina(), titulo: g.titulo || 'Datos de la gráfica',
        tabla: { columnas: encabezado.map((nombre, i) => ({ nombre, tipo: i ? 'numero' : 'texto' })), filas },
      });
      location.href = r.url;
    } catch (e) { mostrarNotificacion('error', e.message); }
  },

  graficaALista(bloque, g) {
    const series = g.series || [];
    let ultimo = bloque;
    (g.categorias || []).forEach((c, i) => {
      const valores = series.map((s) => {
        const v = s.valores[i];
        const t = v === null || v === undefined ? '—' : Number(v).toLocaleString('es-MX');
        return series.length > 1 ? `${s.nombre} ${t}` : t;
      });
      ultimo = this.insertarDespues(ultimo, { t: 'lista', x: `${c}: ${valores.join(' · ')}` });
      this.destacar(ultimo);
    });
    this.programarGuardado();
  },

  insertar(ref, tipo, donde) {
    const nuevo = this.crearBloque({ t: ['imagen', 'grafica'].includes(tipo) ? 'p' : tipo, x: '' });
    if (donde === 'arriba') ref.before(nuevo); else ref.after(nuevo);
    if (tipo === 'imagen') { this.elegirImagen(nuevo); return; }
    if (tipo === 'grafica') { this.nuevaGrafica(nuevo); return; }
    this.destacar(nuevo);
    if (tipo === 'separador') nuevo.querySelector('.bloque__separador').focus(); else this.enfocar(nuevo, 'inicio');
    this.programarGuardado();
  },

  duplicar(bloque) {
    const { i, ...datos } = this.datosDe(bloque);
    const copia = this.insertarDespues(bloque, datos);
    this.destacar(copia);
    if (datos.t !== 'separador') this.enfocar(copia, 'fin');
    this.programarGuardado();
  },

  mover(bloque, dir) {
    const otro = dir < 0 ? bloque.previousElementSibling : bloque.nextElementSibling;
    if (!otro?.classList.contains('bloque')) return;
    const el = this.editableDe(bloque);
    const enfocado = bloque.contains(document.activeElement);
    const pos = enfocado && el?.isContentEditable ? this.posicion(el) : null;
    if (dir < 0) otro.before(bloque); else otro.after(bloque);
    if (enfocado) { if (el?.isContentEditable) this.enfocar(bloque, pos ? pos.inicio : 'fin'); else el?.focus(); }
    bloque.scrollIntoView({ block: 'nearest' });
    this.destacar(bloque);
    this.programarGuardado();
  },

  eliminar(bloque) {
    const vecino = bloque.previousElementSibling || bloque.nextElementSibling;
    bloque.remove();
    if (!this.raiz.querySelector('.bloque')) this.raiz.appendChild(this.crearBloque({ t: 'p', x: '' }));
    this.enfocar(vecino?.classList.contains('bloque') ? vecino : this.raiz.firstElementChild, 'fin');
    this.programarGuardado();
  },

  destacar(bloque, enlace = false) {
    const clase = enlace ? 'bloque--enlazado' : 'bloque--destacado';
    bloque.classList.remove(clase);
    void bloque.offsetWidth;
    bloque.classList.add(clase);
    setTimeout(() => bloque.classList.remove(clase), enlace ? 2600 : 1200);
  },

  copiarEnlaceBloque(bloque) {
    if (!bloque.dataset.i) bloque.dataset.i = idBloque();
    if (this.editable && bloque.dataset.iFijo !== '1' && !this.bloqueado) this.guardar();   // guarda el ancla del bloque
    copiarEnlace(`${location.origin}${BASE}/paginas/ver.php?id=${this.paginaId}#b-${bloque.dataset.i}`);
  },

  /** Enlace directo a un bloque (#b-xxxxxxxx): lo muestra y lo resalta un momento. */
  irAlBloqueDelEnlace() {
    const m = location.hash.match(/^#b-([a-z0-9]{8})$/);
    const bloque = m && this.raiz.querySelector(`.bloque[data-i="${m[1]}"]`);
    if (!bloque) return;
    requestAnimationFrame(() => {
      bloque.scrollIntoView({ block: 'center' });
      this.destacar(bloque, true);
    });
  },

  /* Arrastrar bloques por el agarre para reordenarlos */
  arrastrando: false,
  iniciarArrastre(ev) {
    const agarre = ev.target.closest('[data-bloque-agarre]');
    if (!agarre || ev.button !== 0) return;
    const bloque = agarre.closest('.bloque');
    const inicioY = ev.clientY;
    let movio = false;
    let destino = null;
    const linea = document.createElement('div');
    linea.className = 'editor__indicador';
    const mover = (e) => {
      if (!movio && Math.abs(e.clientY - inicioY) < 4) return;
      if (!movio) { movio = true; this.arrastrando = true; bloque.classList.add('arrastrando'); this.raiz.appendChild(linea); }
      const bloques = [...this.raiz.querySelectorAll(':scope > .bloque')].filter((b) => b !== bloque);
      destino = null;
      for (const b of bloques) {
        const r = b.getBoundingClientRect();
        if (e.clientY < r.top + r.height / 2) { destino = b; break; }
      }
      const ref = destino || bloques[bloques.length - 1];
      if (!ref) return;
      const r = ref.getBoundingClientRect();
      const base = this.raiz.getBoundingClientRect();
      linea.style.top = `${(destino ? r.top : r.bottom) - base.top - 1}px`;
    };
    const soltar = () => {
      document.removeEventListener('pointermove', mover);
      document.removeEventListener('pointerup', soltar);
      if (movio) {
        linea.remove();
        bloque.classList.remove('arrastrando');
        if (destino) destino.before(bloque); else this.raiz.appendChild(bloque);
        this.programarGuardado();
        setTimeout(() => { this.arrastrando = false; }, 0);
      }
    };
    document.addEventListener('pointermove', mover);
    document.addEventListener('pointerup', soltar);
  },
};

/* ---------- Buscar en la nota: resalta las coincidencias (sin acentos ni mayúsculas) ---------- */
/** Pliega cada carácter sin cambiar la longitud del texto (los desplazamientos sirven para los rangos). */
const plegarTexto = (s) => s.split('').map((c) => {
  const f = c.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  return f.length === 1 ? f : (c.toLowerCase().charAt(0) || c);
}).join('');

const Buscador = {
  el: null,
  rangos: [],
  actual: 0,
  espera: null,
  vigilando: false,

  abrir(inicial = '') {
    if (!Editor.raiz) return;
    if (!this.el) {
      this.el = document.createElement('div');
      this.el.className = 'buscador-nota';
      this.el.setAttribute('role', 'search');
      this.el.innerHTML = `${ico('buscar')}<input type="text" placeholder="Buscar en la nota" aria-label="Buscar en la nota" spellcheck="false" autocomplete="off">`
        + '<span class="buscador-nota__cuenta" aria-live="polite"></span>'
        + `<button type="button" data-anterior title="Anterior (Mayús + Entrar)" aria-label="Coincidencia anterior">${ico('arriba')}</button>`
        + `<button type="button" data-siguiente title="Siguiente (Entrar)" aria-label="Coincidencia siguiente">${ico('abajo')}</button>`
        + `<button type="button" data-cerrar title="Cerrar (Esc)" aria-label="Cerrar la búsqueda">${ico('cerrar')}</button>`;
      document.body.appendChild(this.el);
      const entrada = this.el.querySelector('input');
      entrada.addEventListener('input', () => { clearTimeout(this.espera); this.espera = setTimeout(() => this.buscar(), 120); });
      entrada.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); this.ir(ev.shiftKey ? -1 : 1); }
        if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); this.cerrar(); }
      });
      this.el.addEventListener('click', (ev) => {
        if (ev.target.closest('[data-anterior]')) this.ir(-1);
        if (ev.target.closest('[data-siguiente]')) this.ir(1);
        if (ev.target.closest('[data-cerrar]')) this.cerrar();
      });
      if (!this.vigilando) {
        this.vigilando = true;
        // Si la nota cambia mientras se busca, las coincidencias se recalculan
        Editor.raiz.addEventListener('input', () => { if (this.el) { clearTimeout(this.espera); this.espera = setTimeout(() => this.buscar(true), 250); } });
      }
    }
    const barra = document.querySelector('.barra-superior')?.getBoundingClientRect();
    const zona = document.querySelector('.contenido__pagina')?.getBoundingClientRect();
    this.el.style.top = `${Math.round((barra?.bottom ?? 48) + 8)}px`;
    this.el.style.right = `${Math.round(window.innerWidth - (zona?.right ?? window.innerWidth) + 20)}px`;
    const entrada = this.el.querySelector('input');
    if (inicial) entrada.value = inicial;
    entrada.focus();
    entrada.select();
    this.buscar();
  },

  buscar(conservar = false) {
    if (!this.el) return;
    const termino = plegarTexto(this.el.querySelector('input').value);
    const previo = this.actual;
    this.rangos = [];
    if (termino.trim()) {
      Editor.raiz.querySelectorAll('.bloque__texto').forEach((el) => {
        const caminante = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
        for (let nodo = caminante.nextNode(); nodo; nodo = caminante.nextNode()) {
          const texto = plegarTexto(nodo.data);
          for (let i = texto.indexOf(termino); i >= 0; i = texto.indexOf(termino, i + termino.length)) {
            const r = document.createRange();
            r.setStart(nodo, i);
            r.setEnd(nodo, i + termino.length);
            this.rangos.push(r);
          }
        }
      });
    }
    this.actual = conservar ? Math.min(previo, Math.max(0, this.rangos.length - 1)) : 0;
    this.pintar(!conservar);
  },

  pintar(desplazar = true) {
    const n = this.rangos.length;
    const hayTexto = !!this.el.querySelector('input').value.trim();
    this.el.querySelector('.buscador-nota__cuenta').textContent = hayTexto ? (n ? `${this.actual + 1} de ${n}` : 'Sin resultados') : '';
    this.el.classList.toggle('sin-resultados', hayTexto && !n);
    this.el.querySelectorAll('[data-anterior], [data-siguiente]').forEach((b) => { b.disabled = n < 2; });
    if (window.CSS?.highlights && typeof Highlight === 'function') {
      CSS.highlights.set('busqueda-nota', new Highlight(...this.rangos));
      if (n) CSS.highlights.set('busqueda-nota-actual', new Highlight(this.rangos[this.actual]));
      else CSS.highlights.delete('busqueda-nota-actual');
    }
    if (n && desplazar) this.rangos[this.actual].startContainer.parentElement?.closest('.bloque')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
  },

  ir(paso) {
    const n = this.rangos.length;
    if (!n) return;
    this.actual = (this.actual + paso + n) % n;
    this.pintar();
  },

  cerrar() {
    clearTimeout(this.espera);
    if (window.CSS?.highlights) { CSS.highlights.delete('busqueda-nota'); CSS.highlights.delete('busqueda-nota-actual'); }
    if (this.el) quitarConAnimacion(this.el, 'cerrando');
    this.el = null;
    this.rangos = [];
  },
};

/* ---------- Página vacía: "Elige una opción" ---------- */
function iniciarOpcionesVacia() {
  const caja = document.querySelector('[data-opciones-vacia]');
  if (!caja) return;
  if (document.body.dataset.paginaTipo !== 'nota') {
    caja.hidden = !!document.querySelector('[data-hoja-hijos] li');
  }
  const alternarMenu = (boton, menu) => {
    const abrir = menu.hidden;
    caja.querySelectorAll('[role="menu"]').forEach((m) => { m.hidden = true; });
    caja.querySelectorAll('[aria-haspopup]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
    menu.hidden = !abrir;
    boton.setAttribute('aria-expanded', String(abrir));
  };
  document.addEventListener('click', (ev) => { if (!ev.target.closest('.opciones-vacia .ancla-popover')) caja.querySelectorAll('[role="menu"]').forEach((m) => { m.hidden = true; }); });

  caja.addEventListener('click', async (ev) => {
    const opcion = ev.target.closest('[data-opcion]')?.dataset.opcion;
    if (opcion === 'ia') {
      document.querySelector('[data-asistente-abrir]')?.click();
    } else if (opcion === 'anotador') {
      Anotador.alternar(ev.target.closest('[data-opcion]'));
    } else if (opcion === 'base_datos') {
      try { await api('paginas/paginas.php', { accion: 'convertir', id: PAGINA_ID }); location.reload(); } catch (e) { mostrarNotificacion('error', e.message); }
    } else if (opcion === 'plantillas') {
      alternarMenu(ev.target.closest('[data-opcion]'), caja.querySelector('[data-plantillas]'));
    } else if (opcion === 'mas') {
      alternarMenu(ev.target.closest('[data-opcion]'), caja.querySelector('[data-opciones-mas]'));
    }
    const plantilla = ev.target.closest('[data-plantilla]');
    if (plantilla) {
      try { await api('paginas/paginas.php', { accion: 'plantilla', id: PAGINA_ID, plantilla: plantilla.dataset.plantilla }); location.reload(); } catch (e) { mostrarNotificacion('error', e.message); }
    }
    if (ev.target.closest('[data-importar]')) caja.querySelector('[data-importar-archivo]').click();
  });

  caja.querySelector('[data-importar-archivo]')?.addEventListener('change', async (ev) => {
    const archivo = ev.target.files[0];
    ev.target.value = '';
    if (!archivo) return;
    if (archivo.size > 500 * 1024) { mostrarNotificacion('error', 'El archivo es muy grande (máximo 500 KB de texto).'); return; }
    const lineas = (await archivo.text()).replace(/\r/g, '').split('\n');
    const primero = Editor.raiz.firstElementChild;
    Editor.insertarLineas(primero, lineas);
    if (!Hoja.titulo.textContent.trim()) {
      Hoja.titulo.textContent = archivo.name.replace(/\.[^.]+$/, '');
      Hoja.titulo.dispatchEvent(new Event('input'));
    }
  });
}

/* ---------- Anotador con IA: dicta y el texto (transcrito en el equipo) se agrega a la nota ---------- */
const Anotador = {
  grabadora: null,
  barra: null,
  inicio: 0,
  reloj: null,

  async alternar(boton) {
    if (this.grabadora?.state === 'recording') { this.grabadora.stop(); return; }
    let flujo;
    try { flujo = await navigator.mediaDevices.getUserMedia({ audio: true }); }
    catch (e) { mostrarNotificacion('error', 'No se pudo usar el micrófono. Revisa el permiso del navegador.'); return; }
    const trozos = [];
    this.grabadora = new MediaRecorder(flujo);
    this.grabadora.ondataavailable = (e) => { if (e.data.size) trozos.push(e.data); };
    this.grabadora.onstop = async () => {
      flujo.getTracks().forEach((t) => t.stop());
      clearInterval(this.reloj);
      this.barra.querySelector('[data-anotador-estado]').textContent = 'Transcribiendo en el equipo…';
      this.barra.querySelector('[data-anotador-detener]').hidden = true;
      const datos = new FormData();
      datos.append('audio', new Blob(trozos, { type: this.grabadora.mimeType }), 'nota.webm');
      try {
        const r = await (await fetch(`${BASE}/api/pacientes/dictado.php`, { method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos })).json();
        if (!r.ok) throw new Error(r.error);
        if (r.texto?.trim()) Editor.agregarAlFinal(r.texto.trim().split(/\n+/));
        else mostrarNotificacion('error', 'No se escuchó ningún texto.');
      } catch (e) { mostrarNotificacion('error', e.message || 'No se pudo transcribir.'); }
      quitarConAnimacion(this.barra, 'cerrando');
      this.barra = null;
      this.grabadora = null;
    };
    this.grabadora.start();
    this.inicio = Date.now();
    this.barra = document.createElement('div');
    this.barra.className = 'anotador';
    this.barra.innerHTML = `<span class="anotador__punto" aria-hidden="true"></span><span data-anotador-estado>Escuchando · 0:00</span><button type="button" class="boton boton--sutil" data-anotador-detener>Detener y agregar</button>`;
    document.querySelector('[data-opciones-vacia]')?.after(this.barra);
    if (!this.barra.isConnected) document.querySelector('.contenido__pagina').appendChild(this.barra);
    this.barra.querySelector('[data-anotador-detener]').addEventListener('click', () => this.grabadora?.stop());
    this.reloj = setInterval(() => {
      const s = Math.floor((Date.now() - this.inicio) / 1000);
      this.barra.querySelector('[data-anotador-estado]').textContent = `Escuchando · ${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
    }, 500);
    boton?.blur();
  },
};

/* ---------- Base de datos (tabla editable) ---------- */
const TIPO_ICONO = { texto: 'aa', numero: 'numero', fecha: 'calendario', seleccion: 'seleccion', casilla: 'casilla' };
/* Condiciones de filtro por tipo de propiedad: [clave, texto, ¿necesita valor?] */
const CONDICIONES = {
  texto: [['contiene', 'Contiene', true], ['no_contiene', 'No contiene', true], ['es', 'Es', true], ['vacio', 'Está vacío', false], ['no_vacio', 'No está vacío', false]],
  numero: [['igual', 'Igual a', true], ['distinto', 'Distinto de', true], ['mayor', 'Mayor que', true], ['menor', 'Menor que', true], ['mayor_igual', 'Mayor o igual que', true], ['menor_igual', 'Menor o igual que', true], ['vacio', 'Está vacío', false], ['no_vacio', 'No está vacío', false]],
  fecha: [['es', 'Es', true], ['antes', 'Antes de', true], ['despues', 'Después de', true], ['vacio', 'Está vacía', false], ['no_vacio', 'No está vacía', false]],
  seleccion: [['es', 'Es', true], ['no_es', 'No es', true], ['vacio', 'Está vacía', false], ['no_vacio', 'No está vacía', false]],
  casilla: [['marcada', 'Marcada', false], ['sin_marcar', 'Sin marcar', false]],
};

const Bdp = {
  raiz: null,
  datos: null,
  editable: false,
  filtro: '',
  // Vista personal (solo en este navegador): orden, filtros, columnas ocultas y columnas inmovilizadas
  vista: { orden: null, filtros: [], ocultas: [], congelada: null },
  claveVista: `femeci-bdp-vista-${USUARIO_ID}-${PAGINA_ID}`,

  iniciar() {
    this.raiz = document.querySelector('[data-bdp]');
    if (!this.raiz) return;
    this.datos = JSON.parse(document.getElementById('bdp-datos').textContent);
    this.editable = document.querySelector('[data-hoja]').dataset.editable === '1';
    try { Object.assign(this.vista, JSON.parse(localStorage.getItem(this.claveVista) || '{}')); } catch (e) { /* opcional */ }
    this.limpiarVista();
    this.pintar();
    this.raiz.addEventListener('click', (ev) => this.alClic(ev));
    this.raiz.addEventListener('contextmenu', (ev) => this.alMenuContextual(ev));
    window.addEventListener('resize', () => this.aplicarCongelado());
  },

  guardarVista() {
    this.limpiarVista();
    try { localStorage.setItem(this.claveVista, JSON.stringify(this.vista)); } catch (e) { /* opcional */ }
  },
  /** Quita de la vista las columnas que ya no existen. */
  limpiarVista() {
    const existe = (id) => this.datos.columnas.some((c) => c.id === id);
    const v = this.vista;
    if (v.orden && !existe(v.orden.col)) v.orden = null;
    v.filtros = (v.filtros || []).filter((f) => existe(f.col));
    v.ocultas = (v.ocultas || []).filter((id) => existe(id) && id !== this.datos.columnas[0].id);
    if (v.congelada && !existe(v.congelada)) v.congelada = null;
  },

  async accion(cuerpo) {
    return api('paginas/base_datos.php', { id: PAGINA_ID, ...cuerpo });
  },

  formato(col, v) {
    if (v === null || v === undefined || v === '') return '';
    if (col.tipo === 'fecha') { const [a, m, d] = String(v).split('-'); return `${d}/${m}/${a}`; }
    if (col.tipo === 'numero') return Number(v).toLocaleString('es-MX');
    if (col.tipo === 'casilla') return v ? 'Sí' : 'No';
    return String(v);
  },

  celdaHtml(col, fila, primera) {
    const v = fila.valores[col.id];
    if (col.tipo === 'casilla') {
      return `<td data-col="${col.id}" class="bdp__celda bdp__celda--casilla"><span class="bloque__casilla" role="checkbox" aria-checked="${v ? 'true' : 'false'}"></span></td>`;
    }
    if (col.tipo === 'seleccion') {
      const opcion = (col.opciones || []).find((o) => o.nombre === v);
      return `<td data-col="${col.id}" class="bdp__celda">${v ? `<span class="opcion opcion--${opcion?.color || 'gris'}">${escaparHtml(v)}</span>` : ''}</td>`;
    }
    return `<td data-col="${col.id}" class="bdp__celda${primera ? ' bdp__celda--titulo' : ''}${col.tipo === 'numero' ? ' bdp__celda--numero' : ''}">${escaparHtml(this.formato(col, v))}</td>`;
  },

  columnasVisibles() { return this.datos.columnas.filter((c, i) => i === 0 || !this.vista.ocultas.includes(c.id)); },

  cumpleFiltro(f, fila) {
    const col = this.columna(f.col);
    if (!col) return true;
    const v = fila.valores[col.id];
    const vacio = v === null || v === undefined || v === '' || v === false;
    if (f.op === 'vacio') return vacio;
    if (f.op === 'no_vacio') return !vacio;
    if (f.op === 'marcada') return !!v;
    if (f.op === 'sin_marcar') return !v;
    if (f.valor === '' || f.valor === undefined || f.valor === null) return true;   // sin valor todavía: no filtra
    if (col.tipo === 'numero') {
      const a = Number(v); const b = Number(f.valor);
      if (vacio || Number.isNaN(b)) return f.op === 'distinto';
      return { igual: a === b, distinto: a !== b, mayor: a > b, menor: a < b, mayor_igual: a >= b, menor_igual: a <= b }[f.op] ?? true;
    }
    if (col.tipo === 'fecha') {
      if (vacio) return false;
      return { es: v === f.valor, antes: v < f.valor, despues: v > f.valor }[f.op] ?? true;
    }
    const a = normalizarTexto(String(v ?? ''));
    const b = normalizarTexto(String(f.valor));
    return { contiene: a.includes(b), no_contiene: !a.includes(b), es: a === b, no_es: a !== b }[f.op] ?? true;
  },

  /** Filas tal como se ven: búsqueda, filtros y orden de la vista. */
  filasVisibles() {
    const { columnas, filas } = this.datos;
    const termino = normalizarTexto(this.filtro);
    let lista = filas.filter((f) => (!termino || normalizarTexto(columnas.map((c) => this.formato(c, f.valores[c.id])).join(' ')).includes(termino))
      && this.vista.filtros.every((filtro) => this.cumpleFiltro(filtro, f)));
    const orden = this.vista.orden && this.columna(this.vista.orden.col);
    if (orden) {
      const dir = this.vista.orden.dir === 'desc' ? -1 : 1;
      const vacio = (v) => v === null || v === undefined || v === '';
      lista = [...lista].sort((x, y) => {
        const a = x.valores[orden.id]; const b = y.valores[orden.id];
        if (orden.tipo !== 'casilla' && (vacio(a) || vacio(b))) return vacio(a) === vacio(b) ? 0 : vacio(a) ? 1 : -1;   // vacías al final
        if (orden.tipo === 'numero') return (Number(a) - Number(b)) * dir;
        if (orden.tipo === 'casilla') return ((a ? 1 : 0) - (b ? 1 : 0)) * dir;
        return String(a).localeCompare(String(b), 'es', { numeric: true, sensitivity: 'base' }) * dir;
      });
    }
    return lista;
  },

  textoFiltro(f) {
    const col = this.columna(f.col);
    const [, texto, conValor] = (CONDICIONES[col.tipo] || CONDICIONES.texto).find(([k]) => k === f.op) || [null, 'Filtro', false];
    if (!conValor) return `${col.nombre}: ${texto.toLowerCase()}`;
    if (f.valor === '' || f.valor === undefined) return `${col.nombre}: ${texto.toLowerCase()}…`;
    return `${col.nombre}: ${texto.toLowerCase()} ${col.tipo === 'fecha' ? this.formato(col, f.valor) : `«${f.valor}»`}`;
  },

  pintar() {
    const { filas } = this.datos;
    const columnas = this.columnasVisibles();
    const visibles = this.filasVisibles();
    const v = this.vista;
    const ordenCol = v.orden && this.columna(v.orden.col);
    const chips = [
      ordenCol ? `<button type="button" class="bdp__chip" data-chip-orden>${ico(v.orden.dir === 'desc' ? 'orden_desc' : 'orden_asc')}<span>${escaparHtml(ordenCol.nombre)}</span></button>` : '',
      ...v.filtros.map((f, i) => `<button type="button" class="bdp__chip" data-chip-filtro="${i}">${ico('filtro')}<span>${escaparHtml(this.textoFiltro(f))}</span></button>`),
      v.ocultas.length ? `<button type="button" class="bdp__chip" data-chip-ocultas>${ico('ocultar')}<span>${v.ocultas.length} ${v.ocultas.length === 1 ? 'propiedad oculta' : 'propiedades ocultas'}</span></button>` : '',
    ].join('');
    this.raiz.innerHTML = `
      <div class="bdp__barra">
        <span class="bdp__vista">${ico('tabla')} Tabla</span>
        <span class="bdp__chips">${chips}</span>
        <span class="bdp__espacio"></span>
        <label class="bdp__buscar${this.filtro ? ' abierto' : ''}">${ico('buscar')}<input type="text" placeholder="Escribe para buscar…" value="${escaparHtml(this.filtro)}" data-bdp-buscar aria-label="Buscar en la tabla" autocomplete="off"></label>
        ${this.editable ? '<button type="button" class="boton boton--primario bdp__nuevo" data-fila-nueva>Nuevo</button>' : ''}
      </div>
      <div class="bdp__marco">
        <table class="bdp-tabla">
          <thead><tr>
            ${columnas.map((c) => `<th scope="col" data-col="${c.id}"><button type="button" data-col-menu aria-haspopup="menu" aria-expanded="false">${ico(TIPO_ICONO[c.tipo])}<span>${escaparHtml(c.nombre)}</span>${v.orden?.col === c.id ? `<i class="bdp__orden" title="${v.orden.dir === 'desc' ? 'Orden descendente' : 'Orden ascendente'}">${ico(v.orden.dir === 'desc' ? 'abajo' : 'arriba')}</i>` : ''}${v.filtros.some((f) => f.col === c.id) ? `<i class="bdp__orden" title="Con filtro">${ico('filtro')}</i>` : ''}</button></th>`).join('')}
            ${this.editable ? `<th class="bdp__agregar-col"><button type="button" data-col-nueva title="Agregar una propiedad" aria-haspopup="menu" aria-expanded="false">${ico('agregar')}</button></th>` : ''}
          </tr></thead>
          <tbody>
            ${visibles.map((f) => `<tr data-fila="${f.id}">${columnas.map((c, i) => this.celdaHtml(c, f, i === 0)).join('')}${this.editable ? `<td class="bdp__fila-acciones"><button type="button" data-fila-menu title="Opciones de la fila" aria-haspopup="menu" aria-expanded="false">${ico('mas')}</button></td>` : ''}</tr>`).join('')}
          </tbody>
        </table>
      </div>
      ${!filas.length ? `<p class="bdp__vacio">${this.editable ? 'Aún no hay filas.' : 'Esta base de datos está vacía.'}</p>` : ''}
      ${filas.length && !visibles.length ? `<p class="bdp__vacio">Ninguna fila coincide con ${this.filtro ? 'la búsqueda' : 'los filtros'}.</p>` : ''}
      ${this.editable ? `<button type="button" class="bdp__nueva-fila" data-fila-nueva>${ico('agregar')} Nueva fila</button>` : ''}`;
    const buscar = this.raiz.querySelector('[data-bdp-buscar]');
    buscar.addEventListener('input', () => {
      this.filtro = buscar.value;
      const pos = buscar.selectionStart;
      this.pintar();
      const nuevo = this.raiz.querySelector('[data-bdp-buscar]');
      nuevo.focus();
      nuevo.setSelectionRange(pos, pos);
    });
    buscar.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && buscar.value) { ev.preventDefault(); this.filtro = ''; this.pintar(); } });
    this.aplicarCongelado();
  },

  /** Inmoviliza las columnas hasta la elegida (quedan fijas al desplazar la tabla a lo ancho). */
  aplicarCongelado() {
    if (!this.raiz) return;
    const columnas = this.columnasVisibles();
    const hasta = columnas.findIndex((c) => c.id === this.vista.congelada);
    if (hasta < 0) return;
    const encabezados = [...this.raiz.querySelectorAll('.bdp-tabla thead th')];
    const izquierdas = [];
    let x = 0;
    for (let i = 0; i <= hasta; i++) { izquierdas.push(x); x += encabezados[i]?.getBoundingClientRect().width || 0; }
    this.raiz.querySelectorAll('.bdp-tabla tr').forEach((tr) => {
      [...tr.children].slice(0, hasta + 1).forEach((celda, i) => {
        celda.classList.add('bdp__congelada');
        celda.classList.toggle('bdp__congelada--ultima', i === hasta);
        celda.style.left = `${izquierdas[i]}px`;
      });
    });
  },

  async alClic(ev) {
    if (ev.target.closest('.bdp__buscar') && !ev.target.closest('input')) {
      const label = ev.target.closest('.bdp__buscar');
      label.classList.add('abierto');
      label.querySelector('input').focus();
      return;
    }
    const chipOrden = ev.target.closest('[data-chip-orden]');
    if (chipOrden) { this.menuOrden(chipOrden); return; }
    const chipFiltro = ev.target.closest('[data-chip-filtro]');
    if (chipFiltro) { this.menuFiltro(chipFiltro, Number(chipFiltro.dataset.chipFiltro)); return; }
    const chipOcultas = ev.target.closest('[data-chip-ocultas]');
    if (chipOcultas) { this.menuOcultas(chipOcultas); return; }
    const colBoton = ev.target.closest('[data-col-menu]');
    if (colBoton) { this.menuColumna(colBoton, colBoton.closest('th').dataset.col); return; }
    if (!this.editable) return;
    if (ev.target.closest('[data-fila-nueva]')) { this.nuevaFila(); return; }
    if (ev.target.closest('[data-col-nueva]')) { this.menuNuevaColumna(ev.target.closest('[data-col-nueva]')); return; }
    const filaMenu = ev.target.closest('[data-fila-menu]');
    if (filaMenu) { this.menuFila(filaMenu, Number(filaMenu.closest('tr').dataset.fila), { alinear: 'derecha' }); return; }
    const celda = ev.target.closest('td[data-col]');
    if (celda && !celda.querySelector('input')) this.editarCelda(celda);
  },

  alMenuContextual(ev) {
    if (ev.shiftKey || ev.target.closest('input')) return;
    const th = ev.target.closest('th[data-col]');
    if (th) { ev.preventDefault(); this.menuColumna(anclaEnPunto(ev.clientX, ev.clientY), th.dataset.col); return; }
    const tr = ev.target.closest('tr[data-fila]');
    if (tr) { ev.preventDefault(); this.menuFila(anclaEnPunto(ev.clientX, ev.clientY), Number(tr.dataset.fila), {}, ev.target.closest('td[data-col]')?.dataset.col); }
  },

  columna(id) { return this.datos.columnas.find((c) => c.id === id); },
  fila(id) { return this.datos.filas.find((f) => f.id === id); },
  /** El encabezado de una columna después de volver a pintar (las anclas se recrean). */
  anclaColumna(colId, ancla) { return ancla?.isConnected ? ancla : (this.raiz.querySelector(`th[data-col="${colId}"] [data-col-menu]`) || ancla); },

  async nuevaFila(referencia = null) {
    try {
      const r = await this.accion({ accion: 'fila_agregar', ...(referencia || {}) });
      const fila = { id: r.fila, valores: r.valores && !Array.isArray(r.valores) ? r.valores : {} };
      const ref = referencia && (referencia.antes_de || referencia.despues_de || referencia.duplicar);
      const i = ref ? this.datos.filas.findIndex((f) => f.id === ref) : -1;
      if (i >= 0) this.datos.filas.splice(referencia.antes_de ? i : i + 1, 0, fila); else this.datos.filas.push(fila);
      this.filtro = '';
      this.pintar();
      marcarEdicionAhora();
      const tr = this.raiz.querySelector(`tr[data-fila="${r.fila}"]`);
      tr?.classList.add('bdp__fila--nueva');
      const celda = tr?.querySelector('td[data-col]');
      if (celda && !referencia?.duplicar) this.editarCelda(celda);
    } catch (e) { mostrarNotificacion('error', e.message); }
  },

  async eliminarFila(id) {
    try {
      await this.accion({ accion: 'fila_eliminar', fila: id });
      this.datos.filas = this.datos.filas.filter((f) => f.id !== id);
      this.pintar();
      marcarEdicionAhora();
    } catch (e) { mostrarNotificacion('error', e.message); }
  },

  async guardarCelda(filaId, colId, valor) {
    try {
      const r = await this.accion({ accion: 'celda', fila: filaId, columna: colId, valor });
      this.fila(filaId).valores[colId] = r.valor;
      if (r.columna) Object.assign(this.columna(colId), r.columna);
      marcarEdicionAhora();
    } catch (e) { mostrarNotificacion('error', e.message); }
    this.pintar();
  },

  editarCelda(celda) {
    const filaId = Number(celda.closest('tr').dataset.fila);
    const col = this.columna(celda.dataset.col);
    const actual = this.fila(filaId).valores[col.id];
    marcarEditando();
    if (col.tipo === 'casilla') { this.guardarCelda(filaId, col.id, !actual); return; }
    if (col.tipo === 'seleccion') { this.editarSeleccion(celda, filaId, col, actual); return; }
    const entrada = document.createElement('input');
    entrada.className = 'bdp__entrada';
    entrada.type = col.tipo === 'numero' ? 'number' : col.tipo === 'fecha' ? 'date' : 'text';
    entrada.value = actual ?? '';
    if (col.tipo === 'numero') entrada.step = 'any';
    celda.textContent = '';
    celda.classList.add('editando');
    celda.appendChild(entrada);
    entrada.focus();
    let hecho = false;
    const terminar = (guardar) => {
      if (hecho) return;
      hecho = true;
      const valor = entrada.value;
      if (guardar && String(valor) !== String(actual ?? '')) this.guardarCelda(filaId, col.id, valor);
      else this.pintar();
    };
    entrada.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') { ev.preventDefault(); terminar(true); }
      if (ev.key === 'Escape') { ev.preventDefault(); terminar(false); }
      if (ev.key === 'Tab') {
        ev.preventDefault();
        const siguiente = ev.shiftKey ? celda.previousElementSibling : celda.nextElementSibling;
        terminar(true);
        if (siguiente?.dataset.col) setTimeout(() => this.editarCelda(this.raiz.querySelector(`tr[data-fila="${filaId}"] td[data-col="${siguiente.dataset.col}"]`)), 60);
      }
    });
    entrada.addEventListener('blur', () => terminar(true));
  },

  editarSeleccion(celda, filaId, col, actual) {
    const caja = document.createElement('div');
    caja.className = 'seleccion-editor';
    caja.innerHTML = `<input type="text" class="menu-flotante__entrada" placeholder="Busca o crea una opción" maxlength="100" aria-label="Opción"><div class="menu-flotante seleccion-editor__lista"></div>`;
    const entrada = caja.querySelector('input');
    const lista = caja.querySelector('.seleccion-editor__lista');
    const pintarLista = () => {
      const termino = normalizarTexto(entrada.value.trim());
      const opciones = (col.opciones || []).filter((o) => !termino || normalizarTexto(o.nombre).includes(termino));
      const existe = (col.opciones || []).some((o) => normalizarTexto(o.nombre) === termino);
      lista.innerHTML = '<div class="menu-flotante__titulo">Selecciona una opción o crea una</div>'
        + opciones.map((o) => `<button type="button" data-opcion-valor="${escaparHtml(o.nombre)}"><span class="seleccion-editor__opcion"><span class="opcion opcion--${o.color}">${escaparHtml(o.nombre)}</span></span>${o.nombre === actual ? SVG_MARCA : ''}</button>`).join('')
        + (entrada.value.trim() && !existe ? `<button type="button" data-opcion-valor="${escaparHtml(entrada.value.trim())}"><small>Crear</small><span class="seleccion-editor__opcion"><span class="opcion opcion--gris">${escaparHtml(entrada.value.trim())}</span></span></button>` : '')
        + (actual ? `<div class="menu-flotante__separador"></div><button type="button" data-opcion-valor="">${ico('cerrar')}<span>Quitar el valor</span></button>` : '');
    };
    pintarLista();
    abrirPopover(celda, caja, { clase: 'popover--menu' });
    entrada.focus();
    entrada.addEventListener('input', pintarLista);
    const botones = () => [...lista.querySelectorAll('button')];
    entrada.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') { ev.preventDefault(); lista.querySelector('[data-opcion-valor]')?.click(); }
      if (ev.key === 'ArrowDown') { ev.preventDefault(); botones()[0]?.focus(); }
    });
    lista.addEventListener('keydown', (ev) => {
      const b = botones();
      const i = b.indexOf(document.activeElement);
      if (ev.key === 'ArrowDown') { ev.preventDefault(); b[(i + 1) % b.length]?.focus(); }
      if (ev.key === 'ArrowUp') { ev.preventDefault(); if (i <= 0) entrada.focus(); else b[i - 1].focus(); }
    });
    lista.addEventListener('mousemove', (ev) => { const b = ev.target.closest('button'); if (b && document.activeElement !== entrada) b.focus({ preventScroll: true }); });
    lista.addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-opcion-valor]');
      if (!b) return;
      cerrarPopover();
      this.guardarCelda(filaId, col.id, b.dataset.opcionValor);
    });
  },

  tiposDePropiedad(alElegir, actual = null) {
    return Object.entries(this.datos.tipos).map(([tipo, texto]) => ({
      icono: TIPO_ICONO[tipo], texto, marcado: actual ? tipo === actual : undefined, accion: () => alElegir(tipo, texto),
    }));
  },

  async agregarColumna(tipo, texto, posicion = null) {
    try {
      const r = await this.accion({ accion: 'columna_agregar', tipo, nombre: texto, ...(posicion !== null ? { posicion } : {}) });
      this.datos.columnas.splice(r.posicion ?? this.datos.columnas.length, 0, r.columna);
      this.pintar();
      marcarEdicionAhora();
      const boton = this.raiz.querySelector(`th[data-col="${r.columna.id}"] [data-col-menu]`);
      boton?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      if (boton) this.menuColumna(boton, r.columna.id, true);
    } catch (e) { mostrarNotificacion('error', e.message); }
  },

  menuNuevaColumna(boton) {
    abrirMenu(boton, [{ titulo: 'Tipo de propiedad' }, ...this.tiposDePropiedad((tipo, texto) => this.agregarColumna(tipo, texto))], { alinear: 'derecha', clase: 'menu-bdp' });
  },

  async moverColumna(colId, desplazamiento) {
    const i = this.datos.columnas.findIndex((c) => c.id === colId);
    const posicion = i + desplazamiento;
    if (i < 1 || posicion < 1 || posicion >= this.datos.columnas.length) return;
    try {
      const r = await this.accion({ accion: 'columna_mover', columna: colId, posicion });
      this.datos.columnas = r.columnas;
      this.pintar();
      marcarEdicionAhora();
    } catch (e) { mostrarNotificacion('error', e.message); }
  },

  ordenar(colId, dir) {
    this.vista.orden = dir ? { col: colId, dir } : null;
    this.guardarVista();
    this.pintar();
  },

  menuColumna(ancla, colId, renombrarYa = false) {
    const col = this.columna(colId);
    if (!col) return;
    const indice = this.datos.columnas.indexOf(col);
    const primera = indice === 0;
    const ed = this.editable;
    const v = this.vista;
    const ordenada = v.orden?.col === colId ? v.orden.dir : null;
    const filtro = v.filtros.findIndex((f) => f.col === colId);
    const th = this.raiz.querySelector(`th[data-col="${colId}"]`);
    th?.classList.add('con-menu');
    const renombrar = async (nombre) => {
      try {
        const r = await this.accion({ accion: 'columna_cambiar', columna: colId, nombre });
        Object.assign(col, r.columna);
        this.pintar();
        marcarEdicionAhora();
      } catch (e) { mostrarNotificacion('error', e.message); }
    };
    const cambiarTipo = async (tipo) => {
      if (tipo === col.tipo) return;
      try {
        const r = await this.accion({ accion: 'columna_cambiar', columna: colId, tipo });
        Object.assign(col, r.columna);
        this.limpiarFiltrosDe(colId);
        this.pintar();
        marcarEdicionAhora();
      } catch (e) { mostrarNotificacion('error', e.message); }
    };
    abrirMenu(ancla, [
      ed ? { campo: true, valor: col.nombre, maximo: 60, etiqueta: 'Nombre de la propiedad', placeholder: 'Nombre de la propiedad', alGuardar: renombrar } : { titulo: col.nombre },
      ed && !primera && { icono: TIPO_ICONO[col.tipo], texto: 'Tipo', detalle: this.datos.tipos[col.tipo], submenu: () => [{ titulo: 'Tipo de propiedad' }, ...this.tiposDePropiedad(cambiarTipo, col.tipo)] },
      ed && !primera && 'separador',
      { icono: 'orden_asc', texto: 'Orden ascendente', marcado: ordenada === 'asc' ? true : undefined, accion: () => this.ordenar(colId, 'asc') },
      { icono: 'orden_desc', texto: 'Orden descendente', marcado: ordenada === 'desc' ? true : undefined, accion: () => this.ordenar(colId, 'desc') },
      ordenada && { icono: 'cerrar', texto: 'Quitar orden', accion: () => this.ordenar(null) },
      { icono: 'filtro', texto: filtro >= 0 ? 'Editar filtro' : 'Filtrar', accion: () => this.filtrarColumna(colId, ancla) },
      'separador',
      { icono: 'fijar', texto: v.congelada === colId ? 'Dejar de inmovilizar' : 'Inmovilizar hasta aquí', accion: () => { v.congelada = v.congelada === colId ? null : colId; this.guardarVista(); this.pintar(); } },
      !primera && { icono: 'ocultar', texto: 'Ocultar en la vista', accion: () => { v.ocultas.push(colId); this.guardarVista(); this.pintar(); } },
      ed && !primera && { icono: 'izquierda', texto: 'Mover a la izquierda', deshabilitado: indice <= 1, accion: () => this.moverColumna(colId, -1) },
      ed && !primera && { icono: 'derecha', texto: 'Mover a la derecha', deshabilitado: indice >= this.datos.columnas.length - 1, accion: () => this.moverColumna(colId, 1) },
      ed && !primera && { icono: 'columna_izq', texto: 'Insertar a la izquierda', submenu: () => [{ titulo: 'Tipo de propiedad' }, ...this.tiposDePropiedad((t, texto) => this.agregarColumna(t, texto, indice))] },
      ed && { icono: 'columna_der', texto: 'Insertar a la derecha', submenu: () => [{ titulo: 'Tipo de propiedad' }, ...this.tiposDePropiedad((t, texto) => this.agregarColumna(t, texto, indice + 1))] },
      ed && !primera && 'separador',
      ed && !primera && { icono: 'papelera', texto: 'Eliminar propiedad', peligro: true, accion: () => this.eliminarColumna(colId) },
      primera && ed && { nota: 'La primera propiedad identifica cada fila: no se puede eliminar ni cambiar de tipo.' },
    ], { clase: 'menu-bdp', seleccionar: renombrarYa, alCerrar: () => th?.classList.remove('con-menu') });
  },

  limpiarFiltrosDe(colId) {
    this.vista.filtros = this.vista.filtros.filter((f) => f.col !== colId);
    if (this.vista.orden?.col === colId && this.columna(colId)?.tipo === 'casilla') this.vista.orden = null;
    this.guardarVista();
  },

  async eliminarColumna(colId) {
    const col = this.columna(colId);
    const ok = await confirmar(this.raiz.querySelector(`th[data-col="${colId}"]`) || this.raiz, {
      titulo: `¿Eliminar la propiedad «${col.nombre}»?`, texto: 'Se borrarán sus valores en todas las filas. Esta acción no se puede deshacer.', boton: 'Eliminar',
    });
    if (!ok) return;
    try {
      await this.accion({ accion: 'columna_eliminar', columna: colId });
      this.datos.columnas = this.datos.columnas.filter((c) => c.id !== colId);
      this.guardarVista();
      this.pintar();
      marcarEdicionAhora();
    } catch (e) { mostrarNotificacion('error', e.message); }
  },

  /** Crea (o abre) el filtro de una columna y muestra su editor. */
  filtrarColumna(colId, ancla) {
    let i = this.vista.filtros.findIndex((f) => f.col === colId);
    if (i < 0) {
      const col = this.columna(colId);
      this.vista.filtros.push({ col: colId, op: (CONDICIONES[col.tipo] || CONDICIONES.texto)[0][0], valor: '' });
      i = this.vista.filtros.length - 1;
      this.guardarVista();
      this.pintar();
    }
    this.menuFiltro(this.raiz.querySelector(`[data-chip-filtro="${i}"]`) || this.anclaColumna(colId, ancla), i);
  },

  menuFiltro(ancla, i) {
    const f = this.vista.filtros[i];
    if (!f) return;
    const col = this.columna(f.col);
    const condiciones = CONDICIONES[col.tipo] || CONDICIONES.texto;
    let espera = null;
    const aplicar = () => { this.guardarVista(); this.pintar(); };
    abrirMenu(ancla, () => {
      const actual = condiciones.find(([k]) => k === f.op) || condiciones[0];
      const conValor = actual[2];
      return [
        { titulo: `Filtrar por «${col.nombre}»` },
        conValor && col.tipo !== 'seleccion' && {
          campo: true, valor: f.valor ?? '', tipo: col.tipo === 'numero' ? 'number' : col.tipo === 'fecha' ? 'date' : 'text',
          placeholder: 'Escribe un valor…', etiqueta: 'Valor del filtro', maximo: 100,
          alCambiar: (valor) => { f.valor = valor; clearTimeout(espera); espera = setTimeout(aplicar, 180); },
        },
        conValor && col.tipo === 'seleccion' && { titulo: 'Opción' },
        ...(conValor && col.tipo === 'seleccion' ? (col.opciones || []).map((o) => ({
          texto: o.nombre, marcado: f.valor === o.nombre, mantener: true, accion: () => { f.valor = o.nombre; aplicar(); },
        })) : []),
        conValor && col.tipo === 'seleccion' && !(col.opciones || []).length && { nota: 'Esta propiedad aún no tiene opciones.' },
        'separador',
        { titulo: 'Condición' },
        ...condiciones.map(([k, texto]) => ({ texto, marcado: f.op === k, mantener: true, accion: () => { f.op = k; aplicar(); } })),
        'separador',
        { icono: 'papelera', texto: 'Quitar filtro', peligro: true, accion: () => { this.vista.filtros.splice(i, 1); aplicar(); } },
      ];
    }, { clase: 'menu-bdp menu-filtro', alCerrar: () => { clearTimeout(espera); if (this.vista.filtros[i] === f) aplicar(); } });
  },

  menuOrden(ancla) {
    const o = this.vista.orden;
    const col = o && this.columna(o.col);
    if (!col) return;
    abrirMenu(ancla, [
      { titulo: `Ordenado por «${col.nombre}»` },
      { icono: 'orden_asc', texto: 'Ascendente', marcado: o.dir === 'asc', accion: () => this.ordenar(o.col, 'asc') },
      { icono: 'orden_desc', texto: 'Descendente', marcado: o.dir === 'desc', accion: () => this.ordenar(o.col, 'desc') },
      'separador',
      { icono: 'cerrar', texto: 'Quitar orden', accion: () => this.ordenar(null) },
    ], { clase: 'menu-bdp' });
  },

  menuOcultas(ancla) {
    abrirMenu(ancla, () => {
      const ocultas = this.vista.ocultas.map((id) => this.columna(id)).filter(Boolean);
      return [
        { titulo: 'Propiedades ocultas' },
        ...ocultas.map((c) => ({ icono: 'ojo', texto: `Mostrar «${c.nombre}»`, mantener: ocultas.length > 1, accion: () => {
          this.vista.ocultas = this.vista.ocultas.filter((x) => x !== c.id);
          this.guardarVista();
          this.pintar();
        } })),
        ocultas.length > 1 && 'separador',
        ocultas.length > 1 && { icono: 'ojo', texto: 'Mostrar todas', accion: () => { this.vista.ocultas = []; this.guardarVista(); this.pintar(); } },
      ];
    }, { clase: 'menu-bdp' });
  },

  menuFila(ancla, filaId, opciones = {}, colId = null) {
    const fila = this.fila(filaId);
    if (!fila) return;
    const primera = this.datos.columnas[0];
    const tr = this.raiz.querySelector(`tr[data-fila="${filaId}"]`);
    tr?.classList.add('con-menu');
    const ed = this.editable;
    const col = colId && this.columna(colId);
    const valorCelda = col ? this.formato(col, fila.valores[col.id]) : '';
    const comoTexto = () => this.datos.columnas.map((c) => this.formato(c, fila.valores[c.id])).join('\t');
    abrirMenu(ancla, [
      { titulo: this.formato(primera, fila.valores[primera.id]) || 'Fila sin nombre' },
      ed && { icono: 'insertar_arriba', texto: 'Insertar arriba', accion: () => this.nuevaFila({ antes_de: filaId }) },
      ed && { icono: 'insertar_abajo', texto: 'Insertar abajo', accion: () => this.nuevaFila({ despues_de: filaId }) },
      ed && { icono: 'duplicar', texto: 'Duplicar', accion: () => this.nuevaFila({ duplicar: filaId }) },
      'separador',
      valorCelda && { icono: 'copiar', texto: `Copiar «${col.nombre}»`, accion: () => copiarTexto(valorCelda, 'Valor copiado.') },
      { icono: 'copiar', texto: 'Copiar fila', detalle: 'separada por tabuladores', accion: () => copiarTexto(comoTexto(), 'Fila copiada. Puedes pegarla en una hoja de cálculo.') },
      ed && 'separador',
      ed && { icono: 'papelera', texto: 'Eliminar fila', peligro: true, accion: async () => {
        const ok = await confirmar(this.raiz.querySelector(`tr[data-fila="${filaId}"] td`) || this.raiz, {
          titulo: '¿Eliminar esta fila?', texto: 'Se borrarán todos sus valores. Esta acción no se puede deshacer.', boton: 'Eliminar', alinear: opciones.alinear,
        });
        if (ok) this.eliminarFila(filaId);
      } },
    ], { ...opciones, clase: 'menu-bdp', alCerrar: () => tr?.classList.remove('con-menu') });
  },
};

/* ---------- Compartir (como la referencia: invitar, personas con acceso, acceso general, copiar enlace) ---------- */
async function abrirCompartir(ancla) {
  let datos;
  try { datos = await api(`paginas/paginas.php?miembros=${PAGINA_ID}`); } catch (e) { mostrarNotificacion('error', e.message); return; }
  const caja = document.createElement('div');
  caja.className = 'compartir';
  const pintar = () => {
    const general = datos.compartida;
    caja.innerHTML = `
      <div class="compartir__pestanas"><span class="activa">Compartir</span></div>
      ${datos.puede_compartir ? `
        <form class="compartir__invitar" data-invitar>
          <input type="text" placeholder="Correo electrónico, separados por comas" aria-label="Correos a invitar" autocomplete="off" spellcheck="false">
          <button type="submit" class="boton boton--primario">Invitar</button>
        </form>` : ''}
      <ul class="compartir__miembros">
        ${datos.miembros.map((m) => `
          <li>
            ${avatarHtml(m.id, m.nombre, 'avatar--grande', m.foto)}
            <span class="compartir__persona"><b>${escaparHtml(m.nombre)}${m.id === datos.yo ? ' (Tú)' : ''}</b><small>${escaparHtml(m.correo)}</small></span>
            ${datos.puede_compartir && !m.propietario
              ? `<button type="button" class="compartir__nivel" data-nivel-de="${m.id}">${escaparHtml(NIVELES[m.nivel])}${ico('flecha_abajo')}</button>`
              : `<span class="compartir__nivel compartir__nivel--fijo">${escaparHtml(NIVELES[m.nivel])}</span>`}
          </li>`).join('')}
      </ul>
      <div class="compartir__titulo">Acceso general</div>
      <div class="compartir__general">
        <span class="compartir__general-icono">${ico(general ? 'usuarios' : 'candado')}</span>
        <span class="compartir__general-texto">
          ${datos.puede_compartir
            ? `<button type="button" data-acceso-general>${general ? 'Todo el equipo del establecimiento' : 'Solo las personas invitadas'}${ico('flecha_abajo')}</button>`
            : `<b>${general ? 'Todo el equipo del establecimiento' : 'Solo las personas invitadas'}</b>`}
          <small>${general ? 'Cualquier persona con cuenta activa puede editar.' : 'Solo las personas de la lista pueden abrirla.'}</small>
        </span>
      </div>
      <div class="compartir__pie">
        <button type="button" class="compartir__copiar" data-copiar>${ico('enlace')} Copiar enlace</button>
      </div>`;
  };
  const NIVELES = { completo: 'Acceso completo', editar: 'Puede editar', ver: 'Puede ver' };
  pintar();
  abrirPopover(ancla, caja, { clase: 'popover--compartir', alinear: 'derecha' });
  caja.querySelector('[data-invitar] input')?.focus();

  const cambio = async () => {
    const r = await Arbol.recargar();
    if (r) actualizarPrivacidad();
  };
  caja.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const entrada = caja.querySelector('[data-invitar] input');
    if (!entrada.value.trim()) { entrada.focus(); return; }
    try {
      const r = await api('paginas/paginas.php', { accion: 'invitar', id: PAGINA_ID, correos: entrada.value, nivel: 'editar' });
      datos.miembros = r.miembros;
      pintar();
      if (r.aviso) mostrarNotificacion('error', r.aviso);
      cambio();
    } catch (e) { mostrarNotificacion('error', e.message); }
  });
  caja.addEventListener('click', async (ev) => {
    if (ev.target.closest('[data-copiar]')) { copiarEnlace(); return; }
    const nivelBoton = ev.target.closest('[data-nivel-de]');
    if (nivelBoton) {
      const id = Number(nivelBoton.dataset.nivelDe);
      const miembro = datos.miembros.find((m) => m.id === id);
      const fijar = async (nivel) => {
        try {
          const r = await api('paginas/paginas.php', { accion: 'permiso', id: PAGINA_ID, usuario_id: id, nivel });
          datos.miembros = r.miembros;
          cambio();
        } catch (e) { mostrarNotificacion('error', e.message); }
        abrirCompartir(ancla);
      };
      abrirMenu(nivelBoton, [
        ...Object.entries(NIVELES).map(([n, t]) => ({ texto: t, marcado: miembro.nivel === n, accion: () => fijar(n) })),
        'separador',
        { texto: 'Quitar acceso', peligro: true, accion: () => fijar('quitar') },
      ], { alinear: 'derecha' });
      return;
    }
    const general = ev.target.closest('[data-acceso-general]');
    if (general) {
      const fijar = async (compartida) => {
        try {
          await api('paginas/paginas.php', { accion: 'acceso_general', id: PAGINA_ID, compartida });
          cambio();
        } catch (e) { mostrarNotificacion('error', e.message); }
        abrirCompartir(ancla);
      };
      abrirMenu(general, [
        { icono: 'candado', texto: 'Solo las personas invitadas', marcado: !datos.compartida, accion: () => fijar(false) },
        { icono: 'usuarios', texto: 'Todo el equipo del establecimiento', marcado: datos.compartida, accion: () => fijar(true) },
      ]);
    }
  });
}

document.addEventListener('click', (ev) => {
  const b = ev.target.closest('[data-compartir-abrir]');
  if (!b || !PAGINA_ID) return;
  if (popoverActual?.ancla === b) { cerrarPopover(); return; }
  abrirCompartir(b);
});

/* ---------- Actividad: tarjeta al pasar el ratón y panel con clic ---------- */
const Actividad = {
  boton: null,
  panel: null,
  datos: null,
  pestana: 'notificaciones',
  temporizador: null,

  iniciar() {
    this.boton = document.querySelector('[data-actividad-abrir]');
    this.panel = document.querySelector('[data-panel-actividad]');
    if (!this.boton || !PAGINA_ID) return;

    // Al pasar el ratón: tarjeta "Actividad" (última edición y creación)
    this.boton.addEventListener('mouseenter', () => {
      clearTimeout(this.temporizador);
      if (!this.panel.hidden || popoverActual) return;
      this.temporizador = setTimeout(() => this.tarjeta(), 280);
    });
    this.boton.addEventListener('mouseleave', () => {
      clearTimeout(this.temporizador);
      this.temporizador = setTimeout(() => { if (popoverActual?.el.classList.contains('popover--actividad') && !popoverActual.el.matches(':hover')) cerrarPopover(); }, 220);
    });
    // Con clic: panel lateral con Notificaciones y Estadísticas
    this.boton.addEventListener('click', () => {
      clearTimeout(this.temporizador);
      cerrarPopover();
      this.alternarPanel();
    });
    this.panel.querySelector('[data-panel-cerrar]').addEventListener('click', () => this.alternarPanel(false));
    this.panel.querySelectorAll('[data-panel-pestana]').forEach((b) => b.addEventListener('click', () => {
      this.pestana = b.dataset.panelPestana;
      this.panel.querySelectorAll('[data-panel-pestana]').forEach((x) => x.setAttribute('aria-selected', String(x === b)));
      this.pintarPanel();
    }));
  },

  async cargar() {
    this.datos = await api(`paginas/paginas.php?actividad=${PAGINA_ID}`);
    return this.datos;
  },

  async tarjeta() {
    try { await this.cargar(); } catch (e) { return; }
    if (!this.boton.matches(':hover')) return;
    const { resumen } = this.datos;
    const fila = (texto, p) => (p ? `<li>${avatarHtml(p.usuario_id, p.nombre, 'avatar--grande', p.foto)}<span>${texto} <b>${escaparHtml(p.nombre)}</b></span><small>${escaparHtml(haceTexto(p.hace))}</small></li>` : '');
    const el = abrirPopover(this.boton, `
      <div class="actividad-tarjeta">
        <div class="actividad-tarjeta__titulo">Actividad</div>
        <ul>${fila('Editada por', resumen.edicion)}${fila('Creada por', resumen.creacion)}</ul>
      </div>`, { clase: 'popover--actividad', alinear: 'izquierda' });
    el.addEventListener('mouseleave', () => { setTimeout(() => { if (!this.boton.matches(':hover') && !el.matches(':hover')) cerrarPopover(); }, 200); });
  },

  async alternarPanel(abrir = this.panel.hidden) {
    const raiz = document.documentElement;
    if (abrir) {
      document.querySelector('[data-asistente-cerrar]')?.click();
      this.panel.hidden = false;
      raiz.classList.add('panel-actividad-abierto');
      this.boton.setAttribute('aria-expanded', 'true');
      this.panel.querySelector('[data-panel-cuerpo]').innerHTML = '<p class="panel-lateral__vacio">Cargando…</p>';
      try { await this.cargar(); this.pintarPanel(); } catch (e) {
        this.panel.querySelector('[data-panel-cuerpo]').innerHTML = `<p class="panel-lateral__vacio">${escaparHtml(e.message)}</p>`;
      }
    } else {
      this.panel.hidden = true;
      raiz.classList.remove('panel-actividad-abierto');
      this.boton.setAttribute('aria-expanded', 'false');
    }
  },

  pintarPanel() {
    const cuerpo = this.panel.querySelector('[data-panel-cuerpo]');
    if (!this.datos) return;
    const titulo = escaparHtml(document.body.dataset.titulo || '');
    if (this.pestana === 'notificaciones') {
      cuerpo.innerHTML = this.datos.detalle.length
        ? `<ul class="notificaciones-lista">${this.datos.detalle.map((d) => `
            <li>
              ${avatarHtml(d.usuario_id, d.nombre, '', d.foto)}
              <div>
                <p><b>${escaparHtml(d.tu ? 'Tú' : d.nombre)}</b> ${escaparHtml(d.texto)} en <b>${titulo}</b></p>
                <small><time datetime="${escaparHtml(d.fecha)}">${escaparHtml(haceTexto(d.hace))}</time></small>
              </div>
            </li>`).join('')}</ul>`
        : '<p class="panel-lateral__vacio">Aún no hay actividad en esta página.</p>';
      return;
    }
    const e = this.datos.estadisticas;
    const maximo = Math.max(1, ...e.dias.map((d) => d.total));
    const fecha = (dia) => { const [, m, d] = dia.split('-'); return `${Number(d)}/${Number(m)}`; };
    cuerpo.innerHTML = `
      <div class="estadisticas">
        <p class="estadisticas__periodo">Últimos 28 días</p>
        <dl class="estadisticas__cifras">
          <div><dt>Vistas</dt><dd>${e.vistas}</dd></div>
          <div><dt>Visitantes únicos</dt><dd>${e.visitantes}</dd></div>
          <div><dt>Ediciones</dt><dd>${e.ediciones}</dd></div>
          <div><dt>Personas que editaron</dt><dd>${e.editores}</dd></div>
        </dl>
        <p class="estadisticas__subtitulo">Vistas por día</p>
        <div class="estadisticas__barras" role="img" aria-label="Vistas por día en los últimos 28 días">
          ${e.dias.map((d) => `<span title="${fecha(d.dia)}: ${d.total} ${d.total === 1 ? 'vista' : 'vistas'}" style="--alto:${Math.round((d.total / maximo) * 100)}%"${d.total ? '' : ' class="cero"'}></span>`).join('')}
        </div>
        <div class="estadisticas__ejes"><span>${fecha(e.dias[0].dia)}</span><span>Hoy</span></div>
        <p class="estadisticas__nota">Una vista por persona cada 30 minutos, tomada de la bitácora.</p>
      </div>`;
  },
};

/* ---------- Presencia: burbujas de quién está aquí y quién edita ---------- */
let ultimaEdicionLocal = 0;
function marcarEditando() {
  const antes = Date.now() - ultimaEdicionLocal > 12000;
  ultimaEdicionLocal = Date.now();
  if (antes) Presencia.latir();
}

const Presencia = {
  clave: document.body.dataset.presencia,
  caja: null,
  personas: [],
  temporizador: null,

  iniciar() {
    this.caja = document.querySelector('[data-presencia-burbujas]');
    if (!this.caja || !this.clave) return;
    this.latir();
    this.temporizador = setInterval(() => { if (!document.hidden) this.latir(); }, 15000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) this.latir(); });
    // Cualquier escritura en un formulario de la página cuenta como "editando"
    document.querySelector('.contenido__pagina')?.addEventListener('input', (ev) => {
      if (ev.target.closest('form:not(.entrada), [data-editor-bloques], [data-hoja-titulo], [data-bdp]')) marcarEditando();
    });
    window.addEventListener('pagehide', () => {
      try {
        fetch(`${BASE}/api/presencia.php`, {
          method: 'POST', keepalive: true,
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
          body: JSON.stringify({ clave: this.clave, salir: true }),
        });
      } catch (e) { /* al salir no importa */ }
    });
    this.caja.addEventListener('click', () => this.lista());
  },

  async latir() {
    if (!this.caja) return;
    try {
      const r = await api('presencia.php', { clave: this.clave, editando: Date.now() - ultimaEdicionLocal < 12000 });
      this.personas = r.personas;
      this.pintar();
    } catch (e) { /* sin conexión: se reintenta en el siguiente latido */ }
  },

  pintar() {
    const maximo = 4;
    const visibles = this.personas.slice(0, maximo);
    const resto = this.personas.length - visibles.length;
    this.caja.innerHTML = visibles.map((p, i) => `
      <span class="presencia__burbuja${p.editando ? ' editando' : ''}" style="--i:${i}" title="${escaparHtml(p.tu ? `${p.nombre} (tú)` : p.nombre)} · ${p.editando ? 'editando' : 'viendo'}">
        ${avatarHtml(p.id, p.nombre, '', p.foto)}
      </span>`).join('') + (resto > 0 ? `<span class="presencia__mas" title="${resto} ${resto === 1 ? 'persona más' : 'personas más'}"><span class="presencia__puntos" aria-hidden="true">···</span>+${resto}</span>` : '');
    this.caja.setAttribute('aria-label', this.personas.map((p) => `${p.nombre}${p.editando ? ' (editando)' : ''}`).join(', '));
    this.caja.hidden = !this.personas.length;
  },

  lista() {
    if (!this.personas.length) return;
    abrirPopover(this.caja, `
      <div class="presencia-lista">
        <div class="actividad-tarjeta__titulo">En esta ${PAGINA_ID ? 'página' : 'sección'}</div>
        <ul>${this.personas.map((p) => `<li>${avatarHtml(p.id, p.nombre, 'avatar--grande', p.foto)}<span><b>${escaparHtml(p.nombre)}</b>${p.tu ? ' (tú)' : ''}</span><small>${p.editando ? 'Editando' : 'Viendo'}</small></li>`).join('')}</ul>
      </div>`, { clase: 'popover--actividad', alinear: 'derecha' });
  },
};

/* ---------- Inicio ---------- */
document.addEventListener('DOMContentLoaded', () => {
  Arbol.iniciar();
  Hoja.iniciar();
  Editor.iniciar();
  iniciarOpcionesVacia();
  Bdp.iniciar();
  Actividad.iniciar();
  Presencia.iniciar();
  // "Última edición hace…" se mantiene al día sin recargar
  setInterval(() => {
    const el = document.querySelector('[data-ultima-edicion]');
    if (!el?.dataset.fecha) return;
    const min = Math.floor((Date.now() - new Date(el.dataset.fecha)) / 60000);
    el.textContent = min < 1 ? 'ahora mismo' : min < 60 ? `hace ${min} min` : min < 1440 ? `hace ${Math.floor(min / 60)} h` : el.textContent;
  }, 30000);
  // La primera vez, una nota nueva sin título enfoca el título
  if (Hoja.editable && Hoja.titulo && !Hoja.titulo.textContent.trim() && Editor.raiz && Editor.actualizarVacio) {
    const vacia = !document.querySelector('[data-editor-bloques] .bloque__texto:not(:empty)');
    if (vacia) Hoja.titulo.focus();
  }
});

/* ---------- Foto de perfil (clic en el avatar de la barra lateral) ---------- */
document.addEventListener('change', async (ev) => {
  const entrada = ev.target.closest('[data-foto-perfil]');
  if (!entrada?.files[0]) return;
  const datos = new FormData();
  datos.append('foto', entrada.files[0]);
  entrada.value = '';
  try {
    const r = await (await fetch(`${BASE}/api/configuracion/foto_perfil.php`, { method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos })).json();
    if (!r.ok) throw new Error(r.error);
    entrada.closest('label').querySelector('.avatar').innerHTML = `<img src="${escaparHtml(r.url)}" alt="">`;
    document.body.dataset.usuarioFoto = r.url;
    mostrarNotificacion('exito', 'Foto de perfil actualizada.');
    Presencia.latir();
  } catch (e) { mostrarNotificacion('error', e.message || 'No se pudo subir la foto.'); }
});

/* ---------- Portadas: galería, subir imagen o quitar (notas, páginas y Pacientes) ---------- */
async function guardarPortada(cuerpo) {
  const esArchivo = cuerpo instanceof FormData;
  const r = await fetch(`${BASE}/api/portadas.php`, {
    method: 'POST',
    headers: esArchivo ? { 'X-CSRF-Token': TOKEN_CSRF } : { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
    body: esArchivo ? cuerpo : JSON.stringify(cuerpo),
  });
  const datos = await r.json().catch(() => ({ ok: false }));
  if (!datos.ok) throw new Error(datos.error || 'No se pudo cambiar la portada.');
  location.reload();
}

document.addEventListener('click', async (ev) => {
  const quitar = ev.target.closest('[data-portada-quitar]');
  if (quitar) {
    try { await guardarPortada({ destino: quitar.dataset.destino, id: Number(quitar.dataset.id) || undefined, clave: quitar.dataset.clave, portada: null }); }
    catch (e) { mostrarNotificacion('error', e.message); }
    return;
  }
  const boton = ev.target.closest('[data-portada-cambiar]');
  if (!boton) return;
  const destino = { destino: boton.dataset.destino, id: Number(boton.dataset.id) || undefined, clave: boton.dataset.clave };
  let galeria = [];
  try { galeria = (await api('portadas.php')).galeria; } catch (e) { mostrarNotificacion('error', e.message); return; }
  const caja = document.createElement('div');
  caja.className = 'portadas';
  caja.innerHTML = `
    <div class="portadas__cabecera"><span>Galería</span>${boton.dataset.tiene ? '<button type="button" data-quitar-portada>Quitar</button>' : ''}</div>
    <div class="portadas__galeria">${galeria.map((g) => `<button type="button" data-ruta="${escaparHtml(g.ruta)}" style="background-image:url('${escaparHtml(g.url)}')" aria-label="Usar esta imagen"></button>`).join('')}</div>
    <label class="portadas__subir">${ico('agregar')}<span>Subir una imagen</span><small>JPG, PNG o WebP, al menos 600 × 150 px</small><input type="file" accept="image/jpeg,image/png,image/webp" hidden></label>`;
  abrirPopover(boton, caja, { clase: 'popover--portadas', alinear: 'derecha' });
  caja.addEventListener('click', async (e) => {
    const opcion = e.target.closest('[data-ruta]');
    try {
      if (opcion) await guardarPortada({ ...destino, portada: opcion.dataset.ruta });
      if (e.target.closest('[data-quitar-portada]')) await guardarPortada({ ...destino, portada: null });
    } catch (err) { mostrarNotificacion('error', err.message); }
  });
  caja.querySelector('input[type="file"]').addEventListener('change', async (e) => {
    const archivo = e.target.files[0];
    if (!archivo) return;
    const datos = new FormData();
    datos.append('foto', archivo);
    datos.append('destino', destino.destino);
    if (destino.id) datos.append('id', destino.id);
    if (destino.clave) datos.append('clave', destino.clave);
    try { await guardarPortada(datos); } catch (err) { mostrarNotificacion('error', err.message); }
  });
});

/* ---------- Textos editables en su lugar (títulos y subtítulos de secciones; administrador) ---------- */
document.addEventListener('focusout', async (ev) => {
  const el = ev.target.closest?.('[data-texto-editable]');
  if (!el) return;
  const texto = el.textContent.replace(/\s+/g, ' ').trim();
  if (texto === (el.dataset.guardado ?? el.dataset.inicial)) return;
  try {
    const r = await api('configuracion/textos.php', { clave: el.dataset.textoEditable, texto });
    el.dataset.guardado = r.texto;
    if (!r.texto) el.textContent = el.dataset.placeholder;
  } catch (e) { mostrarNotificacion('error', e.message); }
});
document.addEventListener('focusin', (ev) => {
  const el = ev.target.closest?.('[data-texto-editable]');
  if (el && el.dataset.inicial === undefined) el.dataset.inicial = el.textContent.trim();
});
document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Enter' && ev.target.closest?.('[data-texto-editable], [data-editando-campo]')) { ev.preventDefault(); ev.target.blur(); }
});

/* ---------- Personas y permisos: vistas, rol, estado, edición en su lugar, permisos e invitaciones ---------- */
function iniciarPersonas() {
  const raiz = document.querySelector('[data-personas]');
  if (!raiz) return;
  const datos = JSON.parse(document.getElementById('personas-datos').textContent);
  const accion = (cuerpo) => api('configuracion/personas.php', cuerpo);
  const recargar = () => location.reload();

  // Vistas (Personas · Invitaciones · Permisos), recordadas en la sesión
  const vistas = raiz.querySelectorAll('[data-vista]');
  const mostrarVista = (nombre) => {
    vistas.forEach((v) => { v.classList.toggle('activa', v.dataset.vista === nombre); v.setAttribute('aria-selected', String(v.dataset.vista === nombre)); });
    raiz.querySelectorAll('[data-vista-panel]').forEach((p) => { p.hidden = p.dataset.vistaPanel !== nombre; });
    try { sessionStorage.setItem('femeci-personas-vista', nombre); } catch (e) { /* opcional */ }
  };
  vistas.forEach((v) => v.addEventListener('click', () => mostrarVista(v.dataset.vista)));
  try { mostrarVista(sessionStorage.getItem('femeci-personas-vista') || 'personas'); } catch (e) { mostrarVista('personas'); }

  // Buscar en la vista actual
  const buscar = raiz.querySelector('[data-personas-buscar]');
  buscar.addEventListener('input', () => {
    const termino = normalizarTexto(buscar.value.trim());
    raiz.querySelectorAll('tr[data-buscar]').forEach((tr) => { tr.hidden = !!termino && !normalizarTexto(tr.dataset.buscar).includes(termino); });
  });
  buscar.closest('label').addEventListener('click', () => buscar.closest('label').classList.add('abierto'));

  // Invitar: correo(s) y rol, con el estilo del panel Compartir
  raiz.querySelectorAll('[data-personas-invitar]').forEach((boton) => boton.addEventListener('click', () => {
    const caja = document.createElement('div');
    caja.className = 'compartir';
    caja.innerHTML = `
      <div class="compartir__pestanas"><span class="activa">Invitar</span></div>
      <form class="compartir__invitar">
        <input type="text" placeholder="Correo electrónico, separados por comas" aria-label="Correos" autocomplete="off" spellcheck="false">
        <button type="submit" class="boton boton--primario">Invitar</button>
      </form>
      <div class="compartir__titulo">Rol</div>
      <div class="invitar-roles">${Object.entries(datos.roles).map(([clave, nombre], i) => `
        <label><input type="radio" name="rol" value="${clave}"${i === 0 ? ' checked' : ''}><span class="opcion opcion--${datos.colores[clave]}">${escaparHtml(nombre)}</span></label>`).join('')}</div>
      <p class="invitar-nota">Llega un enlace por correo que vence en 7 días.</p>`;
    abrirPopover(boton, caja, { clase: 'popover--compartir', alinear: 'derecha' });
    const entrada = caja.querySelector('input[type="text"]');
    entrada.focus();
    caja.querySelector('form').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!entrada.value.trim()) { entrada.focus(); return; }
      try {
        const r = await accion({ accion: 'invitar', correos: entrada.value, rol: caja.querySelector('input[name="rol"]:checked').value });
        if (r.avisos?.length) mostrarNotificacion('error', r.avisos.join(' '));
        try { sessionStorage.setItem('femeci-personas-vista', 'invitaciones'); } catch (e) { /* opcional */ }
        recargar();
      } catch (e) { mostrarNotificacion('error', e.message); }
    });
  }));

  // Rol: la pastilla abre el menú de roles
  raiz.querySelectorAll('[data-cambiar-rol]').forEach((boton) => boton.addEventListener('click', () => {
    const id = Number(boton.closest('tr').dataset.usuario);
    abrirMenu(boton, Object.entries(datos.roles).map(([clave, nombre]) => ({
      texto: nombre, marcado: boton.dataset.cambiarRol === clave,
      accion: async () => { try { await accion({ accion: 'rol', usuario_id: id, rol: clave }); recargar(); } catch (e) { mostrarNotificacion('error', e.message); } },
    })));
  }));

  // "…" de cada persona: aprobar, rechazar, desactivar o reactivar
  raiz.querySelectorAll('[data-persona-menu]').forEach((boton) => boton.addEventListener('click', () => {
    const tr = boton.closest('tr');
    const id = Number(tr.dataset.usuario);
    const hacer = (nombre) => async () => { try { await accion({ accion: nombre, usuario_id: id }); recargar(); } catch (e) { mostrarNotificacion('error', e.message); } };
    const opciones = {
      pendiente: [{ icono: 'casilla', texto: 'Aprobar cuenta', accion: hacer('aprobar') }, { icono: 'cerrar', texto: 'Rechazar', peligro: true, accion: hacer('desactivar') }],
      activa: [{ icono: 'cerrar', texto: 'Desactivar cuenta', peligro: true, accion: hacer('desactivar') }],
      desactivada: [{ icono: 'reabrir', texto: 'Reactivar cuenta', accion: hacer('reactivar') }],
    }[tr.dataset.estado];
    abrirMenu(boton, opciones, { alinear: 'derecha' });
  }));

  // "…" de cada invitación: reenviar o cancelar
  raiz.querySelectorAll('[data-invitacion-menu]').forEach((boton) => boton.addEventListener('click', () => {
    const id = Number(boton.closest('tr').dataset.invitacion);
    const hacer = (nombre) => async () => { try { await accion({ accion: nombre, id }); recargar(); } catch (e) { mostrarNotificacion('error', e.message); } };
    abrirMenu(boton, [
      { icono: 'reabrir', texto: 'Reenviar con un enlace nuevo', accion: hacer('reenviar') },
      { icono: 'cerrar', texto: 'Cancelar invitación', peligro: true, accion: hacer('cancelar') },
    ], { alinear: 'derecha' });
  }));

  // Nombre y cédula: se editan en su lugar
  raiz.querySelectorAll('[data-editar-campo]').forEach((celda) => celda.addEventListener('click', () => {
    if (celda.dataset.editandoCampo) return;
    const original = celda.textContent.trim();
    celda.dataset.editandoCampo = '1';
    celda.contentEditable = 'plaintext-only';
    celda.classList.add('editando');
    celda.focus();
    const rango = document.createRange();
    rango.selectNodeContents(celda);
    getSelection().removeAllRanges();
    getSelection().addRange(rango);
    celda.addEventListener('blur', async () => {
      celda.contentEditable = 'false';
      celda.classList.remove('editando');
      delete celda.dataset.editandoCampo;
      const valor = celda.textContent.replace(/\s+/g, ' ').trim();
      if (valor === original) return;
      try {
        const r = await accion({ accion: celda.dataset.editarCampo, usuario_id: Number(celda.closest('tr').dataset.usuario), valor });
        celda.textContent = r.valor;
      } catch (e) { celda.textContent = original; mostrarNotificacion('error', e.message); }
    }, { once: true });
  }));

  // Matriz de permisos: cada casilla se guarda al momento
  raiz.querySelectorAll('[data-permiso]').forEach((casilla) => casilla.addEventListener('click', async () => {
    const valor = casilla.getAttribute('aria-checked') !== 'true';
    casilla.setAttribute('aria-checked', String(valor));
    try { await accion({ accion: 'permiso', permiso: casilla.dataset.permiso, rol: casilla.dataset.rol, valor }); }
    catch (e) { casilla.setAttribute('aria-checked', String(!valor)); mostrarNotificacion('error', e.message); }
  }));
}
document.addEventListener('DOMContentLoaded', iniciarPersonas);

/* ---------- Base de datos de pacientes: menú de cada propiedad y panel "Propiedades" ---------- */
(() => {
  const fuente = document.getElementById('propiedades-pacientes');
  if (!fuente) return;
  const datos = JSON.parse(fuente.textContent);
  const guardar = (cuerpo) => api('pacientes/propiedades.php', cuerpo);
  const TIPOS_PROP = { texto: ['Texto', 'aa'], numero: ['Número', 'numero'], fecha: ['Fecha', 'calendario'], seleccion: ['Selección', 'seleccion'], casilla: ['Casilla', 'casilla'] };

  // Menú al hacer clic en un encabezado: renombrar, ordenar, ocultar y (si es agregada) eliminar
  window.menuPropiedadPacientes = (th, ordenar) => {
    const columna = datos.columnas.find((c) => c.clave === th.dataset.clave);
    const caja = document.createElement('div');
    caja.className = 'menu-flotante';
    caja.innerHTML = `
      ${datos.editable ? `<input class="menu-flotante__entrada" value="${escaparHtml(columna?.titulo ?? '')}" maxlength="60" aria-label="Nombre de la propiedad">` : ''}
      <button type="button" data-ordenar="1">${ico('flecha_abajo')}<span>Orden ascendente</span></button>
      <button type="button" data-ordenar="0">${ico('flecha_abajo')}<span>Orden descendente</span></button>
      ${datos.editable && columna && columna.clave !== 'nombre' ? `<div class="menu-flotante__separador"></div><button type="button" data-ocultar>${ico('cerrar')}<span>Ocultar en la vista</span></button>` : ''}
      ${datos.editable && columna?.extra ? `<button type="button" class="peligro" data-eliminar>${ico('papelera')}<span>Eliminar propiedad</span></button>` : ''}`;
    const entrada = caja.querySelector('input');
    const renombrar = async () => {
      if (!entrada || !columna) return;
      const nombre = entrada.value.trim();
      if (!nombre || nombre === columna.titulo) return;
      try {
        await guardar({ accion: 'renombrar', clave: columna.clave, nombre });
        columna.titulo = nombre;
        th.querySelector('button span').textContent = nombre;
      } catch (e) { mostrarNotificacion('error', e.message); }
    };
    abrirPopover(th, caja, { clase: 'popover--menu', alCerrar: renombrar });
    entrada?.focus();
    entrada?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); cerrarPopover(); } });
    caja.addEventListener('click', async (e) => {
      const orden = e.target.closest('[data-ordenar]');
      if (orden) { ordenar(orden.dataset.ordenar === '1'); cerrarPopover(); return; }
      try {
        if (e.target.closest('[data-ocultar]')) { await guardar({ accion: 'ocultar', clave: columna.clave, oculta: true }); location.reload(); }
        if (e.target.closest('[data-eliminar]')) {
          cerrarPopover();
          const ok = await confirmar(th, { titulo: `¿Eliminar la propiedad «${columna.titulo}»?`, texto: 'Deja de mostrarse en la tabla. Los cambios anteriores siguen en la bitácora.', boton: 'Eliminar' });
          if (ok) { await guardar({ accion: 'eliminar', clave: columna.clave }); location.reload(); }
        }
      } catch (err) { mostrarNotificacion('error', err.message); }
    });
  };

  // Panel "Propiedades": mostrar u ocultar columnas y agregar propiedades nuevas
  document.querySelector('[data-propiedades]')?.addEventListener('click', (ev) => {
    const boton = ev.currentTarget;
    const caja = document.createElement('div');
    caja.className = 'propiedades';
    caja.innerHTML = `
      <div class="menu-flotante__titulo">Propiedades de la tabla</div>
      <div class="propiedades__lista">${datos.columnas.map((c) => `
        <label class="propiedades__fila"><span>${escaparHtml(c.titulo)}</span>
          <input type="checkbox" data-mostrar="${escaparHtml(c.clave)}"${c.oculta ? '' : ' checked'}${c.clave === 'nombre' ? ' disabled' : ''}>
          <i class="interruptor" aria-hidden="true"></i></label>`).join('')}</div>
      <div class="menu-flotante__separador"></div>
      <form class="propiedades__nueva">
        <div class="menu-flotante__titulo">Nueva propiedad</div>
        <input class="menu-flotante__entrada" name="nombre" placeholder="Nombre de la propiedad" maxlength="60" required>
        <div class="propiedades__tipos">${Object.entries(TIPOS_PROP).map(([t, [texto, icono]], i) => `
          <label><input type="radio" name="tipo" value="${t}"${i === 0 ? ' checked' : ''}><span>${ico(icono)}${texto}</span></label>`).join('')}</div>
        <input class="menu-flotante__entrada" name="opciones" placeholder="Opciones separadas por comas" hidden>
        <button type="submit" class="boton boton--primario propiedades__agregar">Agregar propiedad</button>
      </form>`;
    abrirPopover(boton, caja, { clase: 'popover--menu popover--propiedades', alinear: 'derecha' });
    let cambio = false;
    caja.addEventListener('change', async (e) => {
      const casilla = e.target.closest('[data-mostrar]');
      if (casilla) {
        try { await guardar({ accion: 'ocultar', clave: casilla.dataset.mostrar, oculta: !casilla.checked }); cambio = true; }
        catch (err) { casilla.checked = !casilla.checked; mostrarNotificacion('error', err.message); }
      }
      if (e.target.name === 'tipo') caja.querySelector('[name="opciones"]').hidden = e.target.value !== 'seleccion';
    });
    caja.querySelector('form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.currentTarget;
      try {
        await guardar({ accion: 'agregar', nombre: form.nombre.value, tipo: form.tipo.value, opciones: form.opciones.value.split(',') });
        location.reload();
      } catch (err) { mostrarNotificacion('error', err.message); }
    });
    // Al cerrar el panel, la tabla se vuelve a dibujar con las columnas elegidas
    const vigilar = setInterval(() => { if (!caja.isConnected) { clearInterval(vigilar); if (cambio) location.reload(); } }, 300);
  });
})();
// Desde «Editar formato» del registro de pacientes: abre el panel de propiedades
if (location.hash === '#propiedades') document.querySelector('[data-propiedades]')?.click();

/* ---------- Alto de las portadas: arrastrar el borde inferior (o flechas) y volver al predeterminado ---------- */
(() => {
  const destinoDe = (el) => ({ destino: el.dataset.destino, id: Number(el.dataset.id) || undefined, clave: el.dataset.clave });
  const guardarAlto = (el, alto) => api('portadas.php', { ...destinoDe(el), alto });

  document.addEventListener('pointerdown', (ev) => {
    const tirador = ev.target.closest('[data-portada-tirador]');
    if (!tirador || ev.button !== 0) return;
    ev.preventDefault();
    const portada = tirador.parentElement;
    const inicioY = ev.clientY;
    const inicioAlto = portada.getBoundingClientRect().height;
    tirador.setPointerCapture(ev.pointerId);
    portada.classList.add('ajustando');
    const mover = (e) => {
      const alto = Math.round(Math.max(120, Math.min(520, inicioAlto + e.clientY - inicioY)));
      portada.style.setProperty('--alto', `${alto}px`);
    };
    const soltar = async () => {
      tirador.removeEventListener('pointermove', mover);
      portada.classList.remove('ajustando');
      const alto = Math.round(portada.getBoundingClientRect().height);
      if (Math.abs(alto - inicioAlto) < 2) return;
      try { await guardarAlto(tirador, alto); } catch (e) { mostrarNotificacion('error', e.message); }
    };
    tirador.addEventListener('pointermove', mover);
    tirador.addEventListener('pointerup', soltar, { once: true });
  });

  // Con el teclado: flechas arriba/abajo cambian el alto de 20 en 20 px
  document.addEventListener('keydown', async (ev) => {
    const tirador = ev.target.closest?.('[data-portada-tirador]');
    if (!tirador || !['ArrowUp', 'ArrowDown'].includes(ev.key)) return;
    ev.preventDefault();
    const portada = tirador.parentElement;
    const alto = Math.round(Math.max(120, Math.min(520, portada.getBoundingClientRect().height + (ev.key === 'ArrowDown' ? 20 : -20))));
    portada.style.setProperty('--alto', `${alto}px`);
    clearTimeout(tirador._guardar);
    tirador._guardar = setTimeout(() => guardarAlto(tirador, alto).catch((e) => mostrarNotificacion('error', e.message)), 400);
  });

  document.addEventListener('click', async (ev) => {
    const original = ev.target.closest('[data-portada-alto-original]');
    if (!original) return;
    try {
      await guardarAlto(original, null);
      original.closest('.portada, .banner').style.removeProperty('--alto');
      original.remove();
    } catch (e) { mostrarNotificacion('error', e.message); }
  });
})();

/* ---------- Listas desplegables con el estilo de la app: ahora en assets/js/modulos/controles.js
   (mejorarSelect se aplica solo a todos los <select>, también a los que se agregan después). ---------- */

/* ---------- Recordatorios de citas: ahora los revisa cada minuto assets/js/modulos/notificaciones.js
   (api/notificaciones.php?conteo=1 los envía y los guarda en la bandeja de la campana) ---------- */
