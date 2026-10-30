/* ==========================================================================
   Sin conexión: service worker, indicador discreto y cola de cambios pendientes.

   - Registra sw.js (alcance: toda la app) y deja la promesa en window.registroServiceWorker
     para que otros módulos (notificaciones push) usen el mismo registro.
   - Indicador "Sin conexión" en la barra superior (componente .estado), con los cambios pendientes.
   - Cada POST JSON a /api/ lleva X-Idempotencia (app/nucleo/idempotencia.php): si se repite, el
     servidor devuelve la respuesta guardada y no escribe dos veces.
   - Si la red falla, los cambios que se pueden repetir sin riesgo (lista COLA) se guardan en
     IndexedDB y se envían al volver la conexión, con la sesión y el token CSRF de ese momento.
     Lo demás no se encola: se avisa que no se guardó y lo escrito sigue en pantalla.
   Usa BASE, TOKEN_CSRF y mostrarNotificacion de app.js.
   ========================================================================== */
(() => {
  const USUARIO = Number(document.body.dataset.usuario || 0);
  const RAIZ_API = `${BASE}/api/`;
  const fetchOriginal = window.fetch.bind(window);

  /* ---------- Service worker (necesita contexto seguro: localhost o HTTPS) ---------- */
  if ('serviceWorker' in navigator && window.isSecureContext) {
    window.registroServiceWorker = navigator.serviceWorker
      .register(`${BASE}/sw.js`, { scope: `${BASE}/`, updateViaCache: 'none' })
      .catch((e) => { console.warn('Service worker no registrado:', e); return null; });
  } else {
    window.registroServiceWorker = Promise.resolve(null);
  }

  /* ---------- Qué se encola: cambios puntuales que dan lo mismo aplicados una vez o al final ---------- */
  // Devuelve la clave de fusión (el último valor reemplaza a los anteriores) o null si no se encola
  const COLA = {
    'pacientes/actualizar.php': (c) => `paciente:${c.id}:${c.campo}`,
    'paginas/base_datos.php': (c) => (c.accion === 'celda' ? `bd:${c.id}:${c.fila}:${c.columna}` : null),
    'paginas/paginas.php': (c) => (['renombrar', 'favorita'].includes(c.accion) ? `pagina:${c.id}:${c.accion}` : null),
    'agenda/citas.php': (c) => (['mover', 'actualizar', 'estado'].includes(c.accion) ? `cita:${c.id}:${c.accion}` : null),
    'notificaciones.php': (c) => (['leida', 'todas', 'archivar'].includes(c.accion) ? `aviso:${c.accion}:${c.id ?? ''}` : null),
  };
  // Sin clave de idempotencia: transmisión en vivo, archivos grandes, respaldos y sincronización (tienen su propio control)
  const SIN_CLAVE = ['asistente/conversar.php', 'presencia.php', 'datos/', 'configuracion/respaldos.php', 'sincronizacion/'];

  const MENSAJE_EN_COLA = 'Sin conexión. El cambio quedó guardado en este equipo y se enviará al volver la conexión.';
  const MENSAJE_SIN_CONEXION = 'Sin conexión con el servidor: no se guardó. Lo escrito sigue en pantalla; vuelve a intentarlo cuando regrese la conexión.';

  const nuevaClave = () => {
    if (crypto.randomUUID) return crypto.randomUUID();
    const b = crypto.getRandomValues(new Uint8Array(16));   // HTTP en la red local: randomUUID no existe
    b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
    const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
  };

  /* ---------- IndexedDB ---------- */
  let bdPromesa = null;
  const bd = () => {
    bdPromesa ??= new Promise((resolver, rechazar) => {
      const abrir = indexedDB.open('femeci-sin-conexion', 1);
      abrir.onupgradeneeded = () => abrir.result.createObjectStore('cola', { keyPath: 'clave' });
      abrir.onsuccess = () => resolver(abrir.result);
      abrir.onerror = () => rechazar(abrir.error);
    });
    return bdPromesa;
  };
  const almacen = async (modo) => (await bd()).transaction('cola', modo).objectStore('cola');
  const pedido = (p) => new Promise((resolver, rechazar) => { p.onsuccess = () => resolver(p.result); p.onerror = () => rechazar(p.error); });
  const pendientes = async () => {
    try {
      return (await pedido((await almacen('readonly')).getAll()))
        .filter((p) => p.usuario === USUARIO)
        .sort((a, b) => a.creado - b.creado);
    } catch (e) { return []; }
  };
  const quitar = async (clave) => pedido((await almacen('readwrite')).delete(clave));
  const encolar = async (entrada) => {
    const anteriores = (await pendientes()).filter((p) => p.fusion === entrada.fusion);
    const tienda = await almacen('readwrite');
    anteriores.forEach((p) => tienda.delete(p.clave));
    await pedido(tienda.put(entrada));
  };

  /* ---------- Indicador ---------- */
  let enLinea = navigator.onLine;
  let sesionVencida = false;
  let cuenta = 0;
  const indicador = document.createElement('span');
  indicador.className = 'estado estado--pendiente conexion';
  indicador.setAttribute('role', 'status');
  indicador.hidden = true;
  indicador.innerHTML = '<i aria-hidden="true"></i><span></span>';
  document.querySelector('.barra-superior__derecha')?.prepend(indicador);

  const pintarIndicador = () => {
    const cambios = cuenta === 1 ? '1 cambio pendiente' : `${cuenta} cambios pendientes`;
    let texto = '';
    if (!enLinea) texto = cuenta ? `Sin conexión · ${cambios}` : 'Sin conexión';
    else if (cuenta) texto = sesionVencida ? `${cambios} · inicia sesión para enviarlos` : cambios;
    indicador.hidden = !texto;
    indicador.querySelector('span').textContent = texto;
    indicador.title = !enLinea ? 'El servidor no responde. Lo que se pueda guardar en este equipo se enviará al volver la conexión.' : '';
  };
  const contar = async () => { cuenta = (await pendientes()).length; pintarIndicador(); };

  let revision = null;
  const marcarConexion = (ok) => {
    const antes = enLinea;
    enLinea = ok;
    pintarIndicador();
    if (!ok) {
      // Mientras no hay respuesta, se pregunta cada 15 s con una petición mínima (sin tocar la sesión)
      revision ??= setInterval(async () => {
        try {
          const r = await fetchOriginal(`${BASE}/sw.js`, { method: 'HEAD', cache: 'no-store' });
          if (r.ok) marcarConexion(true);
        } catch (e) { /* sigue sin conexión */ }
      }, 15000);
    } else {
      clearInterval(revision);
      revision = null;
      if (!antes) {
        document.dispatchEvent(new CustomEvent('femeci:conexion', { detail: { enLinea: true } }));
        vaciarCola();
      }
    }
  };
  window.addEventListener('online', () => marcarConexion(true));
  window.addEventListener('offline', () => marcarConexion(false));

  /* ---------- fetch con clave de idempotencia y cola ---------- */
  const rutaApi = (url) => {
    const u = new URL(url, location.href);
    return u.origin === location.origin && u.pathname.startsWith(RAIZ_API) ? u.pathname.slice(RAIZ_API.length) : null;
  };

  window.fetch = async function (recurso, opciones = {}) {
    const url = typeof recurso === 'string' || recurso instanceof URL ? String(recurso) : recurso.url;
    const metodo = String(opciones.method || recurso.method || 'GET').toUpperCase();
    const ruta = rutaApi(url);
    const cabeceras = new Headers(opciones.headers || (recurso instanceof Request ? recurso.headers : undefined));
    const esJson = metodo === 'POST' && ruta !== null && typeof opciones.body === 'string'
      && (cabeceras.get('Content-Type') || '').includes('application/json')
      && !SIN_CLAVE.some((s) => ruta.startsWith(s));

    if (!esJson) {
      try {
        const r = await fetchOriginal(recurso, opciones);
        if (ruta !== null && !enLinea) marcarConexion(true);
        return r;
      } catch (e) {
        if (e.name !== 'AbortError' && ruta !== null) marcarConexion(false);
        throw e;
      }
    }

    const clave = cabeceras.get('X-Idempotencia') || nuevaClave();
    cabeceras.set('X-Idempotencia', clave);
    try {
      const r = await fetchOriginal(recurso, { ...opciones, headers: cabeceras });
      if (!enLinea) marcarConexion(true);
      else if (cuenta) vaciarCola();
      return r;
    } catch (e) {
      if (e.name === 'AbortError') throw e;
      marcarConexion(false);
      let cuerpo = null;
      try { cuerpo = JSON.parse(opciones.body); } catch (x) { /* no es JSON */ }
      const reglaRuta = Object.keys(COLA).find((r) => ruta === r);
      const fusion = reglaRuta && cuerpo ? COLA[reglaRuta](cuerpo) : null;
      if (fusion && USUARIO) {
        try {
          await encolar({ clave, usuario: USUARIO, url, cuerpo: opciones.body, fusion, creado: Date.now(), pagina: location.pathname });
          await contar();
          const error = new Error(MENSAJE_EN_COLA);
          error.enCola = true;
          throw error;
        } catch (x) {
          if (x.enCola) throw x;   // sin IndexedDB: se avisa como cualquier otro
        }
      }
      const error = new Error(MENSAJE_SIN_CONEXION);
      error.sinConexion = true;
      throw error;
    }
  };

  /* ---------- Envío de la cola ---------- */
  let vaciando = false;
  async function vaciarCola() {
    if (vaciando || !USUARIO) return;
    vaciando = true;
    const enviar = async () => {
      let enviados = 0;
      const rechazados = [];
      for (const p of await pendientes()) {
        let r;
        try {
          r = await fetchOriginal(p.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF, 'X-Idempotencia': p.clave },
            body: p.cuerpo,
          });
        } catch (e) { marcarConexion(false); break; }
        if (!enLinea) marcarConexion(true);
        let res = {};
        try { res = await r.json(); } catch (e) { /* sin cuerpo */ }
        if (r.ok && res.ok !== false) {
          await quitar(p.clave);
          enviados++;
          sesionVencida = false;
          document.dispatchEvent(new CustomEvent('femeci:cola-enviada', { detail: { url: p.url, cuerpo: JSON.parse(p.cuerpo), respuesta: res } }));
          continue;
        }
        // Sesión terminada o abierta de nuevo en otra página: se queda en la cola para la próxima sesión
        if (r.status === 401 || (r.status === 403 && res.motivo === 'csrf')) { sesionVencida = true; break; }
        // Aún se procesa en otra pestaña, límite de intentos o error del servidor: se reintenta después
        if ((r.status === 409 && res.motivo === 'en_proceso') || r.status === 429 || r.status >= 500) break;
        // El servidor lo rechazó (validación, permiso, conflicto o no se pudo confirmar): se avisa y se quita
        await quitar(p.clave);
        rechazados.push(res.error || `El servidor respondió ${r.status}.`);
      }
      await contar();
      if (enviados) mostrarNotificacion('exito', enviados === 1 ? 'Se envió 1 cambio que estaba pendiente. Recarga para verlo.' : `Se enviaron ${enviados} cambios que estaban pendientes. Recarga para verlos.`);
      rechazados.slice(0, 3).forEach((m) => mostrarNotificacion('error', `No se aplicó un cambio pendiente: ${m}`));
    };
    try {
      // Una sola pestaña envía a la vez (las demás esperan su turno o lo omiten)
      if (navigator.locks) await navigator.locks.request('femeci-cola', { ifAvailable: true }, (candado) => (candado ? enviar() : null));
      else await enviar();
    } finally {
      vaciando = false;
    }
  }

  setInterval(() => { if (cuenta && enLinea) vaciarCola(); }, 30000);
  contar().then(() => { if (cuenta && enLinea) vaciarCola(); });
  pintarIndicador();

  /* ---------- Imágenes de internet en el chat: sin conexión, un texto breve en lugar de la imagen rota ---------- */
  document.addEventListener('error', (ev) => {
    const img = ev.target;
    if (!(img instanceof HTMLImageElement) || !img.src.includes('/api/asistente/imagen.php')) return;
    const aviso = document.createElement('span');
    aviso.className = 'md__pie';
    aviso.textContent = 'Imagen de internet no disponible (sin conexión o el sitio no respondió).';
    (img.closest('a') || img).replaceWith(aviso);
  }, true);
})();
