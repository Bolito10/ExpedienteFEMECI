/* ==========================================================================
   Expedientes Clínicos — notificaciones push (ajustes y suscripción de este dispositivo)
   El permiso del navegador se pide SOLO cuando el usuario enciende "En este dispositivo" (gesto explícito);
   nunca al cargar la página. Los ajustes se abren con el engrane de la bandeja de notificaciones
   (data-push-ajustes-abrir) o se montan en la página en un contenedor [data-push-ajustes-pagina] (portal).
   Reutiliza el registro del service worker de la app (window.registroServiceWorker o
   navigator.serviceWorker.ready); los manejadores 'push' y 'notificationclick' viven en assets/js/push-sw.js.
   Autocontenido: el portal del paciente no carga app.js.
   ========================================================================== */
(() => {
  if (document.body.dataset.base === undefined) return;
  const RAIZ = document.body.dataset.base || '';
  const API = `${RAIZ}/api/push.php`;
  const csrf = () => document.querySelector('meta[name="csrf"]')?.content || '';
  const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const svg = (trazo) => `<svg class="icono" viewBox="0 0 24 24" aria-hidden="true">${trazo}</svg>`;
  const ICONOS = {
    engrane: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    atras: '<path d="m15 18-6-6 6-6"/>',
    menos: '<path d="M5 12h14"/>',
    agregar: '<path d="M12 5v14M5 12h14"/>',
    reloj: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  };

  /* ---------- Avisos breves: los de app.js si existen; si no (portal), el texto de estado ---------- */
  function avisar(tipo, texto, caja) {
    if (typeof mostrarNotificacion === 'function') { mostrarNotificacion(tipo, texto); return; }
    const estado = caja?.querySelector('[data-push-mensaje]');
    if (estado) {
      estado.textContent = texto;
      estado.className = `ajustes-ia__prueba ${tipo === 'error' ? 'error' : 'ok'}`;
    }
  }

  async function pedir(cuerpo) {
    const r = await fetch(API, {
      method: cuerpo ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: cuerpo ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() } : {},
      body: cuerpo ? JSON.stringify(cuerpo) : undefined,
    });
    let datos = {};
    try { datos = await r.json(); } catch (e) { /* sin cuerpo */ }
    if (!r.ok || datos.ok === false) throw new Error(datos.error || 'No se pudo completar la acción.');
    return datos;
  }

  /* ---------- Capacidad del navegador ---------- */
  const esIos = /iPhone|iPad|iPod/.test(navigator.userAgent);
  const instalada = window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true;

  /** null si se puede usar push aquí; si no, el motivo para mostrarlo. */
  function motivoNoDisponible() {
    if (!window.isSecureContext) return 'Requiere una conexión segura (HTTPS).';
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      return esIos && !instalada
        ? 'En iPhone y iPad, agrega la app a la pantalla de inicio para recibir notificaciones.'
        : 'Este navegador no admite notificaciones push.';
    }
    if (Notification.permission === 'denied') return 'Las bloqueaste en este navegador. Permítelas en la configuración del sitio para activarlas.';
    return null;
  }

  /** Registro del service worker de la app (lo crea otro módulo); espera hasta 10 s a que esté listo. */
  async function registro() {
    if (window.registroServiceWorker) {
      const r = await Promise.resolve(window.registroServiceWorker).catch(() => null);
      if (r && r.pushManager) return r;
    }
    return Promise.race([
      navigator.serviceWorker.ready,
      new Promise((_, no) => setTimeout(() => no(new Error('El servicio de notificaciones aún no está listo. Recarga la página e inténtalo de nuevo.')), 10000)),
    ]);
  }

  async function suscripcionLocal() {
    try {
      const r = await navigator.serviceWorker.getRegistration(`${RAIZ}/`);
      return r ? await r.pushManager.getSubscription() : null;
    } catch (e) { return null; }
  }

  function bytesDeB64u(texto) {
    const b64 = texto.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (texto.length % 4)) % 4);
    return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
  }

  /* ---------- Vista de ajustes ---------- */
  const fechaCorta = new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'short', year: 'numeric' });

  function filaInterruptor(nombre, titulo, detalle, marcado, extra = '') {
    return `
      <label class="ajustes-ia__fila ajustes-ia__fila--interruptor"${extra}>
        <div class="ajustes-ia__etiqueta"><b>${esc(titulo)}</b>${detalle ? `<small>${esc(detalle)}</small>` : ''}</div>
        <input type="checkbox" name="${nombre}"${marcado ? ' checked' : ''}><i class="interruptor" aria-hidden="true"></i>
      </label>`;
  }

  function filaContador(nombre, titulo, valor, maximo, oculta) {
    return `
      <div class="ajustes-ia__fila push-ajustes__sub" data-push-sub="${nombre}"${oculta ? ' hidden' : ''}>
        <div class="ajustes-ia__etiqueta"><small>${esc(titulo)}</small></div>
        <div class="contador-ia" data-push-contador="${nombre}" data-maximo="${maximo}">
          <button type="button" data-paso="-1" aria-label="Menos">${svg(ICONOS.menos)}</button>
          <input name="${nombre}" inputmode="numeric" value="${valor}" aria-label="${esc(titulo)}">
          <button type="button" data-paso="1" aria-label="Más">${svg(ICONOS.agregar)}</button>
        </div>
      </div>`;
  }

  function dispositivosHtml(lista) {
    if (!lista.length) return '<p class="push-ajustes__nota">Ningún dispositivo recibe notificaciones todavía.</p>';
    return lista.map((d) => {
      const desde = `desde ${fechaCorta.format(new Date(d.creada))}`;
      return `
        <div class="ajustes-ia__fila">
          <div class="ajustes-ia__etiqueta"><b>${esc(d.agente)}</b><small>${d.este ? 'Este dispositivo · ' : ''}${esc(desde)}</small></div>
          ${d.este ? '' : `<button type="button" class="boton boton--sutil" data-push-quitar="${d.id}">Quitar</button>`}
        </div>`;
    }).join('');
  }

  function vistaHtml(e, enPagina) {
    const p = e.preferencias;
    const d = e.disponibles;
    const umbrales = d.umbral_citas || d.umbral_pacientes || d.signos_fuera_rango;
    return `
      ${enPagina ? '' : `
      <header class="bandeja__cabecera">
        <button type="button" class="boton-icono" data-push-volver title="Volver a las notificaciones">${svg(ICONOS.atras)}</button>
        <span class="bandeja__titulo">Notificaciones push</span>
      </header>`}
      <div class="push-ajustes__cuerpo">
        <label class="ajustes-ia__fila ajustes-ia__fila--interruptor">
          <div class="ajustes-ia__etiqueta"><b>En este dispositivo</b><small data-push-estado>Avisos en el teléfono o la computadora aunque la app esté cerrada. Solo muestran textos generales, sin datos de pacientes.</small></div>
          <input type="checkbox" data-push-activar><i class="interruptor" aria-hidden="true"></i>
        </label>

        <h3 class="ajustes-ia__seccion">Qué avisos recibir</h3>
        ${filaInterruptor('cita_recordatorio', 'Recordatorio de cita', '', p.cita_recordatorio)}
        <div class="ajustes-ia__fila ajustes-ia__fila--apilada push-ajustes__sub" data-push-sub="cita_recordatorio"${p.cita_recordatorio ? '' : ' hidden'}>
          <div class="ajustes-ia__etiqueta"><small>Anticipación</small></div>
          <div class="segmentado" role="radiogroup" aria-label="Anticipación del recordatorio" data-push-anticipacion>
            ${e.anticipaciones.map((a) => `<button type="button" data-valor="${a.valor}" aria-pressed="${a.valor === p.cita_anticipacion}">${esc(a.valor === 0 ? 'Cada cita' : a.texto)}</button>`).join('')}
          </div>
        </div>
        ${d.invitacion_aceptada ? filaInterruptor('invitacion_aceptada', 'Invitación aceptada', '', p.invitacion_aceptada) : ''}
        ${d.pagina_compartida ? filaInterruptor('pagina_compartida', 'Página compartida', '', p.pagina_compartida) : ''}
        ${d.aviso ? filaInterruptor('aviso', 'Aviso del equipo', '', p.aviso) : ''}

        ${umbrales ? '<h3 class="ajustes-ia__seccion">Avisos por umbral</h3>' : ''}
        ${d.umbral_citas ? filaInterruptor('umbral_citas_activo', 'Citas pendientes hoy', 'Una vez al día, al llegar a la cantidad.', p.umbral_citas_activo)
          + filaContador('umbral_citas_n', 'Cantidad de citas', p.umbral_citas_n, e.maximos.citas, !p.umbral_citas_activo) : ''}
        ${d.umbral_pacientes ? filaInterruptor('umbral_pacientes_activo', 'Pacientes nuevos', 'Cada vez que se registren esa cantidad en el día.', p.umbral_pacientes_activo)
          + filaContador('umbral_pacientes_n', 'Cantidad de pacientes', p.umbral_pacientes_n, e.maximos.pacientes, !p.umbral_pacientes_activo) : ''}
        ${d.signos_fuera_rango ? filaInterruptor('signos_fuera_rango', 'Signo vital fuera de rango', 'TA, FC, FR, temperatura, SpO₂ o glucosa fuera del rango de referencia del adulto.', p.signos_fuera_rango) : ''}

        <h3 class="ajustes-ia__seccion">Horario de silencio</h3>
        ${filaInterruptor('silencio_activo', 'Silenciar por horario', 'En ese horario no llegan avisos push; siguen en la bandeja.', p.silencio_activo)}
        <div class="ajustes-ia__fila push-ajustes__sub" data-push-sub="silencio_activo"${p.silencio_activo ? '' : ' hidden'}>
          <div class="ajustes-ia__etiqueta"><small>De</small></div>
          <div class="push-ajustes__horas">
            <span class="campo-ia">${svg(ICONOS.reloj)}<input type="time" name="silencio_inicio" value="${esc(p.silencio_inicio)}" aria-label="Inicio del silencio"></span>
            <small>a</small>
            <span class="campo-ia">${svg(ICONOS.reloj)}<input type="time" name="silencio_fin" value="${esc(p.silencio_fin)}" aria-label="Fin del silencio"></span>
          </div>
        </div>

        <h3 class="ajustes-ia__seccion">Dispositivos</h3>
        <div data-push-dispositivos>${dispositivosHtml(e.dispositivos)}</div>

        <div class="push-ajustes__pie">
          <span class="ajustes-ia__prueba" data-push-mensaje></span>
          <button type="button" class="boton boton--sutil" data-push-probar${e.dispositivos.length ? '' : ' disabled'}>Enviar prueba</button>
        </div>
      </div>`;
  }

  /** Monta los ajustes en `caja` (overlay de la bandeja o contenedor de una página). */
  async function montar(caja, enPagina) {
    caja.innerHTML = '<p class="bandeja__cargando">Cargando…</p>';
    let estado;
    try {
      estado = await pedir();
    } catch (e) {
      caja.innerHTML = `${enPagina ? '' : `<header class="bandeja__cabecera"><button type="button" class="boton-icono" data-push-volver title="Volver">${svg(ICONOS.atras)}</button><span class="bandeja__titulo">Notificaciones push</span></header>`}<p class="bandeja__cargando">${esc(e.message)}</p>`;
      return;
    }
    caja.innerHTML = vistaHtml(estado, enPagina);
    const activar = caja.querySelector('[data-push-activar]');
    const textoEstado = caja.querySelector('[data-push-estado]');
    const textoBase = textoEstado.textContent;
    const motivo = motivoNoDisponible();
    let ocupado = false;

    const pintarDispositivos = (lista) => {
      caja.querySelector('[data-push-dispositivos]').innerHTML = dispositivosHtml(lista);
      caja.querySelector('[data-push-probar]').disabled = !lista.length;
    };

    if (motivo) {
      activar.disabled = true;
      textoEstado.textContent = motivo;
    } else {
      // ¿La suscripción que ya tiene este navegador es de esta cuenta? (no pide permiso ni suscribe)
      const local = await suscripcionLocal();
      if (local) {
        try {
          const r = await pedir({ accion: 'estado', endpoint: local.endpoint });
          activar.checked = r.propia && Notification.permission === 'granted';
        } catch (e) { /* se queda apagado */ }
      }
    }

    /* Encender: el permiso se pide aquí, dentro del gesto del usuario, antes de cualquier otra espera */
    activar.addEventListener('change', async () => {
      if (ocupado) { activar.checked = !activar.checked; return; }
      ocupado = true;
      const encender = activar.checked;
      try {
        if (encender) {
          const permiso = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
          if (permiso !== 'granted') {
            activar.checked = false;
            if (permiso === 'denied') { activar.disabled = true; textoEstado.textContent = motivoNoDisponible(); }
            return;
          }
          textoEstado.textContent = 'Activando…';
          const reg = await registro();
          const llave = bytesDeB64u(estado.llave);
          let sub = await reg.pushManager.getSubscription();
          if (sub && sub.options?.applicationServerKey) {
            const actual = new Uint8Array(sub.options.applicationServerKey);
            if (actual.length !== llave.length || actual.some((b, i) => b !== llave[i])) { await sub.unsubscribe(); sub = null; }
          }
          sub = sub || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: llave });
          const r = await pedir({ accion: 'suscribir', suscripcion: sub.toJSON() });
          estado = r;
          pintarDispositivos(r.dispositivos);
          avisar('exito', 'Notificaciones activadas en este dispositivo.', caja);
        } else {
          const sub = await suscripcionLocal();
          if (sub) {
            const r = await pedir({ accion: 'desuscribir', endpoint: sub.endpoint });
            await sub.unsubscribe().catch(() => {});
            pintarDispositivos(r.dispositivos);
          }
          avisar('exito', 'Notificaciones desactivadas en este dispositivo.', caja);
        }
      } catch (e) {
        activar.checked = !encender;
        avisar('error', e.message || 'No se pudieron cambiar las notificaciones.', caja);
      } finally {
        ocupado = false;
        if (!activar.disabled) textoEstado.textContent = textoBase;
      }
    });

    /* Preferencias: se guardan solas al cambiar (sin botón Guardar) */
    let espera = null;
    const leer = () => {
      const datos = { accion: 'preferencias' };
      caja.querySelectorAll('input[type="checkbox"][name]').forEach((i) => { datos[i.name] = i.checked; });
      caja.querySelectorAll('[data-push-contador] input, input[type="time"]').forEach((i) => { datos[i.name] = i.value; });
      const marcado = caja.querySelector('[data-push-anticipacion] [aria-pressed="true"]');
      if (marcado) datos.cita_anticipacion = Number(marcado.dataset.valor);
      return datos;
    };
    const guardar = () => {
      clearTimeout(espera);
      espera = setTimeout(async () => {
        try {
          const r = await pedir(leer());
          estado.preferencias = r.preferencias;
          caja.querySelectorAll('[data-push-contador] input').forEach((i) => { i.value = r.preferencias[i.name]; });
        } catch (e) {
          avisar('error', e.message, caja);
        }
      }, 450);
    };

    caja.addEventListener('change', (ev) => {
      const campo = ev.target;
      if (!campo.name || campo === activar) return;
      const sub = caja.querySelector(`[data-push-sub="${campo.name}"]`);
      if (sub && campo.type === 'checkbox') sub.hidden = !campo.checked;
      guardar();
    });

    caja.addEventListener('click', async (ev) => {
      const opcion = ev.target.closest('[data-push-anticipacion] button');
      if (opcion) {
        caja.querySelectorAll('[data-push-anticipacion] button').forEach((b) => b.setAttribute('aria-pressed', String(b === opcion)));
        guardar();
        return;
      }
      const paso = ev.target.closest('[data-push-contador] [data-paso]');
      if (paso) {
        const contador = paso.closest('[data-push-contador]');
        const entrada = contador.querySelector('input');
        const valor = Math.max(1, Math.min(Number(contador.dataset.maximo), (parseInt(entrada.value, 10) || 1) + Number(paso.dataset.paso)));
        entrada.value = valor;
        guardar();
        return;
      }
      const quitar = ev.target.closest('[data-push-quitar]');
      if (quitar) {
        quitar.disabled = true;
        try {
          const r = await pedir({ accion: 'quitar', id: Number(quitar.dataset.pushQuitar) });
          pintarDispositivos(r.dispositivos);
        } catch (e) {
          quitar.disabled = false;
          avisar('error', e.message, caja);
        }
        return;
      }
      const probar = ev.target.closest('[data-push-probar]');
      if (probar) {
        probar.disabled = true;
        try {
          const r = await pedir({ accion: 'probar' });
          avisar('exito', r.dispositivos === 1 ? 'Se envió una notificación de prueba.' : `Se envió una notificación de prueba a ${r.dispositivos} dispositivos.`, caja);
        } catch (e) {
          avisar('error', e.message, caja);
        } finally {
          setTimeout(() => { probar.disabled = false; }, 3000);
        }
      }
    });
  }

  /* ---------- Engrane de la bandeja: los ajustes se abren encima de la lista ---------- */
  document.addEventListener('click', (ev) => {
    const abrir = ev.target.closest('[data-push-ajustes-abrir]');
    if (abrir) {
      const bandeja = abrir.closest('.bandeja');
      if (!bandeja || bandeja.querySelector('.push-ajustes')) return;
      const capa = document.createElement('div');
      capa.className = 'push-ajustes';
      capa.setAttribute('role', 'region');
      capa.setAttribute('aria-label', 'Notificaciones push');
      bandeja.appendChild(capa);
      montar(capa, false).then(() => capa.querySelector('[data-push-volver]')?.focus({ preventScroll: true }));
      return;
    }
    const volver = ev.target.closest('[data-push-volver]');
    if (volver) {
      const capa = volver.closest('.push-ajustes');
      const bandeja = capa?.closest('.bandeja');
      capa?.remove();
      bandeja?.querySelector('[data-push-ajustes-abrir]')?.focus({ preventScroll: true });
    }
  });

  /* ---------- Ajustes dentro de una página (portal del paciente) ---------- */
  document.querySelectorAll('[data-push-ajustes-pagina]').forEach((caja) => {
    caja.classList.add('push-ajustes', 'push-ajustes--pagina');
    montar(caja, true);
  });

  /* ---------- Al cerrar sesión se cancela la suscripción de este dispositivo ---------- */
  document.addEventListener('submit', async (ev) => {
    const form = ev.target;
    if (!(form instanceof HTMLFormElement) || !form.action.endsWith('/acceso/salir.php') || form.dataset.pushListo) return;
    if (!('serviceWorker' in navigator)) return;
    ev.preventDefault();
    form.dataset.pushListo = '1';
    try {
      const sub = await Promise.race([suscripcionLocal(), new Promise((r) => setTimeout(() => r(null), 1500))]);
      if (sub) await Promise.race([sub.unsubscribe(), new Promise((r) => setTimeout(r, 1500))]);
    } catch (e) { /* el servidor da de baja la suscripción de la sesión de todos modos */ }
    form.submit();
  }, true);
})();
