/* ==========================================================================
   Perfiles de formato (pestaña "Formatos" de datos/index.php): lista de perfiles incluidos y propios,
   ver o editar el JSON con validación del servidor, duplicar, quitar e importar. Los incluidos solo se
   leen: para cambiar uno se duplica. API: api/datos/perfiles.php.
   ========================================================================== */
(() => {
  const raiz = document.querySelector('[data-formatos]');
  if (!raiz) return;
  const BASE_URL = document.body.dataset.base ?? '';
  const CSRF = document.querySelector('meta[name="csrf"]')?.content ?? '';
  const EDITABLE = raiz.dataset.editable === '1';
  const tabla = raiz.querySelector('[data-formatos-tabla]');
  const editor = raiz.querySelector('[data-formatos-editor]');
  const areaJson = raiz.querySelector('[data-formatos-json]');
  const errores = raiz.querySelector('[data-formatos-errores]');
  const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const avisar = (tipo, texto) => window.mostrarNotificacion?.(tipo, texto);
  let actual = null;   // {id, origen}

  async function pedir(metodo, cuerpo, consulta = '') {
    const r = await fetch(`${BASE_URL}/api/datos/perfiles.php${consulta}`, {
      method: metodo,
      headers: metodo === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF } : {},
      body: metodo === 'POST' ? JSON.stringify(cuerpo) : undefined,
    });
    const json = await r.json().catch(() => ({ ok: false, error: 'Respuesta inesperada del servidor.' }));
    if (!r.ok || !json.ok) throw Object.assign(new Error(json.error || 'No se pudo completar.'), { errores: json.errores || [] });
    return json;
  }

  const vigencia = (v) => [v.desde ? `desde ${v.desde}` : '', v.hasta ? `hasta ${v.hasta}` : ''].filter(Boolean).join(' ') || 'Sin fecha';

  async function cargar() {
    try {
      const { perfiles } = await pedir('GET');
      tabla.innerHTML = `<thead><tr><th>Perfil</th><th>Institución</th><th>Versión</th><th>Vigencia</th><th>Origen</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>${
        perfiles.map((p) => `<tr>
          <td><button type="button" class="datos-enlace formatos-tabla__nombre" data-ver="${esc(p.id)}">${esc(p.nombre)}</button></td>
          <td>${esc(p.institucion)}</td>
          <td>${esc(p.version)}</td>
          <td>${esc(vigencia(p.vigencia))}${p.vigente ? '' : ' · <span class="formatos-tabla__vencido">no vigente</span>'}</td>
          <td>${p.origen === 'incluido' ? 'Incluido' : 'Propio'}</td>
          <td class="formatos-tabla__acciones">${EDITABLE ? `<button type="button" class="datos-enlace" data-duplicar="${esc(p.id)}">Duplicar</button>${p.origen === 'propio' ? `<button type="button" class="datos-enlace" data-quitar="${esc(p.id)}">Quitar</button>` : ''}` : ''}</td>
        </tr>`).join('')
      }</tbody>`;
      if (!perfiles.length) tabla.innerHTML = '<tbody><tr><td class="bd__vacio">No hay perfiles de formato.</td></tr></tbody>';
    } catch (e) { avisar('error', e.message); }
  }

  function mostrarErrores(lista) {
    errores.hidden = !lista.length;
    errores.innerHTML = lista.map((t) => `<li>${esc(t)}</li>`).join('');
  }

  async function abrir(id) {
    try {
      const { perfil, origen } = await pedir('GET', null, `?id=${encodeURIComponent(id)}`);
      actual = { id, origen };
      raiz.querySelector('[data-formatos-editor-titulo]').textContent = perfil.nombre;
      raiz.querySelector('[data-formatos-aviso]').textContent = perfil.aviso || '';
      areaJson.value = JSON.stringify(perfil, null, 2);
      // Los incluidos se leen tal cual; para cambiarlos se duplican
      areaJson.readOnly = !EDITABLE || origen === 'incluido';
      raiz.querySelector('[data-formatos-guardar]').hidden = areaJson.readOnly;
      mostrarErrores(origen === 'incluido' && EDITABLE ? ['Perfil incluido: solo lectura. Duplícalo para editarlo.'] : []);
      editor.hidden = false;
      editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) { avisar('error', e.message); }
  }

  async function guardar(texto) {
    try {
      const r = await pedir('POST', { accion: 'guardar', json: texto });
      avisar('exito', `Perfil guardado (versión ${r.version}).`);
      mostrarErrores([]);
      await cargar();
      await abrir(r.id);
    } catch (e) {
      mostrarErrores(e.errores.length ? e.errores : [e.message]);
      editor.hidden = false;
    }
  }

  tabla.addEventListener('click', async (ev) => {
    const ver = ev.target.closest('[data-ver]');
    const duplicar = ev.target.closest('[data-duplicar]');
    const quitar = ev.target.closest('[data-quitar]');
    if (ver) abrir(ver.dataset.ver);
    if (duplicar) {
      try { const r = await pedir('POST', { accion: 'duplicar', id: duplicar.dataset.duplicar }); avisar('exito', 'Se creó una copia editable.'); await cargar(); abrir(r.id); }
      catch (e) { avisar('error', e.message); }
    }
    if (quitar) {
      const ok = typeof confirmar === 'function'
        ? await confirmar(quitar, { titulo: '¿Quitar este perfil?', texto: 'Se archiva en el historial de perfiles; las conversiones ya hechas no cambian.', boton: 'Quitar' })
        : true;
      if (!ok) return;
      try { await pedir('POST', { accion: 'eliminar', id: quitar.dataset.quitar }); if (actual?.id === quitar.dataset.quitar) editor.hidden = true; await cargar(); }
      catch (e) { avisar('error', e.message); }
    }
  });

  raiz.querySelector('[data-formatos-guardar]').addEventListener('click', () => guardar(areaJson.value));
  raiz.querySelector('[data-formatos-cerrar]').addEventListener('click', () => { editor.hidden = true; actual = null; });
  raiz.querySelector('[data-formatos-descargar]').addEventListener('click', () => {
    const enlace = document.createElement('a');
    enlace.href = URL.createObjectURL(new Blob([areaJson.value + '\n'], { type: 'application/json' }));
    enlace.download = `${actual?.id || 'perfil'}.json`;
    enlace.click();
    setTimeout(() => URL.revokeObjectURL(enlace.href), 1000);
  });
  raiz.querySelector('[data-formatos-importar]')?.addEventListener('change', async (ev) => {
    const archivo = ev.target.files[0];
    ev.target.value = '';
    if (!archivo) return;
    if (archivo.size > 64 * 1024) { avisar('error', 'El perfil supera 64 KB.'); return; }
    const texto = await archivo.text();
    areaJson.value = texto;
    areaJson.readOnly = false;
    raiz.querySelector('[data-formatos-guardar]').hidden = false;
    raiz.querySelector('[data-formatos-editor-titulo]').textContent = archivo.name;
    actual = null;
    guardar(texto);
  });

  cargar();
})();
