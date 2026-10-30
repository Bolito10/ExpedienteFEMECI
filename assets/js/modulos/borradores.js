/* ==========================================================================
   Borradores de formularios largos en este navegador (localStorage).
   Cualquier <form data-borrador="clave"> se guarda mientras se escribe; si la página se cierra, se va
   la luz o falla el envío, al volver se restaura con un aviso discreto (con opción de descartarlo).
   Se borra cuando el envío se guardó: la siguiente página ya no tiene el formulario y muestra el aviso
   de éxito del servidor. Nunca se guardan el token CSRF, la clave de envío, contraseñas, archivos ni
   las casillas obligatorias (constancias); un campo se excluye también con data-borrador-omitir.
   Usa escaparHtml y mostrarNotificacion de app.js.
   ========================================================================== */
(() => {
  const USUARIO = document.body.dataset.usuario || '0';
  const PREFIJO = `femeci-borrador-${USUARIO}-`;
  const MARCA_ENVIO = 'femeci-borrador-enviado';
  const VIGENCIA = 7 * 86400000;
  const OMITIR = new Set(['csrf', 'clave_envio']);
  const leer = (k) => { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } };
  const escribir = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* sin espacio o sin almacenamiento */ } };
  const borrar = (k) => { try { localStorage.removeItem(k); } catch (e) { /* opcional */ } };

  // Borradores vencidos de cualquier formulario
  try {
    Object.keys(localStorage).filter((k) => k.startsWith('femeci-borrador-')).forEach((k) => {
      const b = leer(k);
      if (!b || Date.now() - (b.fecha || 0) > VIGENCIA) borrar(k);
    });
  } catch (e) { /* sin almacenamiento */ }

  const formularios = [...document.querySelectorAll('form[data-borrador]')];
  const claveDe = (form) => PREFIJO + form.dataset.borrador;

  // ¿El último envío se guardó? Sí, si esta página ya no tiene ese formulario y trae el aviso de éxito
  let enviado = null;
  try { enviado = sessionStorage.getItem(MARCA_ENVIO); sessionStorage.removeItem(MARCA_ENVIO); } catch (e) { /* opcional */ }
  if (enviado && !formularios.some((f) => claveDe(f) === enviado) && document.querySelector('.notificacion--exito')) borrar(enviado);
  // Al volver con "Atrás" (página guardada en memoria) el envío no se completó: el borrador sigue
  window.addEventListener('pageshow', (ev) => { if (ev.persisted) { try { sessionStorage.removeItem(MARCA_ENVIO); } catch (e) { /* opcional */ } } });

  // Las casillas obligatorias son constancias (confirmación de datos, "expliqué el procedimiento"):
  // se vuelven a marcar a conciencia, nunca desde un borrador
  const campos = (form) => [...form.elements].filter((el) => el.name && !OMITIR.has(el.name)
    && !['hidden', 'file', 'password', 'submit', 'button', 'reset'].includes(el.type)
    && !(el.type === 'checkbox' && el.required) && !el.closest('[data-borrador-omitir]'));

  const serializar = (form) => campos(form).map((el) => (
    el.type === 'checkbox' || el.type === 'radio' ? { n: el.name, v: el.value, c: el.checked } : { n: el.name, v: el.value }
  ));

  const haceTexto = (ms) => {
    const min = Math.round((Date.now() - ms) / 60000);
    if (min < 1) return 'hace un momento';
    if (min < 60) return `hace ${min} min`;
    const h = Math.round(min / 60);
    return h < 24 ? `hace ${h} h` : `hace ${Math.round(h / 24)} d`;
  };

  /** Renglones repetibles (medicamentos[], diagnóstico[]): se agregan los que falten con su botón. */
  const asegurarRenglones = (form, nombre, necesarios) => {
    const existentes = () => form.querySelectorAll(`[name="${CSS.escape(nombre)}"]`);
    const caja = existentes()[0]?.closest('[data-filas]');
    const boton = caja && form.querySelector(`[data-fila-agregar="${CSS.escape(caja.dataset.filas)}"]`);
    for (let i = 0; boton && existentes().length < necesarios && i < 40; i++) boton.click();
  };

  const aplicar = (form, borrador) => {
    let cambios = 0;
    const porNombre = new Map();
    borrador.campos.forEach((c) => { if (!porNombre.has(c.n)) porNombre.set(c.n, []); porNombre.get(c.n).push(c); });
    porNombre.forEach((valores, nombre) => {
      if (nombre.endsWith('[]')) asegurarRenglones(form, nombre, valores.length);
      const elementos = [...form.querySelectorAll(`[name="${CSS.escape(nombre)}"]`)].filter((el) => campos(form).includes(el));
      if (elementos.length && (elementos[0].type === 'checkbox' || elementos[0].type === 'radio')) {
        elementos.forEach((el) => {
          const guardado = valores.find((v) => v.v === el.value);
          const marcar = Boolean(guardado?.c);
          if (el.checked !== marcar) { el.checked = marcar; cambios++; el.dispatchEvent(new Event('change', { bubbles: true })); }
        });
        return;
      }
      elementos.forEach((el, i) => {
        if (!valores[i] || el.value === valores[i].v) return;
        el.value = valores[i].v;   // en los selectores de la app, el valor repinta el botón
        cambios++;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
    return cambios;
  };

  const avisar = (form, borrador, alDescartar) => {
    const aviso = document.createElement('div');
    aviso.className = 'aviso-borrador';
    aviso.setAttribute('role', 'status');
    aviso.innerHTML = `<span>Se recuperó lo que escribiste ${escaparHtml(haceTexto(borrador.fecha))} y no se había guardado.</span>
      <button type="button" class="boton boton--sutil">Descartar borrador</button>`;
    aviso.querySelector('button').addEventListener('click', () => { alDescartar(); aviso.remove(); });
    form.before(aviso);
  };

  const iniciar = (form) => {
    const clave = claveDe(form);
    let tocado = false;
    let temporizador = null;
    const guardar = () => {
      clearTimeout(temporizador);
      if (tocado) escribir(clave, { fecha: Date.now(), url: location.pathname + location.search, campos: serializar(form) });
    };
    const programar = () => { tocado = true; clearTimeout(temporizador); temporizador = setTimeout(guardar, 500); };

    const borrador = leer(clave);
    if (borrador?.campos?.length && aplicar(form, borrador) > 0) {
      tocado = true;
      avisar(form, borrador, () => {
        borrar(clave);
        tocado = false;
        form.reset();   // vuelve a lo que trajo el servidor
        form.querySelectorAll('select').forEach((s) => s.dispatchEvent(new Event('change', { bubbles: true })));
      });
    }

    form.addEventListener('input', programar);
    form.addEventListener('change', programar);
    window.addEventListener('pagehide', guardar);
    form.addEventListener('submit', (ev) => {
      // Si la validación del navegador lo detuvo, el borrador sigue igual
      setTimeout(() => {
        if (ev.defaultPrevented) return;
        guardar();
        try { sessionStorage.setItem(MARCA_ENVIO, clave); } catch (e) { /* opcional */ }
      }, 0);
    });
  };

  // Después de que la app convierte los <select> en sus menús propios (espacio.js, DOMContentLoaded)
  const arrancar = () => formularios.forEach(iniciar);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrancar);
  else arrancar();
})();
