/* ==========================================================================
   Expedientes Clínicos — notificaciones push dentro del service worker.
   Lo carga sw.js con importScripts(`${BASE}/assets/js/push-sw.js`); no se registra por sí solo.

   'push':              muestra la notificación con el icono de la app. El servidor solo manda textos
                        genéricos (nunca nombres de pacientes ni datos clínicos: se ven en la pantalla
                        bloqueada) y una ruta interna; aun así, aquí se acotan los tamaños.
   'notificationclick': abre o enfoca la ruta interna, solo si es del mismo origen y dentro del alcance
                        del service worker; cualquier otra cosa abre la raíz de la app.
   ========================================================================== */
(() => {
  const ALCANCE = new URL(self.registration.scope);
  const RAIZ = ALCANCE.pathname.replace(/\/$/, '');
  const ICONO = `${RAIZ}/assets/img/icono-notificacion.svg`;
  const INSIGNIA = `${RAIZ}/assets/img/insignia-notificacion.svg`;
  const TITULO = 'Expedientes Clínicos';

  /** URL interna segura: mismo origen, dentro del alcance, solo http(s). Si no, la raíz de la app. */
  function urlInterna(valor) {
    try {
      if (typeof valor !== 'string' || valor.length > 600) throw new Error('url');
      const url = new URL(valor, self.location.origin);
      if (url.origin !== self.location.origin || !/^https?:$/.test(url.protocol)) throw new Error('origen');
      if (!url.pathname.startsWith(ALCANCE.pathname) && url.pathname !== RAIZ) throw new Error('alcance');
      url.hash = '';
      return url.href;
    } catch (e) {
      return ALCANCE.href;
    }
  }

  const texto = (v, max) => (typeof v === 'string' ? v.replace(/[\u0000-\u001f\u007f]/g, ' ').trim().slice(0, max) : '');

  self.addEventListener('push', (ev) => {
    let datos = {};
    try { datos = ev.data ? ev.data.json() : {}; } catch (e) { datos = {}; }
    if (!datos || typeof datos !== 'object') datos = {};
    const etiqueta = /^[A-Za-z0-9_-]{1,32}$/.test(datos.etiqueta || '') ? datos.etiqueta : undefined;
    // userVisibleOnly: cada push muestra una notificación (aunque llegue vacío o malformado)
    ev.waitUntil(self.registration.showNotification(texto(datos.titulo, 60) || TITULO, {
      body: texto(datos.cuerpo, 160) || 'Tienes un aviso nuevo',
      icon: ICONO,
      badge: INSIGNIA,
      lang: 'es-MX',
      tag: etiqueta,
      renotify: !!etiqueta,
      timestamp: Date.now(),
      data: { url: urlInterna(datos.url) },
    }));
  });

  self.addEventListener('notificationclick', (ev) => {
    ev.notification.close();
    const destino = urlInterna(ev.notification.data && ev.notification.data.url);
    ev.waitUntil((async () => {
      const ventanas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      const propias = ventanas.filter((c) => {
        try { return new URL(c.url).origin === self.location.origin; } catch (e) { return false; }
      });
      const exacta = propias.find((c) => c.url === destino);
      if (exacta) return exacta.focus();
      const otra = propias.find((c) => 'navigate' in c);
      if (otra) {
        try {
          const navegada = await otra.navigate(destino);
          if (navegada) return navegada.focus();
        } catch (e) { /* la ventana no la controla este service worker: se abre una nueva */ }
      }
      return self.clients.openWindow(destino);
    })());
  });
})();
