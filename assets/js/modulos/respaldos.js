/* Respaldos (configuracion/respaldos.php): vistas Respaldos · Integridad, respaldar ahora, descargar,
   verificar un archivo, CHECK TABLE y restaurar con confirmación escrita.
   Usa BASE, TOKEN_CSRF, escaparHtml y mostrarNotificacion (app.js); abrirMenu, abrirPopover,
   cerrarPopover e ico (espacio.js). */
(() => {
  const raiz = document.querySelector('[data-respaldos]');
  if (!raiz) return;
  const datos = JSON.parse(document.getElementById('respaldos-datos')?.textContent || '{}');
  const URL_API = `${BASE}/api/configuracion/respaldos.php`;

  const pedir = async (cuerpo) => {
    const r = await fetch(URL_API, {
      method: cuerpo ? 'POST' : 'GET',
      headers: cuerpo ? { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF } : {},
      body: cuerpo ? JSON.stringify(cuerpo) : undefined,
    });
    let res = {};
    try { res = await r.json(); } catch (e) { /* sin cuerpo */ }
    if (!r.ok || res.ok === false) throw new Error(res.error || 'No se pudo completar la acción.');
    return res;
  };

  /* ---------- Vistas ---------- */
  const vistas = raiz.querySelectorAll('[data-vista]');
  const mostrarVista = (nombre) => {
    vistas.forEach((b) => { b.classList.toggle('activa', b.dataset.vista === nombre); b.setAttribute('aria-selected', String(b.dataset.vista === nombre)); });
    raiz.querySelectorAll('[data-vista-panel]').forEach((p) => { p.hidden = p.dataset.vistaPanel !== nombre; });
  };
  vistas.forEach((b) => b.addEventListener('click', () => mostrarVista(b.dataset.vista)));

  /* ---------- Lista de respaldos ---------- */
  const lista = raiz.querySelector('[data-respaldos-lista]');
  const pintar = (respaldos) => {
    lista.innerHTML = respaldos.map((r) => `
      <tr data-archivo="${escaparHtml(r.archivo)}">
        <td><span class="personas__persona"><span class="personas__icono">${ico('carpeta')}</span><span class="personas__nombre">${escaparHtml(r.fecha_texto)}</span></span></td>
        <td class="personas__gris">${escaparHtml(r.motivo_texto)}</td>
        <td class="personas__gris">${escaparHtml(r.tamano)}</td>
        <td class="personas__gris respaldos__suma" title="${escaparHtml(r.sha256 || '')}">${r.sha256 ? `${escaparHtml(r.sha256.slice(0, 16))}…` : 'Sin suma'}</td>
        <td class="personas__acciones-col"><button type="button" class="personas__mas" data-respaldo-menu title="Acciones">${ico('mas')}</button></td>
      </tr>`).join('');
    raiz.querySelector('[data-respaldos-cuenta]').textContent = String(respaldos.length);
    raiz.querySelector('[data-respaldos-vacio]').hidden = respaldos.length > 0;
  };

  const ahora = raiz.querySelector('[data-respaldos-ahora]');
  ahora.addEventListener('click', async () => {
    ahora.disabled = true;
    try {
      const r = await pedir({ accion: 'respaldar' });
      pintar(r.respaldos);
      lista.querySelector('tr')?.classList.add('nuevo');
      mostrarVista('respaldos');
      mostrarNotificacion('exito', `Respaldo creado: ${r.archivo}`);
    } catch (e) {
      mostrarNotificacion('error', e.message);
    } finally {
      ahora.disabled = false;
    }
  });

  /* ---------- CHECK TABLE ---------- */
  const botonIntegridad = raiz.querySelector('[data-respaldos-integridad]');
  botonIntegridad.addEventListener('click', async () => {
    botonIntegridad.disabled = true;
    try {
      const r = await pedir({ accion: 'integridad' });
      raiz.querySelector('[data-integridad-lista]').innerHTML = r.tablas.map((t) => `
        <tr>
          <td><span class="personas__persona"><span class="personas__icono">${ico('tabla')}</span><span class="personas__nombre">${escaparHtml(t.tabla)}</span></span></td>
          <td><span class="estado${t.ok ? '' : ' estado--pendiente'}"><i aria-hidden="true"></i>${t.ok ? 'Correcta' : escaparHtml(t.estado)}</span></td>
          <td class="personas__gris">${escaparHtml(t.mensaje || '')}</td>
        </tr>`).join('');
      raiz.querySelector('[data-integridad-vacio]').hidden = true;
      mostrarVista('integridad');
      mostrarNotificacion(r.fallas ? 'error' : 'exito', r.fallas
        ? `${r.fallas} ${r.fallas === 1 ? 'tabla necesita' : 'tablas necesitan'} revisión.`
        : `Las ${r.tablas.length} tablas están correctas.`);
    } catch (e) {
      mostrarNotificacion('error', e.message);
    } finally {
      botonIntegridad.disabled = false;
    }
  });

  /* ---------- Restaurar: se escribe RESTAURAR para confirmar ---------- */
  const confirmarRestauracion = (ancla, archivo) => new Promise((resolver) => {
    let respondido = false;
    const el = abrirPopover(ancla, `
      <form class="confirmar">
        <p class="confirmar__titulo">Restaurar este respaldo</p>
        <p class="confirmar__texto">Se reemplaza toda la base de datos por la de ${escaparHtml(archivo)}. Lo capturado después se pierde, salvo en el respaldo que se hace antes automáticamente. Escribe RESTAURAR para confirmar.</p>
        <input class="menu-flotante__entrada" autocomplete="off" spellcheck="false" aria-label="Escribe RESTAURAR">
        <div class="confirmar__botones">
          <button type="button" class="boton boton--sutil" data-no>Cancelar</button>
          <button type="submit" class="boton boton--peligro" data-si disabled>Restaurar</button>
        </div>
      </form>`, { alinear: 'derecha', alCerrar: () => { if (!respondido) resolver(null); } });
    const entrada = el.querySelector('input');
    const si = el.querySelector('[data-si]');
    entrada.addEventListener('input', () => { si.disabled = entrada.value.trim() !== 'RESTAURAR'; });
    el.querySelector('[data-no]').addEventListener('click', () => cerrarPopover());
    el.querySelector('form').addEventListener('submit', (ev) => {
      ev.preventDefault();
      if (si.disabled) return;
      respondido = true;
      cerrarPopover();
      resolver(entrada.value.trim());
    });
    entrada.focus();
  });

  lista.addEventListener('click', (ev) => {
    const boton = ev.target.closest('[data-respaldo-menu]');
    if (!boton) return;
    const archivo = boton.closest('tr').dataset.archivo;
    abrirMenu(boton, [
      { icono: 'descargar', texto: 'Descargar', accion: () => { location.href = `${URL_API}?descargar=${encodeURIComponent(archivo)}`; } },
      {
        icono: 'ojo', texto: 'Verificar archivo', accion: async () => {
          try {
            const r = await pedir({ accion: 'verificar', archivo });
            mostrarNotificacion(r.integro ? 'exito' : 'error', r.mensaje);
          } catch (e) { mostrarNotificacion('error', e.message); }
        },
      },
      'separador',
      datos.restaurar
        ? {
          icono: 'reabrir', texto: 'Restaurar…', peligro: true, accion: async () => {
            const confirmacion = await confirmarRestauracion(boton, archivo);
            if (!confirmacion) return;
            mostrarNotificacion('exito', 'Restaurando… no cierres esta página.');
            try {
              const r = await pedir({ accion: 'restaurar', archivo, confirmacion });
              mostrarNotificacion('exito', r.mensaje);
              setTimeout(() => location.reload(), 1600);
            } catch (e) { mostrarNotificacion('error', e.message); }
          },
        }
        : { nota: 'Para restaurar falta el usuario de respaldos de MySQL (docs/respaldos.md).' },
    ], { alinear: 'derecha' });
  });
})();
