/* ==========================================================================
   Expedientes Clínicos — sincronización con el servidor central
   1. Envío perezoso: con conexión, cada pocos minutos (una sola pestaña a la vez)
      pide a api/sincronizacion/sincronizar.php que suba lo pendiente. El servidor
      decide si hay algo que mandar, respeta la espera entre reintentos y usa un
      candado para no correr dos veces.
   2. Sección "Sincronización" de la configuración (⚙, solo administrador):
      dirección del central, token (oculto, con botón de ver), estado y
      "Sincronizar ahora". Se guarda con el botón Guardar de la configuración.
   Usa BASE, TOKEN_CSRF y mostrarNotificacion (app.js).
   ========================================================================== */
(() => {
  if (!document.body.dataset.usuario || typeof BASE === 'undefined') return;

  const RUTA = `${BASE}/api/sincronizacion/sincronizar.php`;
  const CLAVE_ULTIMO = 'femeci-sinc-ultimo';
  const CLAVE_APAGADA = 'femeci-sinc-apagada';
  const INTERVALO = 3 * 60 * 1000;
  const ESPERA_APAGADA = 30 * 60 * 1000;

  const leer = (clave) => { try { return Number(localStorage.getItem(clave) || 0); } catch (e) { return 0; } };
  const escribir = (clave, valor) => { try { localStorage.setItem(clave, String(valor)); } catch (e) { /* sin almacenamiento */ } };
  const borrar = (clave) => { try { localStorage.removeItem(clave); } catch (e) { /* sin almacenamiento */ } };

  async function pedir(cuerpo) {
    const r = await fetch(RUTA, {
      method: cuerpo ? 'POST' : 'GET',
      headers: cuerpo ? { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF } : {},
      body: cuerpo ? JSON.stringify(cuerpo) : undefined,
    });
    let datos = {};
    try { datos = await r.json(); } catch (e) { /* sin cuerpo */ }
    if (!r.ok || datos.ok === false) throw new Error(datos.error || 'No se pudo completar la sincronización.');
    return datos;
  }

  /* --- 1. Envío perezoso cuando hay conexión --- */
  let enCurso = false;
  async function envioAutomatico() {
    if (enCurso || !navigator.onLine || document.hidden) return;
    const ahora = Date.now();
    if (ahora - leer(CLAVE_APAGADA) < ESPERA_APAGADA) return;
    if (ahora - leer(CLAVE_ULTIMO) < INTERVALO - 5000) return;   // otra pestaña acaba de hacerlo
    escribir(CLAVE_ULTIMO, ahora);
    enCurso = true;
    try {
      const r = await pedir({ accion: 'auto' });
      if (!r.configurada) escribir(CLAVE_APAGADA, Date.now());
    } catch (e) { /* sin conexión o sesión vencida: se intentará después */ } finally { enCurso = false; }
  }
  setTimeout(envioAutomatico, 8000);
  setInterval(envioAutomatico, INTERVALO);
  window.addEventListener('online', () => { borrar(CLAVE_ULTIMO); setTimeout(envioAutomatico, 3000); });

  /* --- 2. Configuración (solo administrador) --- */
  const seccion = document.querySelector('[data-sinc-ajustes]');
  if (!seccion) return;
  const form = seccion.closest('form');
  const panelAjustes = seccion.closest('[data-asistente-panel-ajustes]');
  const campoUrl = seccion.querySelector('[data-sinc-url]');
  const campoToken = seccion.querySelector('[data-sinc-token]');
  const ayudaToken = seccion.querySelector('[data-sinc-token-ayuda]');
  const listaEstado = seccion.querySelector('[data-sinc-estado]');
  const textoError = seccion.querySelector('[data-sinc-error]');
  const resumen = seccion.querySelector('[data-sinc-resumen]');
  const botonAhora = seccion.querySelector('[data-sinc-ahora]');
  const AYUDA_TOKEN = ayudaToken.textContent;
  const RESUMEN = resumen.textContent;
  let estado = null;

  const fecha = (texto) => {
    if (!texto) return 'Nunca';
    const f = new Date(texto.replace(' ', 'T'));
    const segundos = Math.round((Date.now() - f.getTime()) / 1000);
    if (segundos < 60) return 'Ahora mismo';
    if (segundos < 3600) return `Hace ${Math.floor(segundos / 60)} min`;
    if (segundos < 86400) return `Hace ${Math.floor(segundos / 3600)} h`;
    return f.toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' });
  };

  function pintar(e) {
    estado = e;
    campoUrl.value = e.url || '';
    campoToken.value = '';
    campoToken.type = 'password';
    seccion.querySelector('[data-sinc-ver]').classList.remove('activo');
    ayudaToken.textContent = e.token_final
      ? `Guardado fuera del alcance web (termina en ${e.token_final.slice(-4)}). Escribe otro solo si quieres cambiarlo.`
      : AYUDA_TOKEN;
    listaEstado.innerHTML = '';
    [['Pendientes', String(e.pendientes ?? 0)], ['Último envío', fecha(e.ultimo_envio)]].forEach(([t, v]) => {
      const div = document.createElement('div');
      div.innerHTML = '<dt></dt><dd></dd>';
      div.querySelector('dt').textContent = t;
      div.querySelector('dd').textContent = v;
      listaEstado.appendChild(div);
    });
    textoError.hidden = !e.ultimo_error;
    textoError.textContent = e.ultimo_error ? `Último error: ${e.ultimo_error}` : '';
    resumen.textContent = e.configurada
      ? (e.proximo_intento && new Date(e.proximo_intento.replace(' ', 'T')) > new Date()
        ? `Sin conexión con el central; se reintenta ${fecha(e.proximo_intento) === 'Ahora mismo' ? 'en un momento' : 'más tarde'}.`
        : RESUMEN)
      : 'Escribe la dirección del central y el token para activarla.';
    botonAhora.disabled = !e.configurada;
  }

  async function cargar() {
    try { pintar(await pedir()); } catch (e) { resumen.textContent = e.message; }
  }

  const hayCambios = () => estado && (campoUrl.value.trim().replace(/\/+$/, '') !== (estado.url || '') || campoToken.value.trim() !== '');

  async function guardar() {
    const r = await pedir({ accion: 'guardar', url: campoUrl.value.trim(), token: campoToken.value.trim() });
    borrar(CLAVE_APAGADA);
    borrar(CLAVE_ULTIMO);
    pintar(r);
    return r;
  }

  // Al abrir la configuración se lee el estado actual
  if (panelAjustes) {
    new MutationObserver(() => { if (!panelAjustes.hidden) cargar(); })
      .observe(panelAjustes, { attributes: true, attributeFilter: ['hidden'] });
    if (!panelAjustes.hidden) cargar();
  } else {
    cargar();
  }

  // El botón Guardar de la configuración también guarda esta sección (si cambió algo)
  form?.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (!hayCambios()) return;
    try {
      await guardar();
      mostrarNotificacion('exito', 'Sincronización guardada. El token quedó fuera del alcance web.');
    } catch (e) { mostrarNotificacion('error', e.message); }
  });
  campoUrl.addEventListener('input', () => { botonAhora.disabled = !campoUrl.value.trim(); });
  campoToken.addEventListener('input', () => { if (campoUrl.value.trim()) botonAhora.disabled = false; });

  seccion.querySelector('[data-sinc-ver]').addEventListener('click', (ev) => {
    const ver = campoToken.type === 'password';
    campoToken.type = ver ? 'text' : 'password';
    ev.currentTarget.classList.toggle('activo', ver);
  });
  seccion.querySelector('[data-sinc-generar]').addEventListener('click', () => {
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    campoToken.value = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    campoToken.type = 'text';
    seccion.querySelector('[data-sinc-ver]').classList.add('activo');
    ayudaToken.textContent = 'Token nuevo: cópialo en el servidor central y en cada consultorio. Se guarda al pulsar Guardar.';
    if (campoUrl.value.trim()) botonAhora.disabled = false;
  });

  botonAhora.addEventListener('click', async () => {
    botonAhora.disabled = true;
    botonAhora.textContent = 'Sincronizando…';
    try {
      if (hayCambios()) await guardar();
      const r = await pedir({ accion: 'ahora' });
      pintar(r);
      const res = r.resultado || {};
      if (res.ocupada) mostrarNotificacion('exito', 'Ya hay una sincronización en curso.');
      else if (res.ok === false) mostrarNotificacion('error', res.error || 'No se pudo sincronizar.');
      else mostrarNotificacion(res.rechazados ? 'error' : 'exito',
        `Sincronizado: ${res.enviados} enviados, ${res.recibidos} recibidos${res.rechazados ? `, ${res.rechazados} con error` : ''}.`);
    } catch (e) {
      mostrarNotificacion('error', e.message);
      cargar();
    } finally {
      botonAhora.textContent = 'Sincronizar ahora';
      botonAhora.disabled = !estado?.configurada;
    }
  });
})();
