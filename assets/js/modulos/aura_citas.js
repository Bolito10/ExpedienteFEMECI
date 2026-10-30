/* ==========================================================================
   Aura de citas: un borde de luz delgado alrededor de toda la pantalla cuando se acerca un paciente que
   te toca atender (mismo estilo que el orbe de la IA). Solo usa tus citas reales de hoy.
     cerca  (30 a 10 min antes): aqua y azul, respira lento
     pronto (10 min o menos):     ámbar, más vivo
     ahora  (ya empezó, hasta 15 min sin atender) o motivo urgente: rojo, late
   Una píldora arriba dice quién y cuándo, con «Abrir expediente» y «Ocultar». Se puede apagar.
   ========================================================================== */
(() => {
  const yo = Number(document.body.dataset.usuario || 0);
  if (!yo || document.body.classList.contains('portal') || typeof BASE === 'undefined') return;
  const CLAVE_APAGADA = `femeci-aura-apagada-${yo}`;
  const CLAVE_OCULTAS = `femeci-aura-ocultas-${yo}`;
  const leer = (k, d) => { try { return JSON.parse(sessionStorage.getItem(k) ?? localStorage.getItem(k) ?? 'null') ?? d; } catch (e) { return d; } };
  const apagada = () => { try { return localStorage.getItem(CLAVE_APAGADA) === '1'; } catch (e) { return false; } };
  let ocultas = new Set(leer(CLAVE_OCULTAS, []));
  let citas = [];
  let ultimaCarga = 0;

  const aura = document.createElement('div');
  aura.className = 'aura-citas';
  aura.setAttribute('aria-hidden', 'true');
  const pildora = document.createElement('div');
  pildora.className = 'aura-pildora';
  pildora.setAttribute('role', 'status');
  pildora.hidden = true;
  document.body.append(aura, pildora);

  const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  async function cargar() {
    const hoy = new Date();
    const manana = new Date(hoy.getTime() + 86400000);
    try {
      const r = await fetch(`${BASE}/api/agenda/citas.php?desde=${ymd(hoy)}&hasta=${ymd(manana)}`, { headers: { Accept: 'application/json' } });
      const d = await r.json();
      if (d.ok) { citas = d.citas.filter((c) => c.profesional_id === yo && ['programada', 'confirmada'].includes(c.estado)); ultimaCarga = Date.now(); }
    } catch (e) { /* sin conexión: se conserva lo último */ }
  }

  // La cita más importante ahora mismo y su nivel
  function elegir() {
    const ahora = Date.now();
    let mejor = null;
    citas.forEach((c) => {
      if (ocultas.has(c.id)) return;
      const min = (new Date(c.inicio).getTime() - ahora) / 60000;
      const urgente = /urgen/i.test(`${c.motivo} ${c.notas}`);
      let nivel = null;
      if (min <= 0 && min > -15) nivel = 'ahora';
      else if (min > 0 && min <= 10) nivel = urgente ? 'ahora' : 'pronto';
      else if (min > 10 && min <= 30) nivel = urgente ? 'pronto' : 'cerca';
      if (!nivel) return;
      const peso = { ahora: 3, pronto: 2, cerca: 1 }[nivel];
      if (!mejor || peso > mejor.peso || (peso === mejor.peso && min < mejor.min)) mejor = { c, nivel, min, peso, urgente };
    });
    return mejor;
  }

  // Orbe real de la IA (sombreador aurora de assets/vendor/aurora); si no hay WebGL queda la versión CSS
  let orbe = null;
  function orbeIa() {
    if (orbe) return orbe;
    orbe = document.createElement('span');
    orbe.className = 'aura-orbe';
    const montar = () => {
      const b = window.Razonamiento?.bolita?.();
      if (b) { orbe.replaceChildren(b.el); b.el.classList.add('aura-orbe__bolita'); } else orbe.classList.add('aura-orbe--css');
    };
    if (typeof cargarAurora === 'function') cargarAurora().then(montar); else montar();
    return orbe;
  }

  function pintar() {
    const m = apagada() ? null : elegir();
    aura.className = `aura-citas${m ? ` aura-citas--activa aura-citas--${m.nivel}` : ''}`;
    if (!m) { pildora.hidden = true; return; }
    const cuando = m.min <= 0 ? (m.min > -1 ? 'Empieza ahora' : `Empezó hace ${Math.round(-m.min)} min`) : `En ${Math.max(1, Math.round(m.min))} min`;
    const etiqueta = m.urgente ? 'Cita urgente' : m.nivel === 'ahora' ? 'Tu paciente ya está' : 'Próxima cita';
    pildora.hidden = false;
    pildora.className = `aura-pildora aura-pildora--${m.nivel}`;
    if (!pildora.firstChild) {
      pildora.innerHTML = `
        <span class="aura-pildora__orbe-lugar"></span>
        <span class="aura-pildora__texto">
          <span class="aura-pildora__etiqueta"><span data-p-etiqueta></span><span class="aura-pildora__cuando" data-p-cuando></span></span>
          <b data-p-nombre></b>
          <small data-p-detalle></small>
        </span>
        <button type="button" class="aura-pildora__boton" data-aura-atender><span>Atender</span>${typeof ico === 'function' ? ico('flecha_derecha') : ''}</button>
        <button type="button" class="aura-pildora__cerrar" data-aura-ocultar title="Ocultar el aura de esta cita" aria-label="Ocultar">${typeof ico === 'function' ? ico('cerrar') : '×'}</button>`;
      pildora.querySelector('.aura-pildora__orbe-lugar').appendChild(orbeIa());
    }
    pildora.querySelector('[data-p-etiqueta]').textContent = etiqueta;
    pildora.querySelector('[data-p-cuando]').textContent = cuando;
    pildora.querySelector('[data-p-nombre]').textContent = m.c.paciente || 'Cita';
    pildora.querySelector('[data-p-detalle]').textContent = [`${m.c.inicio.slice(11, 16)}–${m.c.fin.slice(11, 16)}`, m.c.motivo].filter(Boolean).join(' · ');
    pildora.querySelector('[data-aura-atender]').dataset.auraAtender = m.c.id;
    pildora.querySelector('[data-aura-ocultar]').dataset.auraOcultar = m.c.id;
    // Al aparecer la notificación de una cita se abre solo el panel de atención rápida (una vez por cita)
    const abiertas = new Set(leer(`femeci-aura-paneles-${yo}`, []));
    if (!abiertas.has(m.c.id) && !document.querySelector('.aura-panel')) {
      abiertas.add(m.c.id);
      try { sessionStorage.setItem(`femeci-aura-paneles-${yo}`, JSON.stringify([...abiertas])); } catch (e) { /* opcional */ }
      abrirPanel(m.c);
    }
  }

  pildora.addEventListener('click', (ev) => {
    const atender = ev.target.closest('[data-aura-atender]');
    if (atender) { const c = citas.find((x) => x.id === Number(atender.dataset.auraAtender)); if (c) abrirPanel(c); return; }
    const b = ev.target.closest('[data-aura-ocultar]');
    if (!b) return;
    ocultas.add(Number(b.dataset.auraOcultar));
    try { sessionStorage.setItem(CLAVE_OCULTAS, JSON.stringify([...ocultas])); } catch (e) { /* opcional */ }
    pintar();
    window.mostrarNotificacion?.('exito', 'Listo. Puedes apagar el aura en Configuración → Notificaciones.');
  });

  /* --- Panel de atención rápida: todo lo que se puede hacer con este paciente, en un lugar --- */
  let tipos = {};
  try { tipos = JSON.parse(document.getElementById('tipos-registro')?.textContent || '{}'); } catch (e) { /* sin tipos */ }
  const ic = (n) => (typeof ico === 'function' ? ico(n) : '');
  const hm = (t) => t.slice(11, 16);
  async function abrirPanel(c) {
    document.querySelector('.aura-panel')?.remove();
    const panel = document.createElement('aside');
    panel.className = 'aura-panel';
    panel.setAttribute('aria-label', `Atender a ${c.paciente}`);
    const pid = Number(c.paciente_id);
    const grupos = {};
    Object.entries(tipos).forEach(([k, t]) => { (grupos[t.grupo] = grupos[t.grupo] || []).push([k, t]); });
    const ESTADOS = [['confirmada', 'Llegó / confirmada', 'escudo_ok'], ['atendida', 'Atendida', 'casilla'], ['no_asistio', 'No asistió', 'cerrar']];
    panel.innerHTML = `
      <div class="aura-panel__barra">
        <span class="aura-panel__orbe" data-panel-orbe aria-hidden="true"></span>
        <div class="aura-panel__quien"><b>${escaparHtml(c.paciente)}</b><small>${escaparHtml(c.folio || '')} · cita ${hm(c.inicio)}–${hm(c.fin)}${c.motivo ? ` · ${escaparHtml(c.motivo)}` : ''}</small></div>
        <button type="button" class="boton-icono" data-aura-panel-cerrar title="Cerrar (Esc)">${ic('cerrar')}</button>
      </div>
      <div class="aura-panel__cuerpo">
        <div class="aura-panel__seccion">Estado de la cita</div>
        <div class="aura-panel__estados">${ESTADOS.map(([k, t, i]) => `<button type="button" class="aura-panel__estado${c.estado === k ? ' es-actual' : ''}" data-aura-estado="${k}">${ic(i)}<span>${t}</span></button>`).join('')}</div>
        ${Object.entries(grupos).map(([g, lista]) => `
          <div class="aura-panel__seccion">${escaparHtml(g)}</div>
          <div class="aura-panel__acciones">${lista.map(([k, t]) => `
            <a class="aura-accion" href="${BASE}/pacientes/nuevo_registro.php?paciente=${pid}&amp;tipo=${k}">
              <span class="aura-accion__icono">${ic(t.icono) || ic('documento')}</span>
              <span class="aura-accion__texto"><b>${escaparHtml(t.documento || t.corto)}</b><small>${escaparHtml(t.descripcion)}</small></span>
            </a>`).join('')}</div>`).join('')}
        <div class="aura-panel__seccion">Atajos</div>
        <div class="aura-panel__atajos">
          <a href="${BASE}/pacientes/ver.php?id=${pid}">${ic('usuario')}<span>Expediente</span></a>
          <a href="${BASE}/pacientes/ver.php?id=${pid}#historial">${ic('reloj')}<span>Historial</span></a>
          <a href="${BASE}/pacientes/imprimir.php?id=${pid}" target="_blank" rel="noopener">${ic('pdf') || ic('documento')}<span>Imprimir</span></a>
          <a href="${BASE}/agenda/?nueva=1&amp;paciente_id=${pid}">${ic('calendario')}<span>Siguiente cita</span></a>
        </div>
      </div>`;
    document.body.appendChild(panel);
    requestAnimationFrame(() => panel.classList.add('abierta'));
    const cerrar = () => { panel.classList.remove('abierta'); setTimeout(() => panel.remove(), 220); document.removeEventListener('keydown', tecla); };
    const tecla = (ev) => { if (ev.key === 'Escape') cerrar(); };
    document.addEventListener('keydown', tecla);
    panel.addEventListener('click', async (ev) => {
      if (ev.target.closest('[data-aura-panel-cerrar]')) { cerrar(); return; }
      const e = ev.target.closest('[data-aura-estado]');
      if (!e) return;
      try {
        await api('agenda/citas.php', { accion: 'estado', id: c.id, estado: e.dataset.auraEstado });
        c.estado = e.dataset.auraEstado;
        panel.querySelectorAll('[data-aura-estado]').forEach((b) => b.classList.toggle('es-actual', b === e));
        window.mostrarNotificacion?.('exito', `Cita marcada como «${e.textContent.trim()}».`);
        if (['atendida', 'no_asistio'].includes(c.estado)) { citas = citas.filter((x) => x.id !== c.id); pintar(); }
      } catch (err) { window.mostrarNotificacion?.('error', err.message); }
    });
  }

  // Para Configuración: encender o apagar el aura en este navegador
  window.AuraCitas = {
    apagada,
    fijar(apagar) { try { apagar ? localStorage.setItem(CLAVE_APAGADA, '1') : localStorage.removeItem(CLAVE_APAGADA); } catch (e) { /* opcional */ } pintar(); },
  };

  const ciclo = async () => {
    if (document.hidden) return;
    if (Date.now() - ultimaCarga > 120000) await cargar();
    pintar();
  };
  ciclo();
  setInterval(ciclo, 30000);
  document.addEventListener('visibilitychange', ciclo);
})();
