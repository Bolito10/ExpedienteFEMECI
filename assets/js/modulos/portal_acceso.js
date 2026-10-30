/* ==========================================================================
   Expedientes Clínicos — "Dar acceso al portal" desde el perfil del paciente (pacientes/ver.php)
   Genera un código de registro con rol paciente (api/codigos/crear.php) para dictárselo o copiarlo.
   Si el paciente ya tiene cuenta, la muestra y no genera otro código.
   Usa abrirPopover/ico de espacio.js y escaparHtml/mostrarNotificacion de app.js.
   ========================================================================== */

(() => {
  const fechaVigencia = (texto) => {
    const f = new Date(String(texto).replace(' ', 'T'));
    if (Number.isNaN(f.getTime())) return String(texto);
    return f.toLocaleString('es-MX', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).replace(/\.$/, '');   // "a.m." sin punto final, para no duplicarlo
  };

  function abrirAccesoPortal(boton) {
    if (typeof abrirPopover !== 'function') return;
    const pacienteId = Number(boton.dataset.portalAcceso);
    const nombre = boton.dataset.pacienteNombre || 'El paciente';
    const cuenta = boton.dataset.portalCuenta ? JSON.parse(boton.dataset.portalCuenta) : null;
    const caja = document.createElement('div');
    caja.className = 'portal-acceso';

    const pintarCuenta = () => {
      caja.innerHTML = `
        <div class="compartir__titulo">Portal del paciente</div>
        <div class="compartir__general">
          <span class="compartir__general-icono">${ico('usuarios')}</span>
          <span class="compartir__general-texto">
            <b>${escaparHtml(nombre)} ya tiene cuenta</b>
            <small>${escaparHtml(cuenta.correo)}</small>
            <small>${cuenta.activa ? 'Activa' : 'Pendiente de activar'} · creada el ${escaparHtml(cuenta.creada)} · ${cuenta.ultimo_acceso ? `último acceso ${escaparHtml(cuenta.ultimo_acceso)}` : 'sin accesos todavía'}</small>
          </span>
        </div>`;
    };

    const pintarInicio = () => {
      caja.innerHTML = `
        <div class="compartir__titulo">Portal del paciente</div>
        <div class="compartir__general">
          <span class="compartir__general-icono">${ico('candado')}</span>
          <span class="compartir__general-texto">
            <b>Solo lectura de su propio expediente</b>
            <small>Con el código, ${escaparHtml(nombre)} crea su cuenta y consulta su ficha, historial clínico y citas.</small>
          </span>
        </div>
        <div class="compartir__pie">
          <button type="button" class="compartir__copiar" data-portal-generar>Generar código</button>
        </div>`;
      caja.querySelector('[data-portal-generar]').addEventListener('click', generar);
    };

    const pintarCodigo = (codigo, expira) => {
      caja.innerHTML = `
        <div class="compartir__titulo">Código de acceso al portal</div>
        <p class="portal-acceso__codigo" aria-label="Código ${escaparHtml(String(codigo).split('').join(' '))}">${escaparHtml(codigo)}</p>
        <p class="portal-acceso__vigencia">${ico('reloj')}<span>Vigente hasta el ${escaparHtml(fechaVigencia(expira))}. ${escaparHtml(nombre)} lo escribe al crear su cuenta.</span></p>
        <div class="compartir__pie">
          <button type="button" class="compartir__copiar" data-portal-copiar>${ico('enlace')} Copiar código</button>
        </div>`;
      caja.querySelector('[data-portal-copiar]').addEventListener('click', async (ev) => {
        try {
          await navigator.clipboard.writeText(String(codigo));
          ev.currentTarget.lastChild.textContent = ' Copiado';
        } catch (e) {
          mostrarNotificacion('error', 'No se pudo copiar. Dicta el código en voz alta.');
        }
      });
    };

    async function generar(ev) {
      const b = ev.currentTarget;
      b.disabled = true;
      b.textContent = 'Generando…';
      try {
        const r = await fetch(`${BASE}/api/codigos/crear.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
          body: JSON.stringify({ rol: 'paciente', paciente_id: pacienteId }),
        });
        const res = await r.json().catch(() => ({}));
        if (!r.ok || !res.ok || !res.codigo) throw new Error(res.error || 'No se pudo generar el código.');
        pintarCodigo(res.codigo, res.expira);
      } catch (e) {
        mostrarNotificacion('error', e.message);
        b.disabled = false;
        b.textContent = 'Generar código';
      }
    }

    if (cuenta) pintarCuenta(); else pintarInicio();
    abrirPopover(boton, caja, { clase: 'popover--compartir popover--portal-acceso', alinear: 'derecha', arriba: true });
  }

  document.addEventListener('click', (ev) => {
    const boton = ev.target.closest('[data-portal-acceso]');
    if (!boton) return;
    if (typeof popoverActual !== 'undefined' && popoverActual?.ancla === boton) { cerrarPopover(); return; }
    abrirAccesoPortal(boton);
  });
})();
