/* ==========================================================================
   Expedientes Clínicos — notificaciones (campana de la barra lateral)
   Bandeja con pestañas "Sin leer / Todas", agrupada por día. Cada minuto se
   consulta api/notificaciones.php?conteo=1 (que además envía los recordatorios
   de citas que ya tocan) y se muestra un aviso por cada notificación nueva.
   Usa BASE, TOKEN_CSRF, escaparHtml y mostrarNotificacion (app.js) y
   abrirPopover / cerrarPopover (espacio.js).
   ========================================================================== */
(() => {
  const boton = document.querySelector('[data-notificaciones-abrir]');
  if (!boton || !document.body.dataset.usuario) return;

  const punto = boton.querySelector('[data-notificaciones-punto]');
  const CLAVE_ULTIMA = `femeci-notificaciones-ultima-${document.body.dataset.usuario}`;
  const INTERVALO = 60000;

  const svg = (trazo, clase = 'icono') => `<svg class="${clase}" viewBox="0 0 24 24" aria-hidden="true">${trazo}</svg>`;
  const ICONOS = {
    cita_recordatorio: '<path d="M16.5 11V7a2 2 0 0 0-2-2h-9a2 2 0 0 0-2 2v9.5a2 2 0 0 0 2 2H11"/><path d="M7 3v4M13 3v4M3.5 10h13"/><circle cx="17" cy="17" r="4.5"/><path d="M17 15v2l1.4 1"/>',
    invitacion_aceptada: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="m16 11 2 2 4-4"/>',
    pagina_compartida: '<path d="M13 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5"/><path d="M13 3v5a1 1 0 0 0 1 1h5"/><path d="m13 3 6 6v3"/><path d="M15 18.5h6.5M19 16l2.5 2.5L19 21"/>',
    campana: '<path d="M6 9a6 6 0 0 1 12 0c0 6 2.5 8 2.5 8h-17S6 15 6 9z"/><path d="M10 20.5a2.2 2.2 0 0 0 4 0"/>',
  };
  const ICONO_LEIDA = '<path d="m5 12.5 4.5 4.5L19 7.5"/>';
  const ICONO_SIN_LEER = '<circle cx="12" cy="12" r="4"/>';
  const ICONO_ARCHIVAR = '<rect x="3" y="4" width="18" height="4.5" rx="1"/><path d="M5 8.5V18a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.5M10 12.5h4"/>';
  // Engrane (iconos.php): abre los ajustes de notificaciones push (modulos/push.js)
  const ICONO_AJUSTES = '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>';

  /* Ilustración del estado vacío: estetoscopio de línea que "escucha" un pulso (único acento) */
  const ILUSTRACION = `
    <svg class="bandeja__ilustracion" viewBox="40 4 126 108" aria-hidden="true">
      <g class="bandeja__trazo">
        <circle cx="54" cy="15" r="3.2" pathLength="1"/>
        <circle cx="86" cy="15" r="3.2" pathLength="1"/>
        <path d="M54 18.2C54 38 56 50 70 57" pathLength="1"/>
        <path d="M86 18.2C86 38 84 50 70 57" pathLength="1"/>
        <path d="M70 57v13c0 24 16 32 35.5 23.8" pathLength="1"/>
        <circle cx="115" cy="88" r="11" pathLength="1"/>
        <circle class="bandeja__trazo-suave" cx="115" cy="88" r="5.5" pathLength="1"/>
      </g>
      <path class="bandeja__pulso" d="M127.5 88h5l3-8.5 4.5 18 4-13.5 2.5 4h10" pathLength="1"/>
      <path class="bandeja__pulso-luz" d="M127.5 88h5l3-8.5 4.5 18 4-13.5 2.5 4h10" pathLength="1"/>
    </svg>`;

  const estado = {
    filtro: 'no_leidas',
    noLeidas: 0,
    lista: [],
    hayMas: false,
    cargando: false,
    panel: null,
  };

  /* ---------- Peticiones ---------- */
  async function pedir(consulta, cuerpo) {
    const r = await fetch(`${BASE}/api/notificaciones.php${consulta}`, {
      method: cuerpo ? 'POST' : 'GET',
      headers: cuerpo ? { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF } : {},
      body: cuerpo ? JSON.stringify(cuerpo) : undefined,
      keepalive: !!cuerpo,
    });
    let datos = {};
    try { datos = await r.json(); } catch (e) { /* sin cuerpo */ }
    if (!r.ok || datos.ok === false) throw new Error(datos.error || 'No se pudo completar la acción.');
    return datos;
  }

  /* ---------- Fechas ---------- */
  const inicioDia = (f) => new Date(f.getFullYear(), f.getMonth(), f.getDate()).getTime();
  const formatoDia = new Intl.DateTimeFormat('es-MX', { weekday: 'long', day: 'numeric', month: 'long' });
  const formatoDiaAnio = new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'long', year: 'numeric' });
  const formatoHora = new Intl.DateTimeFormat('es-MX', { hour: '2-digit', minute: '2-digit', hour12: false });

  function nombreDia(f) {
    const hoy = inicioDia(new Date());
    const dia = inicioDia(f);
    if (dia === hoy) return 'Hoy';
    if (dia === hoy - 86400000) return 'Ayer';
    const texto = f.getFullYear() === new Date().getFullYear() ? formatoDia.format(f) : formatoDiaAnio.format(f);
    return texto.charAt(0).toUpperCase() + texto.slice(1);
  }

  /** Hoy: "Ahora", "hace 5 min", "hace 2 h"; otros días: la hora ("16:05"). */
  function horaRelativa(f) {
    if (inicioDia(f) !== inicioDia(new Date())) return formatoHora.format(f);
    const minutos = Math.floor((Date.now() - f.getTime()) / 60000);
    if (minutos < 1) return 'Ahora';
    if (minutos < 60) return `hace ${minutos} min`;
    return `hace ${Math.floor(minutos / 60)} h`;
  }

  /* ---------- Punto de "sin leer" ---------- */
  function pintarConteo(n) {
    estado.noLeidas = Math.max(0, n | 0);
    punto.hidden = estado.noLeidas === 0;
    const texto = estado.noLeidas === 0 ? 'Notificaciones' : `Notificaciones · ${estado.noLeidas} sin leer`;
    boton.title = texto;
    boton.setAttribute('aria-label', texto);
    if (!estado.panel) return;
    const cuenta = estado.panel.querySelector('[data-cuenta]');
    cuenta.textContent = estado.noLeidas > 0 ? String(estado.noLeidas) : '';
    estado.panel.querySelector('[data-todas]').hidden = estado.noLeidas === 0;
  }

  /* ---------- Bandeja ---------- */
  function abrir() {
    if (typeof abrirPopover !== 'function') return;
    // Alto que cabe debajo de la campana (para que el popover no la tape); en ventanas muy bajas, toda la altura
    const libre = window.innerHeight - boton.getBoundingClientRect().bottom - 24;   // separación, borde y margen inferior
    const alto = Math.round(libre >= 340 ? Math.min(560, libre) : Math.min(560, window.innerHeight - 16));
    const el = abrirPopover(boton, `
      <div class="bandeja" style="height:${alto}px">
        <header class="bandeja__cabecera">
          <span class="bandeja__titulo">Notificaciones</span>
          <button type="button" class="bandeja__todo" data-todas hidden>Marcar todo como leído</button>
          <button type="button" class="boton-icono" data-push-ajustes-abrir title="Notificaciones push">${svg(ICONO_AJUSTES)}</button>
        </header>
        <div class="bandeja__pestanas" role="tablist" aria-label="Filtro">
          <button type="button" role="tab" data-filtro="no_leidas" aria-selected="${estado.filtro === 'no_leidas'}">Sin leer <span class="bandeja__cuenta" data-cuenta></span></button>
          <button type="button" role="tab" data-filtro="todas" aria-selected="${estado.filtro === 'todas'}">Todas</button>
        </div>
        <div class="bandeja__cuerpo" data-cuerpo role="tabpanel"></div>
      </div>`, { clase: 'popover--bandeja', alinear: 'izquierda', alCerrar: () => { estado.panel = null; } });
    estado.panel = el;
    pintarConteo(estado.noLeidas);
    el.addEventListener('click', alHacerClic);
    cargar(true);
  }

  async function cargar(reiniciar) {
    if (estado.cargando) return;
    estado.cargando = true;
    const cuerpo = estado.panel?.querySelector('[data-cuerpo]');
    if (reiniciar && cuerpo && !cuerpo.children.length) cuerpo.innerHTML = '<p class="bandeja__cargando">Cargando…</p>';
    try {
      const antes = !reiniciar && estado.lista.length ? `&antes=${estado.lista[estado.lista.length - 1].id}` : '';
      const datos = await pedir(`?filtro=${estado.filtro}${antes}`);
      estado.lista = reiniciar ? datos.notificaciones : estado.lista.concat(datos.notificaciones);
      estado.hayMas = datos.hay_mas;
      pintarConteo(datos.no_leidas);
      pintarLista();
    } catch (e) {
      if (cuerpo) cuerpo.innerHTML = `<p class="bandeja__cargando">${escaparHtml(e.message)}</p>`;
    } finally {
      estado.cargando = false;
    }
  }

  function itemHtml(n) {
    const f = new Date(n.creada);
    return `
      <div class="bandeja__item${n.leida ? '' : ' bandeja__item--nueva'}" role="listitem" data-id="${n.id}">
        <button type="button" class="bandeja__enlace" data-abrir="${n.id}">
          <span class="bandeja__icono">${svg(ICONOS[n.tipo] || ICONOS.campana)}</span>
          <span class="bandeja__textos">
            <span class="bandeja__item-titulo">${escaparHtml(n.titulo)}</span>
            ${n.texto ? `<span class="bandeja__item-texto">${escaparHtml(n.texto)}</span>` : ''}
          </span>
          <time class="bandeja__hora" datetime="${escaparHtml(n.creada)}" title="${escaparHtml(formatoDiaAnio.format(f) + ', ' + formatoHora.format(f))}">${horaRelativa(f)}</time>
        </button>
        <span class="bandeja__acciones">
          <button type="button" class="boton-icono" data-leida="${n.id}" title="${n.leida ? 'Marcar como no leída' : 'Marcar como leída'}">${svg(n.leida ? ICONO_SIN_LEER : ICONO_LEIDA)}</button>
          <button type="button" class="boton-icono" data-archivar="${n.id}" title="Archivar">${svg(ICONO_ARCHIVAR)}</button>
        </span>
      </div>`;
  }

  function pintarLista() {
    const cuerpo = estado.panel?.querySelector('[data-cuerpo]');
    if (!cuerpo) return;
    if (!estado.lista.length) {
      const frase = estado.filtro === 'no_leidas'
        ? 'Estás al día, no hay nada pendiente por leer.'
        : 'Aquí verás tus recordatorios de citas y los avisos del equipo.';
      cuerpo.innerHTML = `<div class="bandeja__vacio">${ILUSTRACION}<p>${frase}</p></div>`;
      return;
    }
    let html = '';
    let diaActual = null;
    estado.lista.forEach((n) => {
      const dia = nombreDia(new Date(n.creada));
      if (dia !== diaActual) {
        if (diaActual !== null) html += '</div>';
        html += `<h3 class="bandeja__dia">${escaparHtml(dia)}</h3><div class="bandeja__grupo" role="list">`;
        diaActual = dia;
      }
      html += itemHtml(n);
    });
    html += '</div>';
    if (estado.hayMas) html += '<button type="button" class="bandeja__mas" data-mas>Cargar más</button>';
    cuerpo.innerHTML = html;
  }

  /** Quita un elemento con animación y repinta (para que los grupos vacíos desaparezcan). */
  function quitarDeLista(id) {
    const fila = estado.panel?.querySelector(`.bandeja__item[data-id="${id}"]`);
    estado.lista = estado.lista.filter((n) => n.id !== id);
    if (!fila) { pintarLista(); return; }
    fila.style.height = `${fila.offsetHeight}px`;
    requestAnimationFrame(() => fila.classList.add('bandeja__item--saliendo'));
    setTimeout(pintarLista, 220);
  }

  async function marcar(id, leida) {
    const n = estado.lista.find((x) => x.id === id);
    if (!n || n.leida === leida) return;
    n.leida = leida;
    pintarConteo(estado.noLeidas + (leida ? -1 : 1));
    if (leida && estado.filtro === 'no_leidas') quitarDeLista(id); else pintarLista();
    try {
      const datos = await pedir('', { accion: 'leida', id, leida });
      pintarConteo(datos.no_leidas);
    } catch (e) {
      mostrarNotificacion('error', e.message);
    }
  }

  async function alHacerClic(ev) {
    const pestana = ev.target.closest('[data-filtro]');
    if (pestana) {
      if (pestana.dataset.filtro === estado.filtro) return;
      estado.filtro = pestana.dataset.filtro;
      estado.panel.querySelectorAll('[data-filtro]').forEach((b) => b.setAttribute('aria-selected', String(b === pestana)));
      estado.lista = [];
      estado.panel.querySelector('[data-cuerpo]').innerHTML = '';
      cargar(true);
      return;
    }
    const leida = ev.target.closest('[data-leida]');
    if (leida) {
      const id = Number(leida.dataset.leida);
      marcar(id, !estado.lista.find((n) => n.id === id)?.leida);
      return;
    }
    const archivar = ev.target.closest('[data-archivar]');
    if (archivar) {
      const id = Number(archivar.dataset.archivar);
      const n = estado.lista.find((x) => x.id === id);
      if (n && !n.leida) pintarConteo(estado.noLeidas - 1);
      quitarDeLista(id);
      try {
        const datos = await pedir('', { accion: 'archivar', id });
        pintarConteo(datos.no_leidas);
      } catch (e) {
        mostrarNotificacion('error', e.message);
      }
      return;
    }
    if (ev.target.closest('[data-todas]')) {
      pintarConteo(0);
      if (estado.filtro === 'no_leidas') {
        estado.panel.querySelectorAll('.bandeja__item').forEach((f) => f.classList.add('bandeja__item--leyendo'));
        estado.lista = [];
        setTimeout(pintarLista, 200);
      } else {
        estado.lista.forEach((n) => { n.leida = true; });
        pintarLista();
      }
      try {
        await pedir('', { accion: 'todas' });
      } catch (e) {
        mostrarNotificacion('error', e.message);
      }
      return;
    }
    if (ev.target.closest('[data-mas]')) {
      ev.target.closest('[data-mas]').disabled = true;
      cargar(false);
      return;
    }
    const abrirEl = ev.target.closest('[data-abrir]');
    if (abrirEl) {
      const n = estado.lista.find((x) => x.id === Number(abrirEl.dataset.abrir));
      if (!n) return;
      if (!n.leida) {
        n.leida = true;
        pintarConteo(estado.noLeidas - 1);
        pedir('', { accion: 'leida', id: n.id, leida: true }).catch(() => {});
      }
      if (n.url) {
        cerrarPopover();
        window.location.href = n.url;
      } else if (estado.filtro === 'no_leidas') {
        quitarDeLista(n.id);
      } else {
        pintarLista();
      }
    }
  }

  boton.addEventListener('click', () => {
    if (estado.panel) cerrarPopover(); else abrir();
  });
  // Al cambiar el tamaño de la ventana la campana se mueve (o se oculta con la barra lateral): se cierra la bandeja
  window.addEventListener('resize', () => { if (estado.panel) cerrarPopover(); });

  /* ---------- Sondeo cada minuto: conteo, recordatorios y avisos de lo nuevo ---------- */
  // Id de la última notificación ya avisada en este navegador (null la primera vez: no se avisa lo anterior)
  let ultimaEnMemoria = null;
  const leerUltima = () => {
    try { const v = localStorage.getItem(CLAVE_ULTIMA); return v === null ? ultimaEnMemoria : Number(v); } catch (e) { return ultimaEnMemoria; }
  };
  const guardarUltima = (id) => {
    ultimaEnMemoria = id;
    try { localStorage.setItem(CLAVE_ULTIMA, String(id)); } catch (e) { /* sin almacenamiento: queda en memoria */ }
  };
  let ultimaRevision = 0;

  async function revisar() {
    if (document.hidden) return;
    ultimaRevision = Date.now();
    const desde = leerUltima();
    try {
      const datos = await pedir(`?conteo=1${desde !== null ? `&desde=${desde}` : ''}`);
      pintarConteo(datos.no_leidas);
      if (desde !== null && datos.ultima < desde) guardarUltima(datos.ultima);   // la base se restauró: se reinicia la marca
      // Otra pestaña pudo avisar ya: se vuelve a leer justo antes de mostrar
      const vistas = leerUltima() ?? 0;
      const nuevas = datos.nuevas.filter((n) => n.id > vistas);
      if (desde === null || datos.ultima > vistas) guardarUltima(Math.max(datos.ultima, vistas));
      if (desde !== null && nuevas.length) {
        if (nuevas.length > 3) {
          mostrarNotificacion('exito', `Tienes ${nuevas.length} notificaciones nuevas.`);
        } else {
          nuevas.slice().reverse().forEach((n) => mostrarNotificacion('exito', n.texto ? `${n.titulo} · ${n.texto}` : n.titulo));
        }
        if (estado.panel) cargar(true);
      }
    } catch (e) { /* sin conexión: se intenta en el siguiente minuto */ }
  }

  setTimeout(revisar, 1500);
  setInterval(revisar, INTERVALO);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && Date.now() - ultimaRevision > 15000) revisar();
  });
})();
