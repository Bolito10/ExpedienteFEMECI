/* ==========================================================================
   Expedientes Clínicos — controles propios en lugar de los del navegador
   - Listas desplegables (<select>): botón con la lista de la app (antes mejorarSelect de espacio.js).
   - Fechas (input date): texto con máscara dd/mm/aaaa y calendario en un popover.
   - Horas (input time): texto HH:MM con lista de horarios.
   - Archivos (input file): botón propio, teclado y soltar archivos.
   - Áreas de texto: crecen con el contenido (sin el agarre del navegador).
   - Avisos de title: globo con el estilo de la app.
   El control original se conserva (oculto) para que el formulario lo envíe y valide igual;
   se le disparan los eventos input/change como si el usuario lo hubiera cambiado.
   Se aplica a todo lo que exista y a lo que se agregue después (MutationObserver).
   Para dejar un control nativo: atributo data-nativo en él o en un contenedor.
   No depende de espacio.js (las pantallas de acceso no lo cargan).
   ========================================================================== */
(() => {
  'use strict';

  const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  const DIAS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
  const capital = (t) => t.charAt(0).toUpperCase() + t.slice(1);
  /* Mismos trazos que app/vistas/plantilla/iconos.php */
  const TRAZOS = {
    calendario: '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>',
    reloj: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    atras: '<path d="m15 18-6-6 6-6"/>',
    adelante: '<path d="m9 18 6-6-6-6"/>',
    flecha_abajo: '<path d="m6 9 6 6 6-6"/>',
    marca: '<path d="m5 12 5 5 9-10"/>',
    subir: '<path d="M12 15V4M7 9l5-5 5 5M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"/>',
  };
  const svg = (nombre, clase = 'icono') => `<svg class="${clase}" viewBox="0 0 24 24" aria-hidden="true">${TRAZOS[nombre]}</svg>`;
  const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const normalizar = (t) => String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  let contador = 0;
  const nuevoId = (prefijo) => `ctl-${prefijo}-${++contador}`;
  const NATIVO = {
    valorInput: Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value'),
    valorSelect: Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value'),
    indiceSelect: Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex'),
  };
  const leerNativo = (el) => NATIVO.valorInput.get.call(el);
  const escribirNativo = (el, v) => NATIVO.valorInput.set.call(el, v);
  const disparar = (el, ...tipos) => tipos.forEach((t) => el.dispatchEvent(new Event(t, { bubbles: true })));
  const marcar = (el, mensaje) => { if (typeof window.marcarError === 'function') window.marcarError(el, mensaje); };
  const esNativo = (el) => el.closest('[data-nativo]') !== null;
  const pantallaTactil = window.matchMedia('(pointer: coarse)');

  /** Etiqueta visible asociada a un control (label[for] o label que lo envuelve). */
  function etiquetaDe(el) {
    if (el.id) {
      const l = document.querySelector(`label[for="${CSS.escape(el.id)}"]`);
      if (l) return l;
    }
    return el.closest('label');
  }
  /** Copia el nombre accesible del original al control visible. */
  function copiarEtiqueta(original, visible) {
    const etiqueta = etiquetaDe(original);
    if (etiqueta && !etiqueta.contains(visible)) {
      if (!etiqueta.id) etiqueta.id = nuevoId('etiqueta');
      visible.setAttribute('aria-labelledby', etiqueta.id);
    } else if (original.getAttribute('aria-label')) {
      visible.setAttribute('aria-label', original.getAttribute('aria-label'));
    } else if (original.getAttribute('aria-labelledby')) {
      visible.setAttribute('aria-labelledby', original.getAttribute('aria-labelledby'));
    }
    if (original.getAttribute('aria-describedby')) visible.setAttribute('aria-describedby', original.getAttribute('aria-describedby'));
  }
  /** Oculta el control original sin quitarlo del formulario (misma clase que ya usa registros_clinicos.js). */
  function ocultarOriginal(original, visible) {
    original.classList.add('select-oculto');
    original.tabIndex = -1;
    original.setAttribute('aria-hidden', 'true');
    // Al marcar un error, el código enfoca el original: el foco va al control visible
    original.addEventListener('focus', () => visible.focus({ preventScroll: true }));
  }

  /* ======================================================================
     Capa flotante: un popover a la vez, con el aspecto de .popover/.menu-flotante.
     Si el control vive dentro de un popover de espacio.js, se registra como "extra"
     de ese popover para que un clic en el calendario no lo cierre.
     ====================================================================== */
  const capa = {
    actual: null,
    abrir(ancla, el, opciones = {}) {
      this.cerrar(false);
      el.classList.add('popover', 'popover--menu', 'control-pop');
      document.body.appendChild(el);
      let padre = null;
      try {
        // eslint-disable-next-line no-undef
        if (typeof popoverActual !== 'undefined' && popoverActual && popoverActual.el.contains(ancla)) padre = popoverActual;
      } catch (e) { /* sin espacio.js */ }
      if (padre) padre.extras.push(el);
      // Los clics dentro no le quitan el foco al campo (excepto en cuadros de búsqueda)
      el.addEventListener('mousedown', (ev) => { if (!ev.target.closest('input')) ev.preventDefault(); });
      this.actual = { el, ancla, padre, dentro: opciones.dentro || [ancla], alCerrar: opciones.alCerrar, teclado: opciones.teclado, controladores: opciones.controladores || [] };
      this.colocar();
      return el;
    },
    colocar() {
      const a = this.actual;
      if (!a) return;
      const r = a.ancla.getBoundingClientRect();
      const el = a.el;
      const ancho = el.offsetWidth;
      const alto = el.offsetHeight;
      const x = Math.max(8, Math.min(r.left, window.innerWidth - ancho - 8));
      let y = r.bottom + 6;
      let arriba = false;
      if (y + alto > window.innerHeight - 8 && r.top - alto - 6 >= 8) { y = r.top - alto - 6; arriba = true; }
      el.style.left = `${Math.round(x)}px`;
      el.style.top = `${Math.round(Math.max(8, Math.min(y, window.innerHeight - alto - 8)))}px`;
      el.style.transformOrigin = `left ${arriba ? 'bottom' : 'top'}`;
    },
    cerrar(conAnimacion = true) {
      const a = this.actual;
      if (!a) return;
      this.actual = null;
      if (a.padre) a.padre.extras = a.padre.extras.filter((x) => x !== a.el);
      if (conAnimacion && typeof window.quitarConAnimacion === 'function' && a.el.isConnected) window.quitarConAnimacion(a.el, 'cerrando');
      else a.el.remove();
      a.alCerrar?.();
    },
    abiertaPara(controlador) {
      const a = this.actual;
      if (!a) return false;
      if (!a.el.isConnected || !a.ancla.isConnected) { this.cerrar(false); return false; }
      return a.controladores.includes(controlador);
    },
  };
  document.addEventListener('pointerdown', (ev) => {
    const a = capa.actual;
    if (!a) return;
    if (a.el.contains(ev.target) || a.dentro.some((x) => x.contains(ev.target))) return;
    capa.cerrar();
  }, true);
  window.addEventListener('resize', () => { if (!pantallaTactil.matches) capa.cerrar(false); else capa.colocar(); });
  window.addEventListener('scroll', (ev) => { if (capa.actual && !capa.actual.el.contains(ev.target)) capa.colocar(); }, true);

  /* Teclado: se escucha en window (fase de captura) para resolver antes que los atajos de la página.
     Cada control guarda su manejador en el elemento que tiene el foco (el._teclado). */
  window.addEventListener('keydown', (ev) => {
    const manejador = ev.target && ev.target._teclado;
    if (!manejador || ev.isComposing) return;
    if (manejador(ev)) { ev.preventDefault(); ev.stopImmediatePropagation(); }
  }, true);

  /* ======================================================================
     Lista de opciones en la capa (selects y horas). El foco se queda en el
     controlador (botón, campo o búsqueda) y la opción activa se indica con
     aria-activedescendant.
     ====================================================================== */
  function abrirLista({ ancla, controlador, dentro, opciones, valor, buscar, vacia, alElegir, alCerrar, clase, ancho, dejarEscape, antesDeEnter, alActivar }) {
    const idLista = nuevoId('lista');
    const caja = document.createElement('div');
    caja.className = `menu-flotante selector__lista ${clase || ''}`;
    let html = buscar ? '<input class="menu-flotante__entrada" placeholder="Buscar…" aria-label="Buscar opción" autocomplete="off" spellcheck="false">' : '';
    html += `<div class="selector__opciones" role="listbox" id="${idLista}">`;
    let grupo = null;
    opciones.forEach((o, i) => {
      if (o.grupo !== undefined && o.grupo !== grupo) { grupo = o.grupo; html += `<div class="menu-flotante__titulo" role="presentation">${esc(grupo)}</div>`; }
      const sel = o.valor === valor;
      html += `<button type="button" role="option" tabindex="-1" id="${idLista}-${i}" data-indice="${i}" aria-selected="${sel}"${o.deshabilitada ? ' disabled aria-disabled="true"' : ''}><span>${esc(o.texto)}</span>${sel ? svg('marca', 'icono menu-flotante__marca') : ''}</button>`;
    });
    html += '</div>';
    if (vacia) html += `<div class="menu-flotante__separador" role="separator"></div><button type="button" tabindex="-1" data-vaciar><span class="selector__vacio">${esc(vacia)}</span></button>`;
    caja.innerHTML = html;
    const busqueda = caja.querySelector('input');
    const lista = caja.querySelector('[role="listbox"]');
    const botones = () => [...lista.querySelectorAll('[role="option"]')].filter((b) => !b.hidden && !b.disabled);
    let activo = null;
    const controladores = [controlador, busqueda].filter(Boolean);
    const activar = (b, centrar = false, porTeclado = false) => {
      if (activo) activo.classList.remove('activo');
      activo = b || null;
      controladores.forEach((c) => (activo ? c.setAttribute('aria-activedescendant', activo.id) : c.removeAttribute('aria-activedescendant')));
      if (!activo) return;
      activo.classList.add('activo');
      const arriba = activo.offsetTop;
      if (centrar) lista.scrollTop = arriba - lista.clientHeight / 2 + activo.offsetHeight / 2;
      else if (arriba < lista.scrollTop) lista.scrollTop = arriba;
      else if (arriba + activo.offsetHeight > lista.scrollTop + lista.clientHeight) lista.scrollTop = arriba + activo.offsetHeight - lista.clientHeight;
      if (porTeclado) alActivar?.(opciones[Number(activo.dataset.indice)].valor);
    };
    const elegir = (b) => {
      cerrar();
      alElegir(b ? opciones[Number(b.dataset.indice)].valor : '');
    };
    const cerrar = () => { if (capa.actual?.el === caja) capa.cerrar(); };
    let tecleo = '';
    let tecleoReloj = null;
    const teclado = (ev) => {
      const lista_ = botones();
      const i = lista_.indexOf(activo);
      switch (ev.key) {
        case 'ArrowDown': activar(lista_[Math.min(lista_.length - 1, i + 1)] || lista_[0], false, true); return true;
        case 'ArrowUp': if (ev.altKey) { if (activo) elegir(activo); else cerrar(); return true; } activar(lista_[Math.max(0, i - 1)] || lista_[0], false, true); return true;
        case 'PageDown': activar(lista_[Math.min(lista_.length - 1, i + 8)], false, true); return true;
        case 'PageUp': activar(lista_[Math.max(0, i - 8)], false, true); return true;
        case 'Home': if (ev.target.tagName === 'INPUT') return false; activar(lista_[0], false, true); return true;
        case 'End': if (ev.target.tagName === 'INPUT') return false; activar(lista_[lista_.length - 1], false, true); return true;
        case 'Enter': if (antesDeEnter?.()) { cerrar(); return true; } if (activo) elegir(activo); else cerrar(); return true;
        case 'Escape': cerrar(); if (dejarEscape) return false; controlador.focus({ preventScroll: true }); return true;
        case 'Tab': cerrar(); return false;
        case ' ':
          if (ev.target === busqueda || ev.target.tagName === 'INPUT') return false;
          if (activo) elegir(activo); return true;
        default:
          // Escribir el inicio de una opción la resalta (como en la lista del navegador)
          if (ev.key.length === 1 && !ev.ctrlKey && !ev.metaKey && !ev.altKey && ev.target !== busqueda && ev.target.tagName !== 'INPUT') {
            clearTimeout(tecleoReloj);
            tecleo += normalizar(ev.key);
            tecleoReloj = setTimeout(() => { tecleo = ''; }, 600);
            const encontrada = lista_.find((b) => normalizar(b.textContent).startsWith(tecleo));
            if (encontrada) activar(encontrada, false, true);
            return true;
          }
          return false;
      }
    };
    controladores.forEach((c) => { c._teclado = teclado; });
    capa.abrir(ancla, caja, {
      dentro: dentro || [ancla],
      controladores,
      alCerrar: () => {
        controladores.forEach((c) => { if (c._teclado === teclado) c._teclado = c._tecladoPropio || null; c.removeAttribute('aria-activedescendant'); });
        controlador.setAttribute('aria-expanded', 'false');
        controlador._cerradoEn = Date.now();
        alCerrar?.();
      },
    });
    controlador.setAttribute('aria-expanded', 'true');
    controlador.setAttribute('aria-controls', idLista);
    caja.style.minWidth = `${Math.max(ancho || 0, ancla.offsetWidth)}px`;
    capa.colocar();
    caja.addEventListener('click', (ev) => {
      const b = ev.target.closest('[role="option"], [data-vaciar]');
      if (!b || b.disabled) return;
      if (b.hasAttribute('data-vaciar')) { cerrar(); alElegir(''); return; }
      elegir(b);
    });
    caja.addEventListener('mousemove', (ev) => {
      const b = ev.target.closest('[role="option"]');
      if (b && b !== activo && !b.disabled) activar(b);
    });
    busqueda?.addEventListener('input', () => {
      const t = normalizar(busqueda.value.trim());
      lista.querySelectorAll('[role="option"]').forEach((b) => { b.hidden = !!t && !normalizar(b.textContent).includes(t); });
      lista.querySelectorAll('.menu-flotante__titulo').forEach((titulo) => {
        let s = titulo.nextElementSibling;
        let alguno = false;
        while (s && !s.classList.contains('menu-flotante__titulo')) { if (!s.hidden) alguno = true; s = s.nextElementSibling; }
        titulo.hidden = !alguno;
      });
      activar(botones()[0]);
    });
    activar(lista.querySelector('[aria-selected="true"]:not([disabled])') || botones()[0], true);
    if (busqueda && !pantallaTactil.matches) busqueda.focus({ preventScroll: true });
    return {
      caja, activar, cerrar,
      resaltarValor(v) { const i = opciones.findIndex((o) => o.valor === v); if (i >= 0) activar(lista.querySelector(`[data-indice="${i}"]`), true); },
    };
  }

  /* ======================================================================
     Listas desplegables
     ====================================================================== */
  function opcionesDe(select) {
    const salida = [];
    [...select.options].forEach((o) => {
      if (o.value === '' || o.hidden) return;
      salida.push({ valor: o.value, texto: o.text, deshabilitada: o.disabled, grupo: o.parentElement.tagName === 'OPTGROUP' ? o.parentElement.label : undefined });
    });
    return salida;
  }
  const textoVacio = (select) => {
    const vacia = [...select.options].find((o) => o.value === '');
    return vacia && vacia.text.trim() && vacia.text.trim() !== '—' ? vacia.text.trim() : 'Selecciona';
  };

  function mejorarSelect(select) {
    if (select._ctl || select.multiple || select.size > 1 || esNativo(select)) return;
    // Una copia (cloneNode) trae el botón del original sin sus eventos: se descarta y se crea otro
    if (select.dataset.mejorado && select.nextElementSibling?.classList.contains('selector')) select.nextElementSibling.remove();
    select.dataset.mejorado = '1';
    const enLinea = select === document.activeElement || !!select.closest('td, .popover');
    if (enLinea) { selectEnLinea(select); return; }

    const boton = document.createElement('button');
    boton.type = 'button';
    boton.className = `selector ${select.className.replace(/\bselect-oculto\b/g, '')}`.trim();
    boton.setAttribute('aria-haspopup', 'listbox');
    boton.setAttribute('aria-expanded', 'false');
    copiarEtiqueta(select, boton);
    const pintar = () => {
      const opcion = select.options[select.selectedIndex];
      const vacio = !select.value;
      boton.innerHTML = `<span class="${vacio ? 'selector__vacio' : ''}">${esc(vacio ? textoVacio(select) : opcion.text)}</span>${svg('flecha_abajo')}`;
      boton.classList.toggle('vacio', vacio);
      boton.disabled = select.disabled;
      if (select.required) boton.setAttribute('aria-required', 'true'); else boton.removeAttribute('aria-required');
    };
    select._ctl = { boton, pintar };
    pintar();
    select.after(boton);
    ocultarOriginal(select, boton);
    select.addEventListener('change', pintar);
    // Si otro código cambia el valor (dictado, CURP, borradores), el botón se actualiza
    new MutationObserver(pintar).observe(select, { attributes: true, attributeFilter: ['disabled', 'required'], childList: true, subtree: true });
    Object.defineProperty(select, 'value', {
      configurable: true,
      get() { return NATIVO.valorSelect.get.call(this); },
      set(v) { NATIVO.valorSelect.set.call(this, v); pintar(); },
    });
    Object.defineProperty(select, 'selectedIndex', {
      configurable: true,
      get() { return NATIVO.indiceSelect.get.call(this); },
      set(v) { NATIVO.indiceSelect.set.call(this, v); pintar(); },
    });
    select.form?.addEventListener('reset', () => setTimeout(pintar));

    const elegirValor = (v) => {
      const antes = select.value;
      select.value = v;
      if (select.value !== antes) disparar(select, 'input', 'change');
    };
    const abrir = () => {
      if (select.disabled) return;
      const opciones = opcionesDe(select);
      abrirLista({
        ancla: boton.closest('.campo__control') || boton,
        controlador: boton,
        dentro: [boton],
        opciones,
        valor: select.value,
        buscar: opciones.length > 8,
        vacia: select.required ? '' : 'Sin valor',
        alElegir: (v) => { elegirValor(v); boton.focus({ preventScroll: true }); },
      });
    };
    boton.addEventListener('click', () => {
      if (capa.abiertaPara(boton)) { capa.cerrar(); return; }
      if (Date.now() - (boton._cerradoEn || 0) < 350) return; // Espacio/Entrar que acaba de elegir
      abrir();
    });
    // Con la lista cerrada: flechas, Entrar o Espacio la abren; una letra elige la opción que empieza así
    let tecleo = '';
    let reloj = null;
    boton._tecladoPropio = (ev) => {
      // Entrar y Espacio abren con el clic normal del botón
      if (['ArrowDown', 'ArrowUp', 'F4'].includes(ev.key) && !ev.ctrlKey && !ev.metaKey) { abrir(); return true; }
      if (ev.key.length === 1 && /\S/.test(ev.key) && !ev.ctrlKey && !ev.metaKey && !ev.altKey) {
        clearTimeout(reloj);
        tecleo += normalizar(ev.key);
        reloj = setTimeout(() => { tecleo = ''; }, 600);
        const o = opcionesDe(select).find((x) => !x.deshabilitada && normalizar(x.texto).startsWith(tecleo));
        if (o) elegirValor(o.valor);
        return true;
      }
      return false;
    };
    boton._teclado = boton._tecladoPropio;
  }

  /** Select que se crea y enfoca al editar una celda: queda visible y la lista se abre de inmediato. */
  function selectEnLinea(select) {
    select._ctl = { enLinea: true };
    select.classList.add('control-en-linea');
    const abrir = () => {
      abrirLista({
        ancla: select,
        controlador: select,
        dentro: [select],
        opciones: opcionesDe(select),
        valor: select.value,
        buscar: false,
        vacia: [...select.options].some((o) => o.value === '') ? 'Sin valor' : '',
        dejarEscape: true,
        alElegir: (v) => {
          if (v === select.value) return;
          NATIVO.valorSelect.set.call(select, v);
          disparar(select, 'input', 'change');
        },
      });
    };
    select.addEventListener('mousedown', (ev) => { ev.preventDefault(); if (capa.abiertaPara(select)) capa.cerrar(); else abrir(); });
    select._tecladoPropio = (ev) => {
      if ([' ', 'F4'].includes(ev.key) || (ev.altKey && ev.key === 'ArrowDown') || ev.key === 'ArrowDown' || ev.key === 'ArrowUp') { abrir(); return true; }
      return false;
    };
    select._teclado = select._tecladoPropio;
    // Esc y Tab siguen llegando al editor de la celda
    abrir();
  }

  /* ======================================================================
     Fechas
     ====================================================================== */
  const dosDigitos = (n) => String(n).padStart(2, '0');
  const isoDe = (d) => `${d.getFullYear()}-${dosDigitos(d.getMonth() + 1)}-${dosDigitos(d.getDate())}`;
  const fechaDeIso = (iso) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
    if (!m) return null;
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    return d.getFullYear() === Number(m[1]) && d.getMonth() === Number(m[2]) - 1 ? d : null;
  };
  const textoDeIso = (iso) => { const d = fechaDeIso(iso); return d ? `${dosDigitos(d.getDate())}/${dosDigitos(d.getMonth() + 1)}/${d.getFullYear()}` : ''; };
  const hoy = () => { const d = new Date(); d.setHours(0, 0, 0, 0); return d; };
  const sumarDias = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
  const sumarMeses = (d, n) => {
    const destino = new Date(d.getFullYear(), d.getMonth() + n, 1);
    const ultimo = new Date(destino.getFullYear(), destino.getMonth() + 1, 0).getDate();
    return new Date(destino.getFullYear(), destino.getMonth(), Math.min(d.getDate(), ultimo));
  };
  const mismoDia = (a, b) => a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
  const textoLargo = (d) => `${DIAS[(d.getDay() + 6) % 7]} ${d.getDate()} de ${MESES[d.getMonth()]} de ${d.getFullYear()}`;

  /** Lee lo escrito (dd/mm/aaaa, también con - o . y año de 2 cifras si laxo). Devuelve ISO, '' si está vacío o null si no es válida. */
  function leerFecha(texto, laxo = false) {
    const t = String(texto || '').trim();
    if (!t) return '';
    let d; let m; let a;
    const partes = t.split(/[\/\-.\s]+/).filter(Boolean);
    if (partes.length === 3) [d, m, a] = partes;
    else {
      const dg = t.replace(/\D/g, '');
      if (dg.length === 8) { d = dg.slice(0, 2); m = dg.slice(2, 4); a = dg.slice(4); }
      else if (dg.length === 6 && laxo) { d = dg.slice(0, 2); m = dg.slice(2, 4); a = dg.slice(4); }
      else return null;
    }
    if (!/^\d{1,2}$/.test(d) || !/^\d{1,2}$/.test(m) || !/^(\d{2}|\d{4})$/.test(a)) return null;
    if (a.length === 2) {
      if (!laxo) return null;
      const actual = new Date().getFullYear() % 100;
      a = String(Number(a) > actual + 10 ? 1900 + Number(a) : 2000 + Number(a));
    }
    const fecha = new Date(Number(a), Number(m) - 1, Number(d));
    if (fecha.getFullYear() !== Number(a) || fecha.getMonth() !== Number(m) - 1 || fecha.getDate() !== Number(d)) return null;
    return isoDe(fecha);
  }

  /** Máscara mientras se escribe: inserta las diagonales y completa con cero (3/ → 03/). */
  function enmascararFecha(crudo, insertando) {
    const separador = /[\/\-.\s]$/.test(crudo);
    let dg = crudo.replace(/\D/g, '');
    if (separador && dg.length === 1) dg = `0${dg}`;
    if (separador && dg.length === 3) dg = `${dg.slice(0, 2)}0${dg.slice(2)}`;
    dg = dg.slice(0, 8);
    let salida = dg.slice(0, 2);
    if (dg.length > 2 || (dg.length === 2 && (separador || insertando))) salida += `/${dg.slice(2, 4)}`;
    if (dg.length > 4 || (dg.length === 4 && (separador || insertando))) salida += `/${dg.slice(4, 8)}`;
    return salida;
  }

  function controlFecha(original) {
    if (original._ctl || esNativo(original)) return;
    const enLinea = original === document.activeElement || !!original.closest('td, .popover');
    let texto;
    let valor = leerNativo(original); // ISO actual
    if (enLinea) {
      // Celdas y filtros: el mismo elemento pasa a texto (conserva el foco y sus eventos)
      texto = original;
      texto.type = 'text';
      Object.defineProperty(texto, 'value', {
        configurable: true,
        get() { return valor; },
        set(v) { valor = fechaDeIso(v) ? String(v) : ''; mostrar(); },
      });
    } else {
      texto = document.createElement('input');
      texto.type = 'text';
      texto.className = original.className;
      original.after(texto);
      ocultarOriginal(original, texto);
      copiarEtiqueta(original, texto);
      Object.defineProperty(original, 'value', {
        configurable: true,
        get() { return leerNativo(this); },
        set(v) { escribirNativo(this, v); valor = leerNativo(this); mostrar(); },
      });
      original.form?.addEventListener('reset', () => setTimeout(() => { valor = leerNativo(original); mostrar(); }));
      new MutationObserver(sincronizarAtributos).observe(original, { attributes: true, attributeFilter: ['disabled', 'readonly', 'required'] });
    }
    texto.classList.add('control-fecha');
    texto.setAttribute('inputmode', 'numeric');
    texto.setAttribute('autocomplete', 'off');
    texto.setAttribute('spellcheck', 'false');
    texto.setAttribute('maxlength', '10');
    texto.setAttribute('placeholder', 'dd/mm/aaaa');
    texto.setAttribute('role', 'combobox');
    texto.setAttribute('aria-haspopup', 'dialog');
    texto.setAttribute('aria-expanded', 'false');
    texto.setAttribute('aria-autocomplete', 'none');
    const idCal = nuevoId('calendario');
    texto.setAttribute('aria-controls', idCal);
    function sincronizarAtributos() {
      texto.disabled = original.disabled;
      texto.readOnly = original.readOnly;
      if (original.required) texto.setAttribute('aria-required', 'true'); else texto.removeAttribute('aria-required');
    }
    if (!enLinea) sincronizarAtributos();

    // Icono del campo (el que ya trae .campo__control o uno propio): abre el calendario
    const control = texto.closest('.campo__control');
    let icono = !enLinea && control ? [...control.children].find((h) => h.matches('svg.icono') && h.compareDocumentPosition(texto) & Node.DOCUMENT_POSITION_FOLLOWING) : null;
    if (!icono && !enLinea) {
      // Sin .campo__control (pantallas de acceso, formularios sueltos): el icono va dentro de una envoltura,
      // a la derecha del campo, en lugar de quedar suelto en otro renglón
      const envoltura = document.createElement('span');
      envoltura.className = 'control-envoltura';
      texto.before(envoltura);
      envoltura.append(texto);
      envoltura.insertAdjacentHTML('beforeend', svg('calendario'));
      icono = envoltura.lastElementChild;
    }
    if (icono) {
      icono.classList.add('control-icono');
      icono.addEventListener('mousedown', (ev) => ev.preventDefault());
      icono.addEventListener('click', () => { if (texto.disabled || texto.readOnly) return; if (estado.abierto) cerrar(); else abrir(false); });
    }
    const ancla = control || texto;

    function mostrar() {
      const visible = textoDeIso(valor);
      // No reescribe lo que se está tecleando si dice lo mismo
      if (leerFecha(leerNativo(texto), true) !== (valor || '') || document.activeElement !== texto) escribirNativo(texto, visible);
      if (estado.abierto) { const d = fechaDeIso(valor); if (d) { estado.activo = d; estado.mes = new Date(d.getFullYear(), d.getMonth(), 1); } pintar(); }
    }
    /** Fija el valor ISO, avisa al formulario (input + change) y repinta. */
    function fijar(iso, { escribir = true } = {}) {
      const cambio = iso !== valor;
      valor = iso;
      if (!enLinea) escribirNativo(original, iso);
      if (escribir) escribirNativo(texto, textoDeIso(iso));
      if (cambio) {
        if (enLinea) disparar(texto, 'input', 'change');
        else disparar(original, 'input', 'change');
      }
    }

    /* ---------- Calendario ---------- */
    const estado = { abierto: false, vista: 'dias', mes: null, activo: null, navegando: false, el: null };
    const limites = () => ({ min: fechaDeIso(original.getAttribute('min')), max: fechaDeIso(original.getAttribute('max')) });
    const fuera = (d, { min, max }) => (min && d < min) || (max && d > max);
    const acotar = (d) => { const { min, max } = limites(); if (min && d < min) return min; if (max && d > max) return max; return d; };

    function pintar() {
      const el = estado.el;
      if (!el) return;
      const lim = limites();
      const a = estado.activo;
      const mes = estado.mes;
      let cuerpo = '';
      let titulo = '';
      let etiquetaAnterior = 'Mes anterior';
      let etiquetaSiguiente = 'Mes siguiente';
      if (estado.vista === 'dias') {
        titulo = `<button type="button" class="control-cal__titulo" data-vista="meses" tabindex="-1" aria-label="Elegir mes y año, ${MESES[mes.getMonth()]} de ${mes.getFullYear()}">${capital(MESES[mes.getMonth()])} ${mes.getFullYear()}${svg('flecha_abajo')}</button>`;
        const inicio = sumarDias(mes, -((mes.getDay() + 6) % 7));
        const seleccion = fechaDeIso(valor);
        const filas = [];
        for (let s = 0; s < 6; s++) {
          let fila = '';
          for (let i = 0; i < 7; i++) {
            const d = sumarDias(inicio, s * 7 + i);
            const iso = isoDe(d);
            const clases = ['control-cal__dia',
              d.getMonth() !== mes.getMonth() && 'es-fuera',
              mismoDia(d, hoy()) && 'es-hoy',
              mismoDia(d, seleccion) && 'es-seleccion',
              mismoDia(d, a) && 'activo',
              fuera(d, lim) && 'es-inhabil'].filter(Boolean).join(' ');
            fila += `<button type="button" role="gridcell" tabindex="-1" id="${idCal}-${iso}" class="${clases}" data-dia="${iso}" aria-label="${textoLargo(d)}" aria-selected="${mismoDia(d, seleccion)}"${fuera(d, lim) ? ' aria-disabled="true"' : ''}${mismoDia(d, hoy()) ? ' aria-current="date"' : ''}>${d.getDate()}</button>`;
          }
          filas.push(`<div class="control-cal__fila" role="row">${fila}</div>`);
        }
        cuerpo = `<div class="control-cal__rejilla" role="grid" aria-label="${capital(MESES[mes.getMonth()])} de ${mes.getFullYear()}">
          <div class="control-cal__fila" role="row">${['L', 'M', 'M', 'J', 'V', 'S', 'D'].map((l, i) => `<span class="control-cal__semana" role="columnheader" aria-label="${DIAS[i]}">${l}</span>`).join('')}</div>
          ${filas.join('')}</div>`;
      } else if (estado.vista === 'meses') {
        etiquetaAnterior = 'Año anterior';
        etiquetaSiguiente = 'Año siguiente';
        titulo = `<button type="button" class="control-cal__titulo" data-vista="anios" tabindex="-1" aria-label="Elegir año, ${mes.getFullYear()}">${mes.getFullYear()}${svg('flecha_abajo')}</button>`;
        cuerpo = `<div class="control-cal__meses" role="grid" aria-label="Meses de ${mes.getFullYear()}">${MESES.map((nombre, i) => {
          const primero = new Date(mes.getFullYear(), i, 1);
          const ultimo = new Date(mes.getFullYear(), i + 1, 0);
          const inhabil = (lim.min && ultimo < lim.min) || (lim.max && primero > lim.max);
          const sel = fechaDeIso(valor);
          return `<button type="button" role="gridcell" tabindex="-1" id="${idCal}-m${i}" class="control-cal__celda${a.getMonth() === i ? ' activo' : ''}${sel && sel.getFullYear() === mes.getFullYear() && sel.getMonth() === i ? ' es-seleccion' : ''}${inhabil ? ' es-inhabil' : ''}" data-mes="${i}" aria-label="${nombre} de ${mes.getFullYear()}"${inhabil ? ' aria-disabled="true"' : ''}>${capital(nombre.slice(0, 3))}</button>`;
        }).join('')}</div>`;
      } else {
        etiquetaAnterior = 'Años anteriores';
        etiquetaSiguiente = 'Años siguientes';
        const base = a.getFullYear() - (((a.getFullYear() % 12) + 12) % 12);
        titulo = `<span class="control-cal__titulo control-cal__titulo--fijo">${base} – ${base + 11}</span>`;
        const sel = fechaDeIso(valor);
        cuerpo = `<div class="control-cal__anios" role="grid" aria-label="Años">${Array.from({ length: 12 }, (_, i) => base + i).map((anio) => {
          const inhabil = (lim.min && anio < lim.min.getFullYear()) || (lim.max && anio > lim.max.getFullYear());
          return `<button type="button" role="gridcell" tabindex="-1" id="${idCal}-a${anio}" class="control-cal__celda${a.getFullYear() === anio ? ' activo' : ''}${sel && sel.getFullYear() === anio ? ' es-seleccion' : ''}${anio === new Date().getFullYear() ? ' es-hoy' : ''}${inhabil ? ' es-inhabil' : ''}" data-anio="${anio}"${inhabil ? ' aria-disabled="true"' : ''}>${anio}</button>`;
        }).join('')}</div>`;
      }
      const hoyInhabil = fuera(hoy(), lim);
      el.innerHTML = `<div class="control-cal__cabecera">${titulo}
          <button type="button" class="boton-icono" data-mover="-1" tabindex="-1" aria-label="${etiquetaAnterior}">${svg('atras')}</button>
          <button type="button" class="boton-icono" data-mover="1" tabindex="-1" aria-label="${etiquetaSiguiente}">${svg('adelante')}</button>
        </div>${cuerpo}
        <div class="control-cal__pie">
          <button type="button" data-hoy tabindex="-1"${hoyInhabil ? ' disabled' : ''}>Hoy</button>
          ${original.required ? '' : '<button type="button" data-borrar tabindex="-1">Borrar</button>'}
        </div>`;
      const activoEl = el.querySelector('.activo');
      if (activoEl) texto.setAttribute('aria-activedescendant', activoEl.id); else texto.removeAttribute('aria-activedescendant');
    }

    function moverActivo(d) {
      estado.activo = d;
      if (estado.vista === 'dias' && (d.getMonth() !== estado.mes.getMonth() || d.getFullYear() !== estado.mes.getFullYear())) estado.mes = new Date(d.getFullYear(), d.getMonth(), 1);
      if (estado.vista !== 'dias') estado.mes = new Date(d.getFullYear(), d.getMonth(), 1);
      pintar();
    }
    function elegirDia(d) {
      const { min, max } = limites();
      if ((min && d < min) || (max && d > max)) return;
      fijar(isoDe(d));
      marcar(texto, '');
      cerrar();
    }

    function abrir(porTeclado) {
      if (estado.abierto || texto.disabled || texto.readOnly) return;
      const el = document.createElement('div');
      el.className = 'control-cal';
      el.id = idCal;
      el.setAttribute('role', 'dialog');
      el.setAttribute('aria-label', 'Elegir fecha');
      estado.el = el;
      estado.abierto = true;
      estado.vista = 'dias';
      estado.navegando = !!porTeclado;
      const actual = fechaDeIso(leerFecha(leerNativo(texto), true) || valor);
      estado.activo = actual || acotar(hoy());
      estado.mes = new Date(estado.activo.getFullYear(), estado.activo.getMonth(), 1);
      pintar();
      capa.abrir(ancla, el, {
        dentro: [texto, icono].filter(Boolean),
        controladores: [texto],
        alCerrar: () => {
          estado.abierto = false;
          estado.el = null;
          texto.setAttribute('aria-expanded', 'false');
          texto.removeAttribute('aria-activedescendant');
        },
      });
      texto.setAttribute('aria-expanded', 'true');
      el.addEventListener('click', (ev) => {
        const b = ev.target.closest('button');
        if (!b || b.disabled || b.getAttribute('aria-disabled') === 'true') return;
        if (b.dataset.dia) { elegirDia(fechaDeIso(b.dataset.dia)); return; }
        if (b.dataset.mover) {
          const paso = Number(b.dataset.mover);
          if (estado.vista === 'dias') { estado.mes = new Date(estado.mes.getFullYear(), estado.mes.getMonth() + paso, 1); estado.activo = sumarMeses(estado.activo, paso); }
          else if (estado.vista === 'meses') moverActivo(new Date(estado.activo.getFullYear() + paso, estado.activo.getMonth(), 1));
          else moverActivo(new Date(estado.activo.getFullYear() + paso * 12, estado.activo.getMonth(), 1));
          pintar();
          return;
        }
        if (b.dataset.vista) { estado.vista = b.dataset.vista; pintar(); capa.colocar(); return; }
        if (b.dataset.mes !== undefined) { estado.activo = new Date(estado.mes.getFullYear(), Number(b.dataset.mes), 1); estado.mes = new Date(estado.activo); estado.vista = 'dias'; pintar(); capa.colocar(); return; }
        if (b.dataset.anio) { estado.activo = new Date(Number(b.dataset.anio), estado.activo.getMonth(), 1); estado.mes = new Date(estado.activo); estado.vista = 'meses'; pintar(); capa.colocar(); return; }
        if (b.hasAttribute('data-hoy')) { elegirDia(hoy()); return; }
        if (b.hasAttribute('data-borrar')) { fijar(''); cerrar(); }
      });
    }
    function cerrar() { if (estado.abierto && capa.actual?.el === estado.el) capa.cerrar(); }

    /** Al salir del campo: completa (03/04/26 → 03/04/2026) y avisa si no es una fecha válida. */
    function confirmarTexto() {
      const escrito = leerNativo(texto);
      const iso = leerFecha(escrito, true);
      if (iso === null) {
        if (!enLinea) {
          fijar('', { escribir: false });
          marcar(texto, 'Escribe la fecha completa: dd/mm/aaaa');
        } else escribirNativo(texto, textoDeIso(valor));
        return;
      }
      fijar(iso);
      const { min, max } = limites();
      const d = fechaDeIso(iso);
      if (!enLinea && d && max && d > max) marcar(texto, `La fecha no puede ser posterior al ${textoDeIso(isoDe(max))}`);
      else if (!enLinea && d && min && d < min) marcar(texto, `La fecha no puede ser anterior al ${textoDeIso(isoDe(min))}`);
    }

    texto.addEventListener('input', (ev) => {
      const crudo = leerNativo(texto);
      const alFinal = texto.selectionStart === crudo.length;
      if (alFinal) {
        const nuevo = enmascararFecha(crudo, (ev.inputType || '').startsWith('insert'));
        if (nuevo !== crudo) escribirNativo(texto, nuevo);
      }
      estado.navegando = false;
      const iso = leerFecha(leerNativo(texto));
      if (iso) {
        fijar(iso, { escribir: false });
        if (estado.abierto) { const d = fechaDeIso(iso); estado.activo = d; estado.mes = new Date(d.getFullYear(), d.getMonth(), 1); estado.vista = 'dias'; pintar(); }
      } else if (!enLinea && valor) {
        // Incompleta o no válida: el formulario no envía una fecha distinta a la que se ve
        fijar('', { escribir: false });
      } else if (enLinea && leerNativo(texto).trim() === '') { valor = ''; }
    });
    texto.addEventListener('blur', () => { if (!estado.abierto || !enLinea) confirmarTexto(); });
    texto.addEventListener('click', () => { if (!estado.abierto) abrir(false); });

    texto._tecladoPropio = (ev) => {
      if (!estado.abierto) {
        if ((ev.key === 'ArrowDown' || ev.key === 'ArrowUp') && !ev.ctrlKey && !ev.metaKey) { abrir(true); return true; }
        if (ev.key === 'Enter' && enLinea) confirmarTexto();
        return false;
      }
      const a = estado.activo;
      const v = estado.vista;
      const lateral = estado.navegando || ev.target !== texto;
      switch (ev.key) {
        case 'Escape':
          if (v !== 'dias') { estado.vista = 'dias'; pintar(); return true; }
          escribirNativo(texto, textoDeIso(valor));
          cerrar();
          return true;
        case 'Enter': {
          if (v === 'meses') { estado.mes = new Date(a.getFullYear(), a.getMonth(), 1); estado.vista = 'dias'; pintar(); return true; }
          if (v === 'anios') { estado.vista = 'meses'; pintar(); return true; }
          const iso = leerFecha(leerNativo(texto), true);
          if (!estado.navegando && iso && !mismoDia(fechaDeIso(iso), a)) { elegirDia(fechaDeIso(iso)); return true; }
          elegirDia(a);
          return true;
        }
        case 'Tab': cerrar(); confirmarTexto(); return false;
        case 'ArrowDown': estado.navegando = true; moverActivo(v === 'dias' ? sumarDias(a, 7) : v === 'meses' ? sumarMeses(a, 3) : new Date(a.getFullYear() + 4, a.getMonth(), 1)); return true;
        case 'ArrowUp': estado.navegando = true; moverActivo(v === 'dias' ? sumarDias(a, -7) : v === 'meses' ? sumarMeses(a, -3) : new Date(a.getFullYear() - 4, a.getMonth(), 1)); return true;
        case 'ArrowRight':
        case 'ArrowLeft': {
          if (!lateral) return false;
          const paso = ev.key === 'ArrowRight' ? 1 : -1;
          moverActivo(v === 'dias' ? sumarDias(a, paso) : v === 'meses' ? sumarMeses(a, paso) : new Date(a.getFullYear() + paso, a.getMonth(), 1));
          return true;
        }
        case 'PageDown':
        case 'PageUp': {
          estado.navegando = true;
          const paso = ev.key === 'PageDown' ? 1 : -1;
          if (v === 'dias') moverActivo(ev.shiftKey ? sumarMeses(a, paso * 12) : sumarMeses(a, paso));
          else if (v === 'meses') moverActivo(sumarMeses(a, paso * 12));
          else moverActivo(new Date(a.getFullYear() + paso * 12, a.getMonth(), 1));
          return true;
        }
        case 'Home':
        case 'End':
          if (!lateral || v !== 'dias') return false;
          moverActivo(sumarDias(a, ev.key === 'Home' ? -((a.getDay() + 6) % 7) : 6 - ((a.getDay() + 6) % 7)));
          return true;
        default: return false;
      }
    };
    texto._teclado = texto._tecladoPropio;
    original._ctl = { texto, abrir, cerrar };
    mostrar();
    // Al editar una celda de fecha, el calendario se abre solo
    if (enLinea && document.activeElement === texto) abrir(false);
  }

  /* ======================================================================
     Horas
     ====================================================================== */
  const textoMin = (m) => `${dosDigitos(Math.floor(m / 60))}:${dosDigitos(m % 60)}`;
  /** Lee 8, 830, 8:30, 20.30, 8 pm, 8:30 a. m. → minutos del día; null si no es válida. */
  function leerHora(texto) {
    let t = normalizar(String(texto || '')).replace(/\s+/g, '').replace(/\./g, '');
    if (!t) return '';
    let sufijo = null;
    const s = /(am|pm|a|p|m)$/.exec(t);
    if (s && /\d/.test(t)) { sufijo = s[1].startsWith('p') ? 'pm' : s[1].startsWith('a') ? 'am' : null; t = t.slice(0, -s[1].length).replace(/[a-z]+$/, ''); }
    let h; let m;
    const conPuntos = /^(\d{1,2})[:h](\d{1,2})?$/.exec(t);
    if (conPuntos) { h = Number(conPuntos[1]); m = Number(conPuntos[2] || 0); }
    else if (/^\d{1,4}$/.test(t)) {
      if (t.length <= 2) { h = Number(t); m = 0; }
      else { h = Number(t.slice(0, t.length - 2)); m = Number(t.slice(-2)); }
    } else return null;
    if (sufijo === 'pm' && h < 12) h += 12;
    if (sufijo === 'am' && h === 12) h = 0;
    if (h > 23 || m > 59) return null;
    return h * 60 + m;
  }
  const isoHora = (v) => { const m = /^(\d{2}):(\d{2})/.exec(String(v || '')); return m ? Number(m[1]) * 60 + Number(m[2]) : null; };

  function controlHora(original) {
    if (original._ctl || esNativo(original)) return;
    const enLinea = original === document.activeElement || !!original.closest('td, .popover');
    let texto;
    let valor = leerNativo(original).slice(0, 5);
    const paso = Number(original.getAttribute('step')) > 0 && Number(original.getAttribute('step')) <= 300 ? 5 : 15;
    if (enLinea) {
      texto = original;
      texto.type = 'text';
      Object.defineProperty(texto, 'value', {
        configurable: true,
        get() { return valor; },
        set(v) { valor = isoHora(v) !== null ? String(v).slice(0, 5) : ''; escribirNativo(texto, valor); },
      });
    } else {
      texto = document.createElement('input');
      texto.type = 'text';
      texto.className = original.className;
      original.after(texto);
      ocultarOriginal(original, texto);
      copiarEtiqueta(original, texto);
      Object.defineProperty(original, 'value', {
        configurable: true,
        get() { return leerNativo(this); },
        set(v) { escribirNativo(this, v); valor = leerNativo(this).slice(0, 5); if (document.activeElement !== texto) escribirNativo(texto, valor); },
      });
      original.form?.addEventListener('reset', () => setTimeout(() => { valor = leerNativo(original).slice(0, 5); escribirNativo(texto, valor); }));
      const sincronizar = () => { texto.disabled = original.disabled; texto.readOnly = original.readOnly; if (original.required) texto.setAttribute('aria-required', 'true'); else texto.removeAttribute('aria-required'); };
      sincronizar();
      new MutationObserver(sincronizar).observe(original, { attributes: true, attributeFilter: ['disabled', 'readonly', 'required'] });
    }
    texto.classList.add('control-hora');
    texto.setAttribute('inputmode', 'numeric');
    texto.setAttribute('autocomplete', 'off');
    texto.setAttribute('spellcheck', 'false');
    texto.setAttribute('maxlength', '8');
    texto.setAttribute('placeholder', 'hh:mm');
    texto.setAttribute('role', 'combobox');
    texto.setAttribute('aria-haspopup', 'listbox');
    texto.setAttribute('aria-expanded', 'false');
    texto.setAttribute('aria-autocomplete', 'list');
    escribirNativo(texto, valor);

    const control = texto.closest('.campo__control');
    let icono = !enLinea && control ? [...control.children].find((h) => h.matches('svg.icono') && h.compareDocumentPosition(texto) & Node.DOCUMENT_POSITION_FOLLOWING) : null;
    if (!icono && !enLinea) {
      const envoltura = document.createElement('span');
      envoltura.className = 'control-envoltura';
      texto.before(envoltura);
      envoltura.append(texto);
      envoltura.insertAdjacentHTML('beforeend', svg('reloj'));
      icono = envoltura.lastElementChild;
    }
    const ancla = control || texto;

    const fijar = (v) => {
      const cambio = v !== valor;
      valor = v;
      escribirNativo(texto, v);
      if (!enLinea) escribirNativo(original, v);
      if (cambio) disparar(enLinea ? texto : original, 'input', 'change');
    };
    const opciones = [];
    for (let m = 0; m < 1440; m += paso) opciones.push({ valor: textoMin(m), texto: textoMin(m) });
    let lista = null;
    const cercana = (m) => textoMin((Math.round(m / paso) * paso) % 1440);
    const abrir = () => {
      if (lista || texto.disabled || texto.readOnly) return;
      const actual = leerHora(leerNativo(texto));
      lista = abrirLista({
        ancla,
        controlador: texto,
        dentro: [texto, icono].filter(Boolean),
        opciones,
        valor: valor || null,
        clase: 'control-horas',
        ancho: 120,
        alElegir: (v) => { fijar(v); marcar(texto, ''); },
        // Con flechas, la hora resaltada se escribe en el campo; Entrar usa lo escrito si es válido
        alActivar: (v) => escribirNativo(texto, v),
        antesDeEnter: () => {
          const m = leerHora(leerNativo(texto));
          if (typeof m !== 'number') return false;
          fijar(textoMin(m));
          marcar(texto, '');
          return true;
        },
        alCerrar: () => { lista = null; },
      });
      lista.caja.style.minWidth = '120px';
      lista.resaltarValor(typeof actual === 'number' ? cercana(actual) : (valor ? cercana(isoHora(valor)) : cercana(new Date().getHours() * 60 + new Date().getMinutes())));
    };
    const confirmar = () => {
      const m = leerHora(leerNativo(texto));
      if (m === '') { fijar(''); return; }
      if (m === null) {
        if (enLinea) { escribirNativo(texto, valor); return; }
        const escrito = leerNativo(texto);
        fijar('');
        escribirNativo(texto, escrito);
        marcar(texto, 'Escribe la hora como hh:mm (24 horas)');
        return;
      }
      fijar(textoMin(m));
    };
    if (icono) {
      icono.classList.add('control-icono');
      icono.addEventListener('mousedown', (ev) => ev.preventDefault());
      icono.addEventListener('click', () => { if (lista) lista.cerrar(); else abrir(); });
    }
    texto.addEventListener('click', abrir);
    texto.addEventListener('input', () => {
      const crudo = leerNativo(texto).replace(/[^0-9:apm.\s]/gi, '');
      if (crudo !== leerNativo(texto)) escribirNativo(texto, crudo);
      const m = leerHora(crudo);
      if (typeof m === 'number' && /^\d{2}:\d{2}$/.test(crudo)) { valor = textoMin(m); if (!enLinea) { escribirNativo(original, valor); disparar(original, 'change'); } }
      if (lista && typeof m === 'number') lista.resaltarValor(cercana(m));
    });
    texto.addEventListener('blur', () => { if (!lista) confirmar(); });
    texto._tecladoPropio = (ev) => {
      if ((ev.key === 'ArrowDown' || ev.key === 'ArrowUp') && !ev.ctrlKey) { abrir(); return true; }
      if (ev.key === 'Enter') confirmar();
      return false;
    };
    texto._teclado = texto._tecladoPropio;
    original._ctl = { texto, abrir };
    if (enLinea && document.activeElement === texto) abrir();
  }

  /* ======================================================================
     Archivos: botón propio; las etiquetas que abren un input file oculto
     se pueden usar con teclado y aceptan archivos soltados.
     ====================================================================== */
  const aceptaArchivo = (input, archivo) => {
    const acepta = (input.getAttribute('accept') || '').split(',').map((x) => x.trim().toLowerCase()).filter(Boolean);
    if (!acepta.length) return true;
    const nombre = archivo.name.toLowerCase();
    const tipo = (archivo.type || '').toLowerCase();
    return acepta.some((a) => (a.startsWith('.') ? nombre.endsWith(a) : a.endsWith('/*') ? tipo.startsWith(a.slice(0, -1)) : tipo === a));
  };
  function permitirSoltar(zona, input) {
    if (zona._soltar) return;
    zona._soltar = true;
    let profundidad = 0;
    zona.addEventListener('dragenter', (ev) => { if (!ev.dataTransfer?.types.includes('Files')) return; ev.preventDefault(); profundidad++; zona.classList.add('control-soltando'); });
    zona.addEventListener('dragover', (ev) => { if (ev.dataTransfer?.types.includes('Files')) { ev.preventDefault(); ev.dataTransfer.dropEffect = 'copy'; } });
    zona.addEventListener('dragleave', () => { profundidad = Math.max(0, profundidad - 1); if (!profundidad) zona.classList.remove('control-soltando'); });
    zona.addEventListener('drop', (ev) => {
      if (!ev.dataTransfer?.files.length) return;
      ev.preventDefault();
      profundidad = 0;
      zona.classList.remove('control-soltando');
      const archivos = [...ev.dataTransfer.files].filter((a) => aceptaArchivo(input, a));
      if (!archivos.length) {
        if (typeof window.mostrarNotificacion === 'function') window.mostrarNotificacion('error', 'Ese tipo de archivo no se puede usar aquí.');
        return;
      }
      const dt = new DataTransfer();
      (input.multiple ? archivos : archivos.slice(0, 1)).forEach((a) => dt.items.add(a));
      input.files = dt.files;
      disparar(input, 'input', 'change');
    });
  }
  function controlArchivo(input) {
    if (input._ctl || esNativo(input) || input.closest('[data-datos-zona]')) return; // la zona de datos/ ya es propia
    input._ctl = true;
    const etiqueta = input.closest('label') || (input.id && document.querySelector(`label[for="${CSS.escape(input.id)}"]`));
    if (input.hidden || input.classList.contains('select-oculto')) {
      // Ya tiene su propio botón: la etiqueta que lo abre necesita teclado
      if (etiqueta && !etiqueta.hasAttribute('tabindex') && !etiqueta.querySelector('button, a, [tabindex]')) {
        etiqueta.tabIndex = 0;
        etiqueta.setAttribute('role', 'button');
        etiqueta.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); input.click(); }
        });
      }
      if (etiqueta) permitirSoltar(etiqueta, input);
      return;
    }
    // Input file visible: se cambia por un botón propio con el nombre del archivo elegido
    const caja = document.createElement('span');
    caja.className = 'control-archivo';
    caja.innerHTML = `<button type="button" class="control-archivo__boton">${svg('subir')}<span>${input.multiple ? 'Elegir archivos' : 'Elegir archivo'}</span></button><span class="control-archivo__nombre" aria-live="polite">Ningún archivo elegido</span>`;
    input.after(caja);
    input.hidden = true;
    const boton = caja.querySelector('button');
    copiarEtiqueta(input, boton);
    boton.disabled = input.disabled;
    boton.addEventListener('click', () => input.click());
    const nombre = caja.querySelector('.control-archivo__nombre');
    const pintar = () => {
      const n = input.files ? input.files.length : 0;
      nombre.textContent = n === 0 ? 'Ningún archivo elegido' : n === 1 ? input.files[0].name : `${n} archivos`;
      nombre.classList.toggle('control-archivo__nombre--vacio', n === 0);
    };
    input.addEventListener('change', pintar);
    input.form?.addEventListener('reset', () => setTimeout(pintar));
    pintar();
    permitirSoltar(caja, input);
  }

  /* ======================================================================
     Áreas de texto: crecen con lo escrito (sin el agarre del navegador)
     ====================================================================== */
  const areas = new Set();
  const VALOR_AREA = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value');
  function ajustarArea(ta) {
    if (!ta.isConnected || !ta.offsetParent) return;
    const estilo = getComputedStyle(ta);
    const bordes = parseFloat(estilo.borderTopWidth) + parseFloat(estilo.borderBottomWidth);
    const desplazable = ta.closest('[data-desplazable]') || document.scrollingElement;
    const antes = desplazable ? desplazable.scrollTop : 0;
    ta.style.height = 'auto';
    ta.style.height = `${ta.scrollHeight + bordes}px`;
    // Si alguna hoja le pone un alto máximo, recupera su barra de desplazamiento
    ta.style.overflowY = ta.scrollHeight > ta.clientHeight + 1 ? 'auto' : 'hidden';
    if (desplazable && desplazable.scrollTop !== antes) desplazable.scrollTop = antes;
  }
  function controlArea(ta) {
    if (ta._ctl || esNativo(ta) || ta.hasAttribute('data-sin-autoajuste') || ta.classList.contains('entrada__texto')) return;
    ta._ctl = true;
    ta.classList.add('control-area');
    areas.add(ta);
    ta.addEventListener('input', () => ajustarArea(ta));
    ta.addEventListener('change', () => ajustarArea(ta));
    ta.addEventListener('focus', () => ajustarArea(ta));
    // Valor puesto por código (dictado, borradores): se ajusta en el siguiente cuadro
    Object.defineProperty(ta, 'value', {
      configurable: true,
      get() { return VALOR_AREA.get.call(this); },
      set(v) { VALOR_AREA.set.call(this, v); requestAnimationFrame(() => ajustarArea(ta)); },
    });
    requestAnimationFrame(() => ajustarArea(ta));
  }
  let relojAreas = null;
  window.addEventListener('resize', () => {
    clearTimeout(relojAreas);
    relojAreas = setTimeout(() => areas.forEach((ta) => (ta.isConnected ? ajustarArea(ta) : areas.delete(ta))), 120);
  });

  /* ======================================================================
     Avisos de title: globo con el estilo de la app en lugar del aviso del navegador
     ====================================================================== */
  const EXCLUIR_AVISO = 'input, textarea, select, option, iframe, abbr, [data-nativo]';
  let aviso = null;
  let avisoDueno = null;
  let avisoReloj = null;
  let ultimoAviso = 0;
  function tomarTitulo(el) {
    const t = el.getAttribute('title');
    if (t === null) return;
    el.removeAttribute('title');
    if (!t.trim()) return;
    el.dataset.aviso = t;
    if (!el.hasAttribute('aria-label') && !el.hasAttribute('aria-labelledby') && !el.textContent.trim()) el.setAttribute('aria-label', t);
    else if (!el.hasAttribute('aria-label') && el.textContent.trim() !== t) el.setAttribute('aria-description', t);
  }
  function mostrarAviso(el) {
    const texto = el.dataset.aviso;
    if (!texto) return;
    ocultarAviso();
    avisoDueno = el;
    aviso = document.createElement('div');
    aviso.className = 'control-aviso';
    aviso.setAttribute('role', 'tooltip');
    aviso.textContent = texto;
    document.body.appendChild(aviso);
    const r = el.getBoundingClientRect();
    const ancho = aviso.offsetWidth;
    const alto = aviso.offsetHeight;
    let y = r.bottom + 6;
    if (y + alto > window.innerHeight - 8) y = r.top - alto - 6;
    const x = Math.max(8, Math.min(r.left + r.width / 2 - ancho / 2, window.innerWidth - ancho - 8));
    aviso.style.left = `${Math.round(x)}px`;
    aviso.style.top = `${Math.round(Math.max(8, y))}px`;
  }
  function ocultarAviso() {
    clearTimeout(avisoReloj);
    if (aviso) { aviso.remove(); aviso = null; ultimoAviso = Date.now(); }
    avisoDueno = null;
  }
  document.addEventListener('pointerover', (ev) => {
    if (ev.pointerType !== 'mouse') return;
    const el = ev.target.closest?.('[title], [data-aviso]');
    if (!el || !(el instanceof HTMLElement) || el.matches(EXCLUIR_AVISO)) return;
    tomarTitulo(el);
    if (el === avisoDueno) return;
    ocultarAviso();
    if (!el.dataset.aviso) return;
    avisoDueno = el;
    avisoReloj = setTimeout(() => { if (avisoDueno === el && el.isConnected && el.matches(':hover')) mostrarAviso(el); }, Date.now() - ultimoAviso < 400 ? 60 : 500);
  }, true);
  document.addEventListener('pointerout', (ev) => {
    if (!avisoDueno) return;
    if (ev.relatedTarget && avisoDueno.contains(ev.relatedTarget)) return;
    if (avisoDueno.contains(ev.target)) ocultarAviso();
  }, true);
  document.addEventListener('focusin', (ev) => {
    const el = ev.target.closest?.('[title], [data-aviso]');
    if (!el || !(el instanceof HTMLElement) || el.matches(EXCLUIR_AVISO)) return;
    tomarTitulo(el);
    if (el.matches(':focus-visible') && el.dataset.aviso) mostrarAviso(el);
  });
  document.addEventListener('focusout', () => ocultarAviso());
  ['pointerdown', 'wheel'].forEach((t) => document.addEventListener(t, ocultarAviso, { capture: true, passive: true }));
  window.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && aviso) ocultarAviso(); }, true);
  window.addEventListener('scroll', () => { if (aviso) ocultarAviso(); }, true);

  /* ======================================================================
     Aplicación: lo que ya existe y lo que se agrega después
     ====================================================================== */
  function aplicar(raiz) {
    if (!raiz || raiz.nodeType !== 1) return;
    const buscar = (sel) => (raiz.matches(sel) ? [raiz] : []).concat([...raiz.querySelectorAll(sel)]);
    buscar('select').forEach(mejorarSelect);
    buscar('input[type="date"]').forEach(controlFecha);
    buscar('input[type="time"]').forEach(controlHora);
    buscar('input[type="file"]').forEach(controlArchivo);
    buscar('textarea').forEach(controlArea);
  }
  const SELECTOR = 'select, input[type="date"], input[type="time"], input[type="file"], textarea';
  new MutationObserver((cambios) => {
    for (const c of cambios) {
      c.addedNodes.forEach((n) => { if (n.nodeType === 1 && (n.matches(SELECTOR) || n.querySelector(SELECTOR))) aplicar(n); });
      // Si el control que abrió la capa desaparece (p. ej. la celda se repinta), la capa se cierra
      if (c.removedNodes.length && capa.actual && !capa.actual.ancla.isConnected) capa.cerrar(false);
      if (c.removedNodes.length && avisoDueno && !avisoDueno.isConnected) ocultarAviso();
    }
  }).observe(document.documentElement, { childList: true, subtree: true });
  aplicar(document.body);

  // Compatibilidad: registros_clinicos.js llama a mejorarSelect(select)
  window.mejorarSelect = mejorarSelect;
  window.Controles = { aplicar, select: mejorarSelect, fecha: controlFecha, hora: controlHora, archivo: controlArchivo, leerFecha, leerHora };
})();
