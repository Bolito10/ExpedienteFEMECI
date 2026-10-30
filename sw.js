/* ==========================================================================
   Expedientes Clínicos — service worker (alcance: toda la app, URL_BASE + '/').
   Guarda solo la estructura estática de assets/ (CSS, JS, iconos e imágenes del diseño) para que la
   interfaz abra aunque el servidor no responda. Nunca guarda páginas, respuestas de /api/ ni
   assets/subidas: los datos clínicos no se quedan en la caché del navegador.
   Se registra desde assets/js/modulos/sin_conexion.js (window.registroServiceWorker).
   ========================================================================== */
const VERSION = 'v1';
const CACHE = `femeci-estatico-${VERSION}`;
const BASE = new URL(self.registration.scope).pathname.replace(/\/$/, '');
const PAGINA_SIN_CONEXION = `${BASE}/assets/sin_conexion.html`;
const ESENCIALES = [PAGINA_SIN_CONEXION, `${BASE}/assets/css/estilos.css`, `${BASE}/assets/css/modulos/durabilidad.css`];

// Notificaciones push (otro módulo): sus manejadores viven en assets/js/push-sw.js. Si aún no existe, se sigue sin ellos.
try {
  importScripts(`${BASE}/assets/js/push-sw.js`);
} catch (e) { /* sin push por ahora */ }

self.addEventListener('install', (ev) => {
  ev.waitUntil(caches.open(CACHE).then((c) => c.addAll(ESENCIALES)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (ev) => {
  ev.waitUntil(caches.keys()
    .then((claves) => Promise.all(claves.filter((k) => k.startsWith('femeci-estatico-') && k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

const esEstatico = (url) => url.pathname.startsWith(`${BASE}/assets/`)
  && !url.pathname.startsWith(`${BASE}/assets/subidas/`)
  && /\.(css|js|svg|png|jpe?g|webp|gif|ico|woff2?)$/i.test(url.pathname);

self.addEventListener('fetch', (ev) => {
  const req = ev.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (req.mode === 'navigate') {
    ev.respondWith(navegar(req));
  } else if (esEstatico(url)) {
    ev.respondWith(estatico(req, url));
  }
});

/** Páginas: siempre de la red (no se guardan); si no hay servidor, la página "Sin conexión". */
async function navegar(req) {
  try {
    return await fetch(req);
  } catch (e) {
    const plantilla = await caches.match(PAGINA_SIN_CONEXION, { ignoreSearch: true });
    const html = plantilla ? (await plantilla.text()).replaceAll('%BASE%', BASE) : 'Sin conexión con el servidor.';
    return new Response(html, { status: 503, headers: { 'Content-Type': 'text/html; charset=UTF-8', 'Cache-Control': 'no-store' } });
  }
}

/** Estáticos: la URL lleva ?v=fecha, así que la copia exacta es válida; sin red, cualquier versión guardada. */
async function estatico(req, url) {
  const cache = await caches.open(CACHE);
  const exacta = await cache.match(req);
  if (exacta) return exacta;
  try {
    const r = await fetch(req);
    if (r.ok && r.type === 'basic') {
      // Se reemplaza la versión anterior del mismo archivo
      const viejas = await cache.keys();
      await Promise.all(viejas.filter((k) => new URL(k.url).pathname === url.pathname).map((k) => cache.delete(k)));
      await cache.put(req, r.clone());
    }
    return r;
  } catch (e) {
    const cualquiera = await cache.match(req, { ignoreSearch: true });
    return cualquiera || Response.error();
  }
}
