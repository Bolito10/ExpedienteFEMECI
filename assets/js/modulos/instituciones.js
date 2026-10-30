/* ==========================================================================
   Alta de la institución e incorporación con códigos:
   - Pasos cortos dentro de la ventana de acceso (crear cuenta, registrar clínica).
   - Edad calculada, reglas de la contraseña, cédula solo con dígitos y código XXXX-XXXX-XXXX.
   - Códigos de acceso en Personas y permisos: generar, copiar y revocar.
   Usa mostrarNotificacion y escaparHtml de app.js; abrirMenu, abrirPopover, mejorarSelect y api de espacio.js.
   ========================================================================== */
(() => {
  const ICONO_MARCA = '<svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>';

  /* ---------- Pasos dentro de la ventana de acceso ---------- */
  function iniciarPasos(form) {
    const pasos = [...form.querySelectorAll('[data-paso]')];
    let actual = Math.min(Math.max(Number(form.dataset.pasoInicial) || 1, 1), pasos.length);

    const mostrar = (n, animar = true) => {
      actual = n;
      pasos.forEach((p, i) => {
        const visible = i + 1 === n;
        p.hidden = !visible;
        p.classList.toggle('entra', visible && animar);
      });
      const primero = pasos[n - 1].querySelector('input:not([type="hidden"]):not([disabled]), .selector');
      if (animar) primero?.focus({ preventScroll: true });
    };

    const mensajeDe = (campo) => campo.dataset.mensaje
      || (campo.type === 'email' ? 'Escribe un correo válido.' : 'Completa este campo para continuar.');

    const validar = (n) => {
      for (const campo of pasos[n - 1].querySelectorAll('input:not([type="hidden"]):not([disabled]), select')) {
        if (campo.matches('[data-confirmar]')) {
          const original = form.querySelector(`[name="${campo.dataset.confirmar}"]`);
          if (original && campo.value !== original.value) { marcar(campo, 'Las contraseñas no coinciden.'); return false; }
        }
        if (!campo.checkValidity()) { marcar(campo, mensajeDe(campo)); return false; }
      }
      return true;
    };
    const marcar = (campo, texto) => {
      const visible = campo.classList.contains('select-oculto') ? campo.nextElementSibling : campo;
      (visible.closest('.alta__selector') || visible).classList.add('alta__campo-error');
      visible.focus();
      mostrarNotificacion('error', texto);
    };
    form.addEventListener('input', (ev) => ev.target.classList?.remove('alta__campo-error'));
    form.addEventListener('change', (ev) => ev.target.closest?.('.alta__selector')?.classList.remove('alta__campo-error'));

    form.querySelectorAll('[data-siguiente]').forEach((b) => b.addEventListener('click', () => { if (validar(actual)) mostrar(actual + 1); }));
    form.querySelectorAll('[data-anterior]').forEach((b) => b.addEventListener('click', (ev) => { ev.preventDefault(); mostrar(actual - 1); }));
    form.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' && ev.target.matches('input') && actual < pasos.length) {
        ev.preventDefault();
        pasos[actual - 1].querySelector('[data-siguiente]')?.click();
      }
    });
    form.addEventListener('submit', (ev) => {
      for (let n = 1; n <= pasos.length; n++) {
        if (!validar(n)) { ev.preventDefault(); if (n !== actual) mostrar(n); return; }
      }
      form.querySelector('[type="submit"]')?.setAttribute('disabled', '');
    });
    mostrar(actual, false);
  }

  /* ---------- Edad a partir de la fecha de nacimiento ---------- */
  function iniciarEdad(campo) {
    const texto = campo.parentElement.querySelector('[data-edad-texto]');
    if (!texto) return;
    const pintar = () => {
      const f = campo.value ? new Date(`${campo.value}T00:00:00`) : null;
      if (!f || Number.isNaN(f.getTime())) { texto.textContent = texto.dataset.vacio || ''; texto.classList.remove('alta__ayuda--error'); return; }
      const hoy = new Date();
      let edad = hoy.getFullYear() - f.getFullYear();
      if (hoy.getMonth() < f.getMonth() || (hoy.getMonth() === f.getMonth() && hoy.getDate() < f.getDate())) edad--;
      const minima = Number(campo.dataset.edadMinima || 0);
      texto.textContent = edad < 0 ? 'La fecha está en el futuro.' : `${edad} ${edad === 1 ? 'año' : 'años'}${minima && edad < minima ? ` · se requiere mayoría de edad (${minima})` : ''}`;
      texto.classList.toggle('alta__ayuda--error', edad < 0 || (minima > 0 && edad < minima));
    };
    campo.addEventListener('input', pintar);
    campo.addEventListener('change', pintar);
    pintar();
  }

  /* ---------- Reglas de la contraseña (las mismas que valida el servidor) ---------- */
  function iniciarReglas(campo) {
    const lista = campo.closest('form').querySelector('[data-reglas]');
    if (!lista) return;
    const reglas = {
      largo: (v) => v.length >= 12 && v.length <= 128,
      tipos: (v) => [/\p{Ll}/u, /\p{Lu}/u, /\d/, /[^\p{L}\d]/u].filter((r) => r.test(v)).length >= 3,
      repeticion: (v) => v.length > 0 && !/(.)\1{3,}/u.test(v),
    };
    lista.querySelectorAll('li').forEach((li) => li.insertAdjacentHTML('afterbegin', ICONO_MARCA));
    const pintar = () => lista.querySelectorAll('[data-regla]').forEach((li) => li.classList.toggle('cumple', reglas[li.dataset.regla](campo.value)));
    campo.addEventListener('input', pintar);
    pintar();
  }

  /* ---------- Código XXXX-XXXX-XXXX mientras se escribe ---------- */
  function iniciarCodigo(campo) {
    campo.addEventListener('input', () => {
      const limpio = campo.value.toUpperCase().replace(/[^0-9A-Z]/g, '').slice(0, 12);
      campo.value = limpio.match(/.{1,4}/g)?.join('-') ?? '';
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select[data-menu-propio]').forEach((s) => { if (typeof mejorarSelect === 'function') mejorarSelect(s); });
    document.querySelectorAll('form[data-pasos]').forEach(iniciarPasos);
    document.querySelectorAll('input[data-edad]').forEach(iniciarEdad);
    document.querySelectorAll('input[data-clave-reglas]').forEach(iniciarReglas);
    document.querySelectorAll('input[data-codigo-acceso]').forEach(iniciarCodigo);
    document.querySelectorAll('input[data-solo-digitos]').forEach((c) => c.addEventListener('input', () => { c.value = c.value.replace(/\D/g, ''); }));
    iniciarCodigos();
  });

  /* ---------- Personas y permisos: reintentar la verificación de una cédula en el RNP ---------- */
  document.addEventListener('click', async (ev) => {
    const boton = ev.target.closest('[data-cedula-reintentar]');
    if (!boton || typeof api !== 'function') return;
    boton.disabled = true;
    try {
      await api('configuracion/personas.php', { accion: 'cedula_verificar', usuario_id: Number(boton.closest('tr').dataset.usuario) });
      location.reload();   // el resultado (verificada o pendiente, con su motivo) llega como aviso
    } catch (e) { mostrarNotificacion('error', e.message); boton.disabled = false; }
  });

  /* ---------- Códigos de acceso en Personas y permisos ---------- */
  function iniciarCodigos() {
    const raiz = document.querySelector('[data-codigos]');
    const fuente = document.getElementById('codigos-datos');
    if (!raiz || !fuente || typeof abrirPopover !== 'function') return;
    const datos = JSON.parse(fuente.textContent);

    const copiar = async (texto) => {
      try { await navigator.clipboard.writeText(texto); mostrarNotificacion('exito', 'Código copiado.'); }
      catch (e) { mostrarNotificacion('error', 'No se pudo copiar; selecciónalo y cópialo a mano.'); }
    };

    // Generar: rol, usos y vigencia; el código completo se muestra una sola vez
    document.querySelectorAll('[data-codigo-generar]').forEach((boton) => boton.addEventListener('click', () => {
      const roles = Object.entries(datos.roles).filter(([clave]) => clave !== 'paciente' || datos.pacientes.length);
      let rol = roles[0]?.[0] || 'medico';
      let paciente = null;
      const caja = document.createElement('div');
      caja.className = 'codigos__nuevo';
      caja.innerHTML = `
        <div class="menu-flotante__titulo">Nuevo código de acceso</div>
        <div class="codigos__fila"><span>Rol</span><button type="button" class="selector" data-rol aria-haspopup="menu"></button></div>
        <div class="codigos__fila" data-fila-paciente hidden><span>Paciente</span><button type="button" class="selector" data-paciente aria-haspopup="menu"></button></div>
        <label class="codigos__fila" data-fila-usos><span>Usos</span><input class="menu-flotante__entrada" type="number" min="1" max="${datos.usosMax}" value="1" data-usos></label>
        <label class="codigos__fila"><span>Vigencia (días)</span><input class="menu-flotante__entrada" type="number" min="1" max="${datos.diasMax}" value="${datos.dias}" data-dias></label>
        <button type="button" class="boton boton--primario" data-crear>Generar código</button>
        <p class="codigos__nota">Quien lo use queda en esta institución con el rol elegido. Se muestra una sola vez.</p>`;
      const pintar = () => {
        caja.querySelector('[data-rol]').innerHTML = `<span>${escaparHtml(datos.roles[rol])}</span>${ico('flecha_abajo')}`;
        const esPaciente = rol === 'paciente';
        caja.querySelector('[data-fila-paciente]').hidden = !esPaciente;
        caja.querySelector('[data-fila-usos]').hidden = esPaciente;
        const p = datos.pacientes.find((x) => x.id === paciente);
        caja.querySelector('[data-paciente]').innerHTML = `<span class="${p ? '' : 'selector__vacio'}">${escaparHtml(p ? p.nombre : 'Selecciona')}</span>${ico('flecha_abajo')}`;
      };
      pintar();
      abrirPopover(boton, caja, { clase: 'popover--compartir', alinear: 'derecha' });
      // Los menús de rol y paciente se abren dentro del mismo popover para no cerrarlo
      const listaEn = (ancla, opciones, alElegir) => {
        const existente = caja.querySelector('.codigos__lista');
        if (existente) { existente.remove(); if (existente.dataset.para === ancla.dataset.para) return; }
        const lista = document.createElement('div');
        lista.className = 'menu-flotante selector__lista codigos__lista';
        lista.dataset.para = ancla.dataset.para;
        lista.innerHTML = `${opciones.length > 8 ? '<input class="menu-flotante__entrada" placeholder="Buscar…" aria-label="Buscar">' : ''}
          <div class="selector__opciones" role="listbox">${opciones.map(([valor, texto, marcado]) => `
            <button type="button" role="option" data-valor="${escaparHtml(String(valor))}" aria-selected="${marcado}"><span>${escaparHtml(texto)}</span></button>`).join('')}</div>`;
        ancla.closest('.codigos__fila').after(lista);
        const buscar = lista.querySelector('input');
        buscar?.addEventListener('input', () => {
          const t = normalizarTexto(buscar.value.trim());
          lista.querySelectorAll('[data-valor]').forEach((b) => { b.hidden = !!t && !normalizarTexto(b.textContent).includes(t); });
        });
        (buscar || lista.querySelector('[data-valor]'))?.focus();
        lista.addEventListener('click', (e) => {
          const b = e.target.closest('[data-valor]');
          if (!b) return;
          alElegir(b.dataset.valor);
          lista.remove();
          pintar();
        });
      };
      caja.querySelector('[data-rol]').dataset.para = 'rol';
      caja.querySelector('[data-paciente]').dataset.para = 'paciente';
      caja.querySelector('[data-rol]').addEventListener('click', (e) => listaEn(e.currentTarget, roles.map(([c, n]) => [c, n, c === rol]), (v) => { rol = v; }));
      caja.querySelector('[data-paciente]').addEventListener('click', (e) => listaEn(e.currentTarget, datos.pacientes.map((p) => [p.id, p.nombre, p.id === paciente]), (v) => { paciente = Number(v); }));

      caja.querySelector('[data-crear]').addEventListener('click', async (ev) => {
        const cuerpo = { rol, usos: Number(caja.querySelector('[data-usos]').value), dias: Number(caja.querySelector('[data-dias]').value) };
        if (rol === 'paciente') {
          if (!paciente) { mostrarNotificacion('error', 'Elige el paciente.'); return; }
          cuerpo.paciente_id = paciente;
          delete cuerpo.usos;
        }
        ev.currentTarget.disabled = true;
        try {
          const r = await api('codigos/crear.php', cuerpo);
          caja.innerHTML = `
            <div class="menu-flotante__titulo">Código para ${escaparHtml(datos.roles[rol])}</div>
            <p class="codigos__generado">${escaparHtml(r.codigo)}</p>
            <p class="codigos__nota">Vence el ${escaparHtml(new Date(r.expira).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }))}. Compártelo en persona o por un medio seguro: después ya no se podrá ver completo.</p>
            <button type="button" class="boton boton--primario" data-copiar>Copiar código</button>`;
          caja.querySelector('[data-copiar]').addEventListener('click', () => copiar(r.codigo));
          raiz.dataset.cambiado = '1';
        } catch (e) { mostrarNotificacion('error', e.message); ev.currentTarget.disabled = false; }
      });
      // Al cerrar el popover con un código nuevo, la lista se recarga para mostrarlo
      const recargarAlCerrar = () => { if (raiz.dataset.cambiado && !document.querySelector('.codigos__nuevo')) { try { sessionStorage.setItem('femeci-personas-vista', 'codigos'); } catch (e) { /* opcional */ } location.reload(); } };
      document.addEventListener('pointerdown', () => setTimeout(recargarAlCerrar, 400));
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setTimeout(recargarAlCerrar, 400); });
    }));

    // "…" de cada código vigente: revocar
    raiz.querySelectorAll('[data-codigo-menu]').forEach((boton) => boton.addEventListener('click', () => {
      const id = Number(boton.closest('tr').dataset.codigo);
      abrirMenu(boton, [
        { icono: 'cerrar', texto: 'Revocar código', peligro: true, accion: async () => {
          try {
            await api('codigos/revocar.php', { id });
            try { sessionStorage.setItem('femeci-personas-vista', 'codigos'); } catch (e) { /* opcional */ }
            location.reload();
          } catch (e) { mostrarNotificacion('error', e.message); }
        } },
      ], { alinear: 'derecha' });
    }));
  }
})();
