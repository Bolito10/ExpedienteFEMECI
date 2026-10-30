/* ==========================================================================
   Expedientes Clínicos — agenda de citas (Día, Semana, Mes y Lista)
   Clic en un horario libre crea una cita (arrastrar marca la duración); arrastrar
   una cita la mueve; estirar el borde inferior cambia la duración. A la izquierda,
   mini calendario y profesionales visibles; arriba, búsqueda por paciente.
   El panel lateral muestra el detalle y el formulario con controles propios.
   Usa api, abrirMenu, abrirPopover, cerrarPopover, confirmar, copiarEnlace, avatarHtml,
   escaparHtml, mostrarNotificacion e ico (espacio.js / app.js).
   ========================================================================== */
(() => {
  const raiz = document.querySelector('[data-agenda]');
  if (!raiz) return;
  const datos = JSON.parse(document.getElementById('agenda-datos').textContent);
  const cuerpo = raiz.querySelector('[data-agenda-cuerpo]');
  const cajon = document.querySelector('[data-agenda-cajon]');
  const miniEl = raiz.querySelector('[data-agenda-mini]');
  const profesionalesEl = raiz.querySelector('[data-agenda-profesionales]');
  const buscarEl = raiz.querySelector('[data-agenda-buscar]');
  const periodoEl = raiz.querySelector('[data-agenda-periodo]');
  const icono = (nombre) => datos.iconos[nombre] || ico(nombre);

  const ALTO_HORA = 48;
  const PASO = 15;              // minutos
  const JORNADA = [7, 21];      // horario habitual; fuera de él la rejilla se ve más oscura
  const DIAS_LISTA = 30;
  const DIAS_CORTOS = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
  const DIAS_LARGOS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
  const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  const MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  // Mismo orden que la barra lateral (encabezado.php): el color depende del id del profesional
  const COLORES = ['azul', 'verde', 'morado', 'naranja', 'rosa', 'cafe', 'amarillo', 'rojo'];
  const VISTAS = ['dia', 'semana', 'mes', 'lista'];
  const LIBERAN = ['cancelada', 'no_asistio'];   // estados que dejan libre el horario
  const MARCA = '<svg class="cal-marca" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>';

  const leer = (clave, defecto) => { try { const v = localStorage.getItem(`femeci-agenda-${clave}`); return v === null ? defecto : JSON.parse(v); } catch (e) { return defecto; } };
  const guardar = (clave, valor) => { try { localStorage.setItem(`femeci-agenda-${clave}`, JSON.stringify(valor)); } catch (e) { /* opcional */ } };

  /* ---------- Fechas ---------- */
  const dosDigitos = (n) => String(n).padStart(2, '0');
  const ymd = (d) => `${d.getFullYear()}-${dosDigitos(d.getMonth() + 1)}-${dosDigitos(d.getDate())}`;
  const hm = (d) => `${dosDigitos(d.getHours())}:${dosDigitos(d.getMinutes())}`;
  const textoMin = (m) => `${dosDigitos(Math.floor(m / 60) % 24)}:${dosDigitos(m % 60)}`;
  const sumarDias = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
  const inicioDia = (d) => { const x = new Date(d); x.setHours(0, 0, 0, 0); return x; };
  const indiceDia = (d) => (d.getDay() + 6) % 7;
  const lunes = (d) => sumarDias(inicioDia(d), -indiceDia(d));
  const mismoDia = (a, b) => ymd(a) === ymd(b);
  const minutosDelDia = (d) => d.getHours() * 60 + d.getMinutes();
  const aFecha = (texto) => new Date(texto.length === 16 ? `${texto}:00` : texto);
  const minutosEntre = (a, b) => Math.round((b - a) / 60000);
  const capital = (t) => t.charAt(0).toUpperCase() + t.slice(1);
  const fechaTitulo = (d) => `${DIAS_LARGOS[indiceDia(d)]} ${d.getDate()} de ${MESES[d.getMonth()]}`;
  const textoDuracion = (m) => (m < 60 ? `${m} min` : `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60} min` : ''}`);
  const etiquetaDia = (d) => ({ 0: 'Hoy', 1: 'Mañana', '-1': 'Ayer' }[Math.round((inicioDia(d) - inicioDia(new Date())) / 86400000)] || null);

  const colorDe = (id) => COLORES[id % COLORES.length];
  const profesional = (id) => datos.profesionales.find((p) => p.id === id);
  const tituloCita = (c) => c.paciente || c.motivo || 'Cita';
  const editable = (c) => !['atendida', 'cancelada'].includes(c.estado);

  /* ---------- Estado ---------- */
  // En el teléfono la agenda abre en Día (la semana no cabe); la vista elegida ahí se recuerda aparte
  const TELEFONO = window.matchMedia('(max-width: 640px)').matches;
  const CLAVE_VISTA = TELEFONO ? 'vista-telefono' : 'vista';
  const VISTA_INICIAL = TELEFONO ? 'dia' : 'semana';
  let vista = VISTAS.includes(leer(CLAVE_VISTA, VISTA_INICIAL)) ? leer(CLAVE_VISTA, VISTA_INICIAL) : VISTA_INICIAL;
  let fecha = new Date(`${datos.fecha}T00:00`);
  let mesMini = new Date(fecha.getFullYear(), fecha.getMonth(), 1);
  let citas = [];
  let diasMini = {};
  const ocultos = new Set(leer('ocultos', []).filter((id) => datos.profesionales.some((p) => p.id === id)));
  let verCanceladas = !!leer('canceladas', false);
  let busqueda = '';
  let resultados = null;      // citas encontradas mientras hay búsqueda activa
  let desplazar = true;       // en el próximo pintado, colocar la rejilla en la hora útil y animar
  let detalleId = null;       // cita abierta en el panel
  let arrastrando = false;
  let ignorarClic = false;

  const visible = (c) => !ocultos.has(c.profesional_id) && (verCanceladas || c.estado !== 'cancelada');
  const buscarCita = (id) => citas.find((c) => c.id === id) || resultados?.find((c) => c.id === id);
  const clasesCita = (c) => `cal-color--${colorDe(c.profesional_id)} cal-estado--${c.estado}${aFecha(c.fin) < new Date() ? ' es-pasada' : ''}${c.id === detalleId ? ' es-activa' : ''}`;

  /** Semanas que ocupa el mes de `fecha` (5 o 6, a veces 4). */
  const semanasMes = () => {
    const primero = new Date(fecha.getFullYear(), fecha.getMonth(), 1);
    const dias = new Date(fecha.getFullYear(), fecha.getMonth() + 1, 0).getDate();
    return Math.ceil((indiceDia(primero) + dias) / 7);
  };
  const rango = () => {
    if (vista === 'dia') { const d = inicioDia(fecha); return [d, sumarDias(d, 1)]; }
    if (vista === 'semana') { const d = lunes(fecha); return [d, sumarDias(d, 7)]; }
    if (vista === 'lista') { const d = inicioDia(fecha); return [d, sumarDias(d, DIAS_LISTA)]; }
    const inicio = lunes(new Date(fecha.getFullYear(), fecha.getMonth(), 1));
    return [inicio, sumarDias(inicio, semanasMes() * 7)];
  };

  /* ---------- Datos ---------- */
  let peticion = 0;
  async function cargar() {
    const n = ++peticion;
    const [desde, hasta] = rango();
    try {
      // Siempre con canceladas: el interruptor "Citas canceladas" filtra sin volver a pedir
      const r = await api(`agenda/citas.php?desde=${ymd(desde)}&hasta=${ymd(hasta)}&canceladas=1`);
      if (n !== peticion) return;
      citas = r.citas;
    } catch (e) {
      if (n !== peticion) return;
      citas = [];
      mostrarNotificacion('error', e.message);
    }
    pintar();
    cargarMini();
  }
  async function cargarMini() {
    const desde = lunes(mesMini);
    try {
      const r = await api(`agenda/citas.php?dias=1&desde=${ymd(desde)}&hasta=${ymd(sumarDias(desde, 42))}`);
      diasMini = r.dias || {};
    } catch (e) { diasMini = {}; }
    pintarMini();
  }
  const accion = (cuerpoPeticion) => api('agenda/citas.php', cuerpoPeticion);
  /** Vuelve a pedir el periodo (y la búsqueda, si hay) después de un cambio. */
  async function refrescar() {
    await cargar();
    if (busqueda) await ejecutarBusqueda();
  }

  /* ---------- Pintar ---------- */
  function textoPeriodo() {
    if (resultados) return 'Resultados de la búsqueda';
    const [desde, hasta] = rango();
    const fin = sumarDias(hasta, -1);
    if (vista === 'dia') return `${capital(fechaTitulo(fecha))} de ${fecha.getFullYear()}`;
    if (vista === 'mes') return `${capital(MESES[fecha.getMonth()])} de ${fecha.getFullYear()}`;
    if (vista === 'lista') {
      return `${desde.getDate()} ${MESES_CORTOS[desde.getMonth()]}${desde.getFullYear() !== fin.getFullYear() ? ` ${desde.getFullYear()}` : ''} – ${fin.getDate()} ${MESES_CORTOS[fin.getMonth()]} ${fin.getFullYear()}`;
    }
    if (desde.getMonth() === fin.getMonth()) return `${capital(MESES[desde.getMonth()])} de ${desde.getFullYear()}`;
    if (desde.getFullYear() === fin.getFullYear()) return `${capital(MESES_CORTOS[desde.getMonth()])} – ${MESES_CORTOS[fin.getMonth()]} de ${fin.getFullYear()}`;
    return `${capital(MESES_CORTOS[desde.getMonth()])} ${desde.getFullYear()} – ${MESES_CORTOS[fin.getMonth()]} ${fin.getFullYear()}`;
  }

  function pintar() {
    raiz.querySelectorAll('[data-vista]').forEach((b) => b.setAttribute('aria-pressed', String(!resultados && b.dataset.vista === vista)));
    periodoEl.textContent = textoPeriodo();
    const animar = desplazar;
    if (resultados) pintarResultados();
    else if (vista === 'mes') pintarMes();
    else if (vista === 'lista') pintarLista();
    else pintarDias(vista === 'dia' ? 1 : 7);
    desplazar = false;
    if (animar) {
      cuerpo.classList.remove('es-entrando');
      void cuerpo.offsetWidth;   // reinicia la animación
      cuerpo.classList.add('es-entrando');
    }
    pintarMini();
  }

  /**
   * Reparte en carriles las citas que se empalman en un mismo día. Cada cita se extiende
   * a la derecha mientras no choque con otra (como en los calendarios de escritorio).
   */
  function repartir(lista) {
    const orden = [...lista].sort((a, b) => a.inicio.localeCompare(b.inicio) || b.fin.localeCompare(a.fin));
    const resultado = new Map();
    let grupo = [];
    let finGrupo = '';
    const cerrarGrupo = () => {
      const carriles = [];
      grupo.forEach((c) => {
        let k = carriles.findIndex((fin) => fin <= c.inicio);
        if (k < 0) { k = carriles.length; carriles.push(''); }
        carriles[k] = c.fin;
        resultado.set(c.id, { carril: k, total: 0, ancho: 1 });
      });
      grupo.forEach((c) => {
        const r = resultado.get(c.id);
        r.total = carriles.length;
        for (let k = r.carril + 1; k < carriles.length; k++) {
          if (grupo.some((o) => o !== c && resultado.get(o.id).carril === k && o.inicio < c.fin && o.fin > c.inicio)) break;
          r.ancho++;
        }
      });
      grupo = [];
    };
    orden.forEach((c) => {
      if (grupo.length && c.inicio >= finGrupo) cerrarGrupo();
      grupo.push(c);
      if (grupo.length === 1 || c.fin > finGrupo) finGrupo = c.fin;
    });
    if (grupo.length) cerrarGrupo();
    return resultado;
  }

  const zonaHoraria = () => {
    const minutos = -new Date().getTimezoneOffset();
    return `GMT${minutos >= 0 ? '+' : '−'}${Math.abs(minutos) / 60}`;
  };

  function contenidoBloque(c, ini, fin, conProfesional) {
    const marca = ['confirmada', 'atendida'].includes(c.estado) ? MARCA : '';
    return `<span class="cal-evento__titulo">${marca}<span>${escaparHtml(tituloCita(c))}</span></span>
      <span class="cal-evento__hora">${hm(ini)}<span class="cal-evento__fin"> – ${hm(fin)}</span>${conProfesional ? `<span class="cal-evento__fin"> · ${escaparHtml(c.profesional)}</span>` : ''}</span>
      ${c.paciente && c.motivo ? `<span class="cal-evento__motivo">${escaparHtml(c.motivo)}</span>` : ''}
      ${editable(c) ? '<i class="cal-evento__estirar" data-estirar aria-hidden="true"></i>' : ''}`;
  }

  function bloqueCita(c, inicioColumna, reparto, conProfesional) {
    const ini = aFecha(c.inicio);
    const fin = aFecha(c.fin);
    const desdeMin = Math.max(0, minutosEntre(inicioColumna, ini));
    const hastaMin = Math.min(1440, minutosEntre(inicioColumna, fin));
    const alto = (Math.max(PASO, hastaMin - desdeMin) / 60) * ALTO_HORA - 2;
    const el = document.createElement('div');
    el.className = `cal-evento ${clasesCita(c)}${alto < 38 ? ' cal-evento--corto' : ''}`;
    el.dataset.cita = c.id;
    el.tabIndex = 0;
    el.setAttribute('role', 'button');
    el.setAttribute('aria-label', `${tituloCita(c)}, ${hm(ini)} a ${hm(fin)}, ${datos.estados[c.estado]}, ${c.profesional}`);
    const { carril, total, ancho } = reparto;
    el.style.cssText = `top:${(desdeMin / 60) * ALTO_HORA}px; height:${alto}px; left:calc(${(carril / total) * 100}% + 1px); width:calc(${(ancho / total) * 100}% - ${carril + ancho === total ? 8 : 3}px)`;
    el.innerHTML = contenidoBloque(c, ini, fin, conProfesional);
    return el;
  }

  function pintarDias(n) {
    const [desde] = rango();
    const hoy = new Date();
    const columnas = Array.from({ length: n }, (_, i) => sumarDias(desde, i));
    const anterior = cuerpo.querySelector('[data-rejilla]')?.scrollTop;
    const variosProfesionales = datos.profesionales.filter((p) => !ocultos.has(p.id)).length > 1;
    const delDia = (d) => {
      const inicio = inicioDia(d);
      const fin = sumarDias(inicio, 1);
      return citas.filter((c) => visible(c) && aFecha(c.inicio) < fin && aFecha(c.fin) > inicio);
    };
    cuerpo.className = `calendario__cuerpo calendario__cuerpo--tiempo${n === 1 ? ' calendario__cuerpo--dia' : ''}`;
    cuerpo.innerHTML = `
      <div class="cal-tiempo__cabecera" style="--dias:${n}">
        <span class="cal-tiempo__zona">${zonaHoraria()}</span>
        ${columnas.map((d) => {
          const total = delDia(d).filter((c) => c.estado !== 'cancelada').length;
          return `<button type="button" class="cal-tiempo__dia${mismoDia(d, hoy) ? ' es-hoy' : ''}${d < inicioDia(hoy) ? ' es-pasado' : ''}" data-ir-dia="${ymd(d)}" aria-label="Ver ${fechaTitulo(d)}">
            <span>${DIAS_CORTOS[indiceDia(d)]}</span><b>${d.getDate()}</b>${n === 1 ? `<small>${total ? `${total} ${total === 1 ? 'cita' : 'citas'}` : 'Sin citas'}</small>` : ''}
          </button>`;
        }).join('')}
      </div>
      <div class="cal-tiempo__scroll" data-rejilla>
        <div class="cal-tiempo__rejilla" style="--dias:${n}; --alto-hora:${ALTO_HORA}px">
          <div class="cal-tiempo__horas" aria-hidden="true">${Array.from({ length: 23 }, (_, i) => `<span data-hora-fija="${i + 1}" style="top:${(i + 1) * ALTO_HORA}px">${dosDigitos(i + 1)}:00</span>`).join('')}</div>
          ${columnas.map((d) => `<div class="cal-tiempo__columna${mismoDia(d, hoy) ? ' es-hoy' : ''}${indiceDia(d) > 4 ? ' es-finde' : ''}" data-dia="${ymd(d)}">
            <i class="cal-tiempo__fuera" style="top:0; height:${JORNADA[0] * ALTO_HORA}px"></i><i class="cal-tiempo__fuera" style="top:${JORNADA[1] * ALTO_HORA}px; bottom:0"></i>
          </div>`).join('')}
        </div>
      </div>`;
    let primera = null;
    columnas.forEach((d) => {
      const columna = cuerpo.querySelector(`[data-dia="${ymd(d)}"]`);
      const lista = delDia(d);
      const reparto = repartir(lista);
      lista.forEach((c) => {
        columna.appendChild(bloqueCita(c, inicioDia(d), reparto.get(c.id), n === 1 && variosProfesionales));
        const m = Math.max(0, minutosEntre(inicioDia(d), aFecha(c.inicio)));
        if (primera === null || m < primera) primera = m;
      });
      if (mismoDia(d, hoy)) {
        const linea = document.createElement('div');
        linea.className = 'cal-ahora';
        linea.dataset.ahora = '';
        columna.appendChild(linea);
        cuerpo.querySelector('.cal-tiempo__horas').insertAdjacentHTML('beforeend', '<span class="cal-ahora__hora" data-ahora-hora></span>');
      }
    });
    colocarAhora();
    const rejilla = cuerpo.querySelector('[data-rejilla]');
    if (!desplazar && anterior != null) rejilla.scrollTop = anterior;
    else {
      // Hoy: un poco antes de la hora actual; otro periodo: la primera cita o el inicio de la jornada
      const objetivo = columnas.some((d) => mismoDia(d, hoy))
        ? Math.max(JORNADA[0] * 60 - 30, minutosDelDia(hoy) - 120)
        : Math.min(primera ?? JORNADA[0] * 60, JORNADA[0] * 60) - 30;
      rejilla.scrollTop = Math.max(0, (objetivo / 60) * ALTO_HORA);
    }
  }

  /** Línea de la hora actual (se mueve sola cada 30 s). */
  function colocarAhora() {
    const ahora = new Date();
    const top = `${(minutosDelDia(ahora) / 60) * ALTO_HORA}px`;
    cuerpo.querySelectorAll('[data-ahora]').forEach((l) => { l.style.top = top; });
    cuerpo.querySelectorAll('[data-ahora-hora]').forEach((l) => { l.style.top = top; l.textContent = hm(ahora); });
    // La hora fija que queda debajo de la etiqueta roja se oculta para que no se encimen
    const conAhora = !!cuerpo.querySelector('[data-ahora-hora]');
    cuerpo.querySelectorAll('[data-hora-fija]').forEach((s) => { s.style.visibility = conAhora && Math.abs(Number(s.dataset.horaFija) * 60 - minutosDelDia(ahora)) < 16 ? 'hidden' : ''; });
  }
  let diaActual = ymd(new Date());
  setInterval(() => {
    if (ymd(new Date()) !== diaActual) { diaActual = ymd(new Date()); if (!arrastrando) cargar(); return; }
    colocarAhora();
  }, 30000);

  const filaMes = (c) => `<button type="button" class="cal-mes__evento ${clasesCita(c)}" data-cita="${c.id}" aria-label="${escaparHtml(`${tituloCita(c)}, ${c.inicio.slice(11, 16)}, ${datos.estados[c.estado]}`)}">
      <i aria-hidden="true"></i><span class="cal-mes__hora">${c.inicio.slice(11, 16)}</span><span class="cal-mes__texto">${escaparHtml(tituloCita(c))}</span></button>`;
  const citasDelDia = (dia, filtrar = true) => citas.filter((c) => (!filtrar || visible(c)) && c.inicio.slice(0, 10) === dia).sort((a, b) => a.inicio.localeCompare(b.inicio));

  function pintarMes() {
    const [desde, hasta] = rango();
    const hoy = new Date();
    const dias = minutosEntre(desde, hasta) / 1440;
    let celdas = '';
    for (let i = 0; i < Math.round(dias); i++) {
      const d = sumarDias(desde, i);
      const fuera = d.getMonth() !== fecha.getMonth();
      celdas += `<div class="cal-mes__celda${fuera ? ' es-fuera' : ''}${mismoDia(d, hoy) ? ' es-hoy' : ''}${indiceDia(d) > 4 ? ' es-finde' : ''}" data-celda="${ymd(d)}">
        <button type="button" class="cal-mes__numero" data-ir-dia="${ymd(d)}" aria-label="Ver ${fechaTitulo(d)}">${d.getDate() === 1 ? `${d.getDate()} ${MESES_CORTOS[d.getMonth()]}` : d.getDate()}</button>
        <div class="cal-mes__eventos">${citasDelDia(ymd(d)).map(filaMes).join('')}</div>
      </div>`;
    }
    cuerpo.className = 'calendario__cuerpo calendario__cuerpo--mes';
    cuerpo.innerHTML = `<div class="cal-mes__semana">${DIAS_CORTOS.map((d) => `<span>${d}</span>`).join('')}</div>
      <div class="cal-mes" style="--semanas:${Math.round(dias / 7)}">${celdas}</div>`;
    recortarMes();
  }

  /** Oculta las citas que no caben en cada celda y deja "N más". */
  function recortarMes() {
    cuerpo.querySelectorAll('.cal-mes__celda').forEach((celda) => {
      const lista = celda.querySelector('.cal-mes__eventos');
      lista.querySelector('.cal-mes__mas')?.remove();
      const filas = [...lista.children];
      filas.forEach((f) => { f.hidden = false; });
      if (!filas.length || lista.scrollHeight <= lista.clientHeight + 1) return;
      const caben = Math.max(0, Math.floor(lista.clientHeight / (filas[0].offsetHeight + 1)) - 1);
      filas.slice(caben).forEach((f) => { f.hidden = true; });
      lista.insertAdjacentHTML('beforeend', `<button type="button" class="cal-mes__mas" data-mas-dia="${celda.dataset.celda}">${filas.length - caben} más</button>`);
    });
  }

  function abrirMasDia(boton) {
    const d = new Date(`${boton.dataset.masDia}T00:00`);
    const el = abrirPopover(boton, `<div class="cal-dia-popover">
        <button type="button" class="cal-dia-popover__titulo" data-ir-dia="${boton.dataset.masDia}">${capital(fechaTitulo(d))}</button>
        <div class="cal-dia-popover__lista">${citasDelDia(boton.dataset.masDia).map(filaMes).join('')}</div>
      </div>`, { clase: 'cal-popover' });
    el.addEventListener('click', (ev) => {
      const fila = ev.target.closest('[data-cita]');
      const ir = ev.target.closest('[data-ir-dia]');
      if (fila) { cerrarPopover(); abrirDetalle(Number(fila.dataset.cita)); }
      if (ir) { cerrarPopover(); irA(d); cambiarVista('dia'); }
    });
  }

  /** Lista agrupada por día (vista Lista y resultados de búsqueda). */
  function htmlLista(lista, conAnio) {
    const grupos = new Map();
    lista.forEach((c) => {
      const k = c.inicio.slice(0, 10);
      if (!grupos.has(k)) grupos.set(k, []);
      grupos.get(k).push(c);
    });
    const hoy = new Date();
    return [...grupos].map(([dia, items]) => {
      const d = new Date(`${dia}T00:00`);
      const etiqueta = etiquetaDia(d);
      return `<section class="cal-lista__dia${mismoDia(d, hoy) ? ' es-hoy' : ''}">
        <h3 class="cal-lista__fecha"><button type="button" data-ir-dia="${dia}"><b>${etiqueta || capital(DIAS_LARGOS[indiceDia(d)])}</b><span>${etiqueta ? `${DIAS_LARGOS[indiceDia(d)]} ` : ''}${d.getDate()} de ${MESES[d.getMonth()]}${conAnio || d.getFullYear() !== hoy.getFullYear() ? ` de ${d.getFullYear()}` : ''}</span></button></h3>
        <div class="cal-lista__citas">${items.map((c) => {
          const ini = aFecha(c.inicio);
          const fin = aFecha(c.fin);
          return `<button type="button" class="cal-lista__cita ${clasesCita(c)}" data-cita="${c.id}">
            <span class="cal-lista__hora">${hm(ini)} – ${hm(fin)}</span>
            <i class="cal-lista__color" aria-hidden="true"></i>
            <span class="cal-lista__texto"><b>${escaparHtml(tituloCita(c))}</b>${c.paciente && c.motivo ? `<small>${escaparHtml(c.motivo)}</small>` : ''}</span>
            <span class="cal-lista__profesional">${avatarHtml(c.profesional_id, c.profesional, '', c.profesional_foto)}<span>${escaparHtml(c.profesional)}</span></span>
            <span class="cal-lista__estado"><i class="cal-punto cal-punto--${c.estado}" aria-hidden="true"></i>${escaparHtml(datos.estados[c.estado])}</span>
          </button>`;
        }).join('')}</div>
      </section>`;
    }).join('');
  }

  /** Estado vacío: sin datos de ejemplo; si hay citas ocultas por filtros, lo dice. */
  function htmlVacio(titulo, texto) {
    return `<div class="cal-vacio">
      <p class="cal-vacio__titulo">${escaparHtml(titulo)}</p>
      <p class="cal-vacio__texto">${escaparHtml(texto)}</p>
      <div class="cal-vacio__acciones">
        ${resultados ? '<button type="button" class="boton boton--secundario" data-limpiar-busqueda>Limpiar búsqueda</button>' : '<button type="button" class="boton boton--secundario" data-nueva-vacio>Nueva cita</button>'}
      </div>
    </div>`;
  }

  function pintarLista() {
    const [desde, hasta] = rango();
    const enRango = citas.filter((c) => aFecha(c.inicio) >= desde && aFecha(c.inicio) < hasta);
    const lista = enRango.filter(visible).sort((a, b) => a.inicio.localeCompare(b.inicio));
    const ocultas = enRango.length - lista.length;
    cuerpo.className = 'calendario__cuerpo calendario__cuerpo--lista';
    const aviso = ocultas ? `<p class="cal-lista__resumen">${ocultas} ${ocultas === 1 ? 'cita oculta' : 'citas ocultas'} por los filtros. <button type="button" class="cal-enlace" data-mostrar-todo>Mostrar todas</button></p>` : '';
    cuerpo.innerHTML = `<div class="cal-lista">${aviso}${lista.length
      ? htmlLista(lista, false)
      : htmlVacio(`Sin citas del ${desde.getDate()} ${MESES_CORTOS[desde.getMonth()]} al ${sumarDias(hasta, -1).getDate()} ${MESES_CORTOS[sumarDias(hasta, -1).getMonth()]}`,
        'Las citas que agendes aparecerán aquí, ordenadas por día.')}</div>`;
  }

  function pintarResultados() {
    const ahora = new Date();
    const proximas = resultados.filter((c) => aFecha(c.fin) >= ahora).sort((a, b) => a.inicio.localeCompare(b.inicio));
    const pasadas = resultados.filter((c) => aFecha(c.fin) < ahora);
    const n = resultados.length;
    cuerpo.className = 'calendario__cuerpo calendario__cuerpo--lista';
    cuerpo.innerHTML = `<div class="cal-lista">${n
      ? `<p class="cal-lista__resumen">${n === 1 ? 'Una cita coincide' : `${n} citas coinciden`} con «${escaparHtml(busqueda)}». <button type="button" class="cal-enlace" data-limpiar-busqueda>Limpiar búsqueda</button></p>
        ${proximas.length ? `<h2 class="cal-lista__grupo">Próximas</h2>${htmlLista(proximas, true)}` : ''}
        ${pasadas.length ? `<h2 class="cal-lista__grupo">Anteriores</h2>${htmlLista(pasadas, true)}` : ''}`
      : htmlVacio(`Ninguna cita coincide con «${busqueda}»`, 'Busca por nombre del paciente, folio, CURP o motivo de la cita.')}</div>`;
  }

  /* ---------- Mini calendario (columna izquierda y selector de fecha) ---------- */
  function htmlMiniMes(mes, { seleccion = null, desde = null, hasta = null, dias = {} } = {}) {
    const inicio = lunes(mes);
    const hoy = new Date();
    let celdas = '';
    for (let i = 0; i < 42; i++) {
      const d = sumarDias(inicio, i);
      const clave = ymd(d);
      const clases = [
        d.getMonth() !== mes.getMonth() && 'es-fuera',
        mismoDia(d, hoy) && 'es-hoy',
        seleccion && mismoDia(d, seleccion) && 'es-seleccion',
        desde && d >= desde && d < hasta && 'en-rango',
        dias[clave] && 'con-citas',
      ].filter(Boolean).join(' ');
      celdas += `<button type="button" class="cal-mini__dia ${clases}" data-mini-dia="${clave}" aria-label="${capital(fechaTitulo(d))}${dias[clave] ? `, ${dias[clave]} ${dias[clave] === 1 ? 'cita' : 'citas'}` : ''}"${seleccion && mismoDia(d, seleccion) ? ' aria-current="date"' : ''}>${d.getDate()}</button>`;
    }
    return `<div class="cal-mini__cabecera">
        <span class="cal-mini__mes">${capital(MESES[mes.getMonth()])} ${mes.getFullYear()}</span>
        <button type="button" class="boton-icono" data-mini-mover="-1" aria-label="Mes anterior">${icono('atras')}</button>
        <button type="button" class="boton-icono" data-mini-mover="1" aria-label="Mes siguiente">${icono('adelante')}</button>
      </div>
      <div class="cal-mini__rejilla">${['L', 'M', 'M', 'J', 'V', 'S', 'D'].map((l) => `<span class="cal-mini__semana" aria-hidden="true">${l}</span>`).join('')}${celdas}</div>`;
  }

  function pintarMini() {
    const [desde, hasta] = rango();
    const conRango = !resultados && (vista === 'semana' || vista === 'dia');
    miniEl.innerHTML = htmlMiniMes(mesMini, { seleccion: resultados ? null : fecha, desde: conRango ? desde : null, hasta, dias: diasMini });
  }
  miniEl.addEventListener('click', (ev) => {
    const mover = ev.target.closest('[data-mini-mover]');
    if (mover) {
      mesMini = new Date(mesMini.getFullYear(), mesMini.getMonth() + Number(mover.dataset.miniMover), 1);
      pintarMini();
      cargarMini();
      return;
    }
    const dia = ev.target.closest('[data-mini-dia]');
    if (dia) {
      if (resultados) limpiarBusqueda(false);
      irA(new Date(`${dia.dataset.miniDia}T00:00`));
      cargar();
    }
  });

  /* ---------- Profesionales visibles ---------- */
  function pintarProfesionales() {
    profesionalesEl.innerHTML = datos.profesionales.length
      ? datos.profesionales.map((p) => `<div class="cal-profesional cal-color--${colorDe(p.id)}">
          <button type="button" class="cal-profesional__casilla" role="checkbox" aria-checked="${!ocultos.has(p.id)}" data-prof-alternar="${p.id}" title="${escaparHtml(`${p.nombre} · ${p.rol}`)}">
            <i aria-hidden="true">${MARCA}</i><span>${escaparHtml(p.nombre)}</span>${p.id === datos.yo ? '<small>Tú</small>' : ''}
          </button>
          ${datos.profesionales.length > 1 ? `<button type="button" class="cal-profesional__solo" data-prof-solo="${p.id}" aria-label="Ver solo a ${escaparHtml(p.nombre)}">Solo</button>` : ''}
        </div>`).join('')
      : '<p class="cal-rail-vacio">No hay profesionales activos.</p>';
    raiz.querySelector('[data-profesionales-todos]').hidden = !ocultos.size;
    const canceladas = raiz.querySelector('[data-ver-canceladas]');
    canceladas.setAttribute('aria-checked', String(verCanceladas));
  }
  const filtrosCambiados = () => {
    guardar('ocultos', [...ocultos]);
    guardar('canceladas', verCanceladas);
    pintarProfesionales();
    pintar();
  };
  profesionalesEl.addEventListener('click', (ev) => {
    const alternar = ev.target.closest('[data-prof-alternar]');
    const solo = ev.target.closest('[data-prof-solo]');
    if (alternar) {
      const id = Number(alternar.dataset.profAlternar);
      if (ocultos.has(id)) ocultos.delete(id); else ocultos.add(id);
    } else if (solo) {
      const id = Number(solo.dataset.profSolo);
      datos.profesionales.forEach((p) => { if (p.id === id) ocultos.delete(p.id); else ocultos.add(p.id); });
    } else return;
    filtrosCambiados();
  });
  raiz.querySelector('[data-profesionales-todos]').addEventListener('click', () => { ocultos.clear(); filtrosCambiados(); });
  raiz.querySelector('[data-ver-canceladas]').addEventListener('click', () => { verCanceladas = !verCanceladas; filtrosCambiados(); });

  /* ---------- Navegación ---------- */
  const irA = (d) => {
    fecha = inicioDia(d);
    mesMini = new Date(fecha.getFullYear(), fecha.getMonth(), 1);
    desplazar = true;
  };
  const mover = (paso) => {
    if (resultados) return;
    if (vista === 'mes') irA(new Date(fecha.getFullYear(), fecha.getMonth() + paso, 1));
    else irA(sumarDias(fecha, paso * ({ dia: 1, semana: 7, lista: DIAS_LISTA }[vista])));
    cargar();
  };
  const irHoy = () => { if (resultados) limpiarBusqueda(false); irA(new Date()); cargar(); };
  function cambiarVista(nueva) {
    if (resultados) limpiarBusqueda(false);
    vista = nueva;
    desplazar = true;
    guardar(CLAVE_VISTA, vista);
    cargar();
  }
  function nuevaCita(extra = {}) {
    const hoy = new Date();
    const [desde, hasta] = rango();
    let dia = vista === 'dia' ? fecha : vista === 'mes' ? new Date(fecha.getFullYear(), fecha.getMonth(), 1) : desde;
    if (resultados || (hoy >= desde && hoy < hasta)) dia = hoy;
    const inicio = inicioDia(dia);
    if (mismoDia(dia, hoy)) inicio.setHours(Math.min(hoy.getHours() + 1, 23), 0, 0, 0); else inicio.setHours(9, 0, 0, 0);
    abrirFormulario({ inicio: `${ymd(inicio)}T${hm(inicio)}`, ...extra });
  }

  raiz.querySelector('[data-agenda-anterior]').addEventListener('click', () => mover(-1));
  raiz.querySelector('[data-agenda-siguiente]').addEventListener('click', () => mover(1));
  raiz.querySelector('[data-agenda-hoy]').addEventListener('click', irHoy);
  raiz.querySelectorAll('[data-vista]').forEach((b) => b.addEventListener('click', () => cambiarVista(b.dataset.vista)));
  raiz.querySelector('[data-agenda-nueva]').addEventListener('click', () => nuevaCita());

  // Columna izquierda: se recuerda si estaba oculta; en pantallas angostas empieza oculta
  const botonRail = raiz.querySelector('[data-agenda-rail-alternar]');
  const fijarRail = (abierto) => {
    raiz.classList.toggle('calendario--sin-rail', !abierto);
    botonRail.setAttribute('aria-pressed', String(abierto));
    if (vista === 'mes' && !resultados) requestAnimationFrame(recortarMes);
  };
  fijarRail(leer('rail', raiz.offsetWidth >= 900));
  botonRail.addEventListener('click', () => {
    const abierto = raiz.classList.contains('calendario--sin-rail');
    fijarRail(abierto);
    guardar('rail', abierto);
  });

  function mostrarAtajos() {
    const boton = raiz.querySelector('[data-agenda-atajos]');
    const ancla = boton.offsetParent ? boton : periodoEl;   // con la columna oculta, junto al periodo
    const atajos = [['T', 'Ir a hoy'], ['D', 'Vista de día'], ['W', 'Vista de semana'], ['M', 'Vista de mes'], ['L', 'Lista de citas'],
      ['N', 'Nueva cita'], ['← →', 'Periodo anterior o siguiente'], ['/', 'Buscar paciente'], ['E', 'Editar la cita abierta'],
      ['Esc', 'Cerrar el panel'], ['Ctrl Enter', 'Guardar la cita']];
    abrirPopover(ancla, `<div class="cal-atajos">
        <p class="cal-atajos__titulo">Atajos de teclado</p>
        <dl>${atajos.map(([k, t]) => `<div><dt>${k.split(' ').map((x) => `<kbd>${x}</kbd>`).join('')}</dt><dd>${t}</dd></div>`).join('')}</dl>
      </div>`, { clase: 'cal-popover', arriba: ancla !== periodoEl });
  }
  raiz.querySelector('[data-agenda-atajos]').addEventListener('click', mostrarAtajos);

  /* ---------- Búsqueda por paciente ---------- */
  const limpiarBusquedaBoton = raiz.querySelector('[data-agenda-buscar-limpiar]');
  let temporizadorBusqueda = null;
  async function ejecutarBusqueda() {
    const texto = busqueda;
    try {
      const r = await api(`agenda/citas.php?buscar=${encodeURIComponent(texto)}`);
      if (texto !== busqueda) return;
      resultados = r.citas;
    } catch (e) { mostrarNotificacion('error', e.message); return; }
    pintar();
  }
  function limpiarBusqueda(repintar = true) {
    clearTimeout(temporizadorBusqueda);
    busqueda = '';
    buscarEl.value = '';
    limpiarBusquedaBoton.hidden = true;
    if (resultados) { resultados = null; desplazar = true; if (repintar) pintar(); }
  }
  buscarEl.addEventListener('input', () => {
    clearTimeout(temporizadorBusqueda);
    const texto = buscarEl.value.trim();
    limpiarBusquedaBoton.hidden = !buscarEl.value;
    if (texto.length < 2) { if (resultados) { busqueda = ''; resultados = null; desplazar = true; pintar(); } return; }
    temporizadorBusqueda = setTimeout(() => { busqueda = texto; ejecutarBusqueda(); }, 250);
  });
  buscarEl.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') { ev.preventDefault(); limpiarBusqueda(); buscarEl.blur(); }
    if (ev.key === 'Enter') { ev.preventDefault(); cuerpo.querySelector('[data-cita]')?.focus(); }
  });
  limpiarBusquedaBoton.addEventListener('click', () => { limpiarBusqueda(); buscarEl.focus(); });

  /* ---------- Teclado ---------- */
  document.addEventListener('keydown', (ev) => {
    if (ev.defaultPrevented) return;
    const enCampo = ev.target.closest('input, textarea, select, [contenteditable="true"], [contenteditable="plaintext-only"]');
    if (ev.key === 'Escape') {
      if (!cajon.hidden && !document.querySelector('.popover')) { ev.preventDefault(); cerrarCajon(); }
      return;
    }
    if (enCampo || ev.ctrlKey || ev.metaKey || ev.altKey || document.querySelector('.popover') || arrastrando) return;
    let tecla = ev.key.length === 1 ? ev.key.toLowerCase() : ev.key;
    if (tecla === '/' && ev.shiftKey) tecla = '?';   // teclados donde "?" llega como Mayús + /
    // Dentro del panel solo cuenta "E" (editar); el resto de las teclas sirven a sus controles
    if (ev.target.closest('.cal-cajon') && tecla !== 'e') return;
    const acciones = {
      t: irHoy, d: () => cambiarVista('dia'), w: () => cambiarVista('semana'), s: () => cambiarVista('semana'),
      m: () => cambiarVista('mes'), l: () => cambiarVista('lista'), n: () => nuevaCita(),
      ArrowLeft: () => mover(-1), ArrowRight: () => mover(1), j: () => mover(1), k: () => mover(-1),
      '/': () => buscarEl.focus(), '?': mostrarAtajos,
      e: () => { const c = detalleId && buscarCita(detalleId); if (c && editable(c)) abrirFormulario(c); },
    };
    if (!acciones[tecla]) return;
    // Las flechas sobre un botón de la barra o del mini calendario no deben cambiar el periodo dos veces
    if (tecla.startsWith('Arrow') && ev.target.closest('.popover, [role="menu"], .segmentado')) return;
    ev.preventDefault();
    acciones[tecla]();
  });

  /* ---------- Clic, arrastrar y estirar ---------- */
  cuerpo.addEventListener('click', (ev) => {
    if (ignorarClic) { ignorarClic = false; return; }
    const irDia = ev.target.closest('[data-ir-dia]');
    if (irDia) { if (resultados) limpiarBusqueda(false); irA(new Date(`${irDia.dataset.irDia}T00:00`)); cambiarVista('dia'); return; }
    const mas = ev.target.closest('[data-mas-dia]');
    if (mas) { abrirMasDia(mas); return; }
    const fila = ev.target.closest('.cal-mes__evento, .cal-lista__cita');
    if (fila) { abrirDetalle(Number(fila.dataset.cita)); return; }
    if (ev.target.closest('[data-nueva-vacio]')) { nuevaCita(); return; }
    if (ev.target.closest('[data-limpiar-busqueda]')) { limpiarBusqueda(); return; }
    if (ev.target.closest('[data-mostrar-todo]')) { ocultos.clear(); verCanceladas = true; filtrosCambiados(); return; }
    const celda = ev.target.closest('[data-celda]');
    if (celda && vista === 'mes' && !ev.target.closest('button')) abrirFormulario({ inicio: `${celda.dataset.celda}T09:00` });
  });
  cuerpo.addEventListener('keydown', (ev) => {
    const el = ev.target.closest('.cal-evento');
    if (el && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); abrirDetalle(Number(el.dataset.cita)); }
  });

  const minutoEnColumna = (columna, clientY) => {
    const r = columna.getBoundingClientRect();
    const minutos = ((clientY - r.top) / ALTO_HORA) * 60;
    return Math.max(0, Math.min(1440 - PASO, Math.floor(minutos / PASO) * PASO));
  };
  /** Mientras se arrastra cerca del borde, la rejilla se desplaza sola. */
  const desplazarAlBorde = (clientY) => {
    const rejilla = cuerpo.querySelector('[data-rejilla]');
    if (!rejilla) return;
    const r = rejilla.getBoundingClientRect();
    if (clientY < r.top + 36) rejilla.scrollTop -= 14;
    else if (clientY > r.bottom - 36) rejilla.scrollTop += 14;
  };

  // Semana y día
  cuerpo.addEventListener('pointerdown', (ev) => {
    if (ev.button !== 0 || resultados || vista === 'mes' || vista === 'lista') return;
    const el = ev.target.closest('.cal-evento');
    const columna = ev.target.closest('[data-dia]');
    if (!columna) return;
    // Horario libre: clic para crear (arrastrar hacia arriba o abajo marca la duración)
    if (!el) {
      ev.preventDefault();
      arrastrando = true;
      const ancla = minutoEnColumna(columna, ev.clientY);
      let inicioMin = ancla;
      let duracion = 30;
      const marca = document.createElement('div');
      marca.className = 'cal-evento cal-evento--nueva';
      const actualizar = () => {
        marca.style.cssText = `top:${(inicioMin / 60) * ALTO_HORA}px; height:${(duracion / 60) * ALTO_HORA - 2}px; left:1px; width:calc(100% - 8px)`;
        marca.classList.toggle('cal-evento--corto', (duracion / 60) * ALTO_HORA < 38);
        marca.innerHTML = `<span class="cal-evento__titulo"><span>Nueva cita</span></span><span class="cal-evento__hora">${textoMin(inicioMin)}<span class="cal-evento__fin"> – ${textoMin(inicioMin + duracion)}</span></span>`;
      };
      actualizar();
      columna.appendChild(marca);
      let movido = false;
      const mov = (e) => {
        desplazarAlBorde(e.clientY);
        const actual = minutoEnColumna(columna, e.clientY);
        if (!movido && actual === ancla) return;
        movido = true;
        inicioMin = Math.min(ancla, actual);
        duracion = Math.min(datos.duracionMaxima, Math.max(PASO, Math.abs(actual - ancla) + PASO));
        actualizar();
      };
      const fin = () => {
        document.removeEventListener('pointermove', mov);
        document.removeEventListener('pointercancel', fin);
        document.removeEventListener('pointerup', fin);
        arrastrando = false;
        abrirFormulario({ inicio: `${columna.dataset.dia}T${textoMin(inicioMin)}`, duracion }, () => marca.remove());
      };
      document.addEventListener('pointermove', mov);
      document.addEventListener('pointerup', fin, { once: true });
      document.addEventListener('pointercancel', fin, { once: true });
      return;
    }
    const cita = buscarCita(Number(el.dataset.cita));
    if (!cita) return;
    ev.preventDefault();
    if (!editable(cita)) { abrirDetalle(cita.id); return; }
    arrastrando = true;
    const estirar = !!ev.target.closest('[data-estirar]');
    const inicioY = ev.clientY;
    const rejilla = cuerpo.querySelector('[data-rejilla]');
    const scrollInicial = rejilla.scrollTop;
    const altoInicial = el.offsetHeight;
    const topInicial = el.offsetTop;
    const duracionOriginal = minutosEntre(aFecha(cita.inicio), aFecha(cita.fin));
    let movido = false;
    let destino = columna;
    const hora = el.querySelector('.cal-evento__hora');
    const mov = (e) => {
      if (!movido && Math.abs(e.clientY - inicioY) < 4 && !estirar) return;
      movido = true;
      el.classList.add('es-moviendo');
      desplazarAlBorde(e.clientY);
      const delta = Math.round((((e.clientY - inicioY + rejilla.scrollTop - scrollInicial) / ALTO_HORA) * 60) / PASO) * PASO;
      if (estirar) {
        const minutos = Math.min(datos.duracionMaxima, Math.max(PASO, Math.round(((altoInicial + 2) / ALTO_HORA) * 60 / PASO) * PASO + delta));
        el.style.height = `${(minutos / 60) * ALTO_HORA - 2}px`;
        const ini = Math.round((topInicial / ALTO_HORA) * 60);
        hora.innerHTML = `${textoMin(ini)}<span class="cal-evento__fin"> – ${textoMin(ini + minutos)}</span>`;
      } else {
        const ini = Math.max(0, Math.min(1440 - PASO, Math.round((topInicial / ALTO_HORA) * 60) + delta));
        el.style.top = `${(ini / 60) * ALTO_HORA}px`;
        el.style.left = '1px';
        el.style.width = 'calc(100% - 8px)';
        const bajo = document.elementsFromPoint(e.clientX, e.clientY).find((nodo) => nodo.matches?.('[data-dia]'));
        if (bajo && bajo !== destino) { destino = bajo; bajo.appendChild(el); }
        hora.innerHTML = `${textoMin(ini)}<span class="cal-evento__fin"> – ${textoMin(ini + duracionOriginal)}</span>`;
      }
    };
    const fin = async () => {
      document.removeEventListener('pointermove', mov);
      document.removeEventListener('pointercancel', fin);
      document.removeEventListener('pointerup', fin);
      el.classList.remove('es-moviendo');
      arrastrando = false;
      if (!movido) { abrirDetalle(cita.id); return; }
      let cuerpoPeticion;
      if (estirar) {
        const duracion = Math.round(((el.offsetHeight + 2) / ALTO_HORA) * 60 / PASO) * PASO;
        if (duracion === duracionOriginal) { pintar(); return; }
        cuerpoPeticion = { accion: 'mover', id: cita.id, duracion };
      } else {
        const inicio = `${destino.dataset.dia}T${textoMin(Math.round((el.offsetTop / ALTO_HORA) * 60 / PASO) * PASO)}`;
        if (inicio === cita.inicio) { pintar(); return; }
        cuerpoPeticion = { accion: 'mover', id: cita.id, inicio };
      }
      try {
        await accion(cuerpoPeticion);
        mostrarNotificacion('exito', estirar ? 'Duración actualizada.' : 'Cita reprogramada.');
      } catch (e) { mostrarNotificacion('error', e.message); }
      await refrescar();
      if (detalleId === cita.id) abrirDetalle(cita.id);
    };
    document.addEventListener('pointermove', mov);
    document.addEventListener('pointerup', fin, { once: true });
    document.addEventListener('pointercancel', fin, { once: true });
  });

  // Mes: arrastrar una cita a otro día conserva la hora
  cuerpo.addEventListener('pointerdown', (ev) => {
    if (ev.button !== 0 || resultados || vista !== 'mes') return;
    const el = ev.target.closest('.cal-mes__evento');
    if (!el) return;
    const cita = buscarCita(Number(el.dataset.cita));
    if (!cita || !editable(cita)) return;
    const x0 = ev.clientX;
    const y0 = ev.clientY;
    let movido = false;
    let destino = null;
    const mov = (e) => {
      if (!movido && Math.hypot(e.clientX - x0, e.clientY - y0) < 5) return;
      if (!movido) { movido = true; arrastrando = true; el.classList.add('es-moviendo'); }
      const celda = document.elementsFromPoint(e.clientX, e.clientY).find((n) => n.matches?.('[data-celda]'));
      if (celda !== destino) {
        destino?.classList.remove('es-destino');
        destino = celda || null;
        destino?.classList.add('es-destino');
      }
    };
    const fin = async () => {
      document.removeEventListener('pointermove', mov);
      document.removeEventListener('pointercancel', fin);
      document.removeEventListener('pointerup', fin);
      if (!movido) return;
      arrastrando = false;
      ignorarClic = true;
      setTimeout(() => { ignorarClic = false; }, 0);
      el.classList.remove('es-moviendo');
      destino?.classList.remove('es-destino');
      if (!destino || destino.dataset.celda === cita.inicio.slice(0, 10)) return;
      try {
        await accion({ accion: 'mover', id: cita.id, inicio: `${destino.dataset.celda}T${cita.inicio.slice(11, 16)}` });
        mostrarNotificacion('exito', 'Cita reprogramada.');
      } catch (e) { mostrarNotificacion('error', e.message); }
      refrescar();
    };
    document.addEventListener('pointermove', mov);
    document.addEventListener('pointerup', fin, { once: true });
    document.addEventListener('pointercancel', fin, { once: true });
  });

  /* ---------- Panel lateral ---------- */
  let alCerrarCajon = null;
  /** El panel ocupa el alto del área de contenido, pegado a su borde derecho. */
  const colocarCajon = () => {
    const marco = document.querySelector('.contenido__pagina') || document.body;
    const r = marco.getBoundingClientRect();
    const barra = marco.querySelector('.barra-superior')?.getBoundingClientRect();   // debajo de la barra de ruta
    cajon.style.top = `${Math.max(0, r.top, barra ? barra.bottom : 0)}px`;
    cajon.style.right = `${Math.max(0, window.innerWidth - r.right)}px`;
    cajon.style.bottom = `${Math.max(0, window.innerHeight - r.bottom)}px`;
  };
  function prepararCajon() {
    const estabaCerrado = cajon.hidden;
    cerrarCajon(true);
    colocarCajon();
    cajon.hidden = false;
    cajon.classList.toggle('es-abriendo', estabaCerrado);
    raiz.classList.add('con-cajon');
  }
  function cerrarCajon(paraReabrir = false) {
    const callback = alCerrarCajon;
    alCerrarCajon = null;
    callback?.();
    if (paraReabrir === true) return;
    cajon.hidden = true;
    cajon.innerHTML = '';
    raiz.classList.remove('con-cajon');
    if (detalleId !== null) { detalleId = null; marcarActiva(); }
    if (vista === 'mes' && !resultados) requestAnimationFrame(recortarMes);
  }
  const marcarActiva = () => cuerpo.querySelectorAll('[data-cita]').forEach((n) => n.classList.toggle('es-activa', Number(n.dataset.cita) === detalleId));

  const MENSAJES_ESTADO = {
    programada: 'La cita volvió a programada.', confirmada: 'Cita confirmada.', atendida: 'Cita marcada como atendida.',
    no_asistio: 'Se registró la inasistencia.', cancelada: 'Cita cancelada. El horario quedó libre.',
  };
  async function cambiarEstado(c, estado, ancla) {
    if (estado === c.estado) return;
    if (estado === 'cancelada') {
      const ok = await confirmar(ancla, { titulo: '¿Cancelar esta cita?', texto: 'Queda registrada en la bitácora y el horario se libera.', boton: 'Cancelar cita', alinear: 'derecha' });
      if (!ok) return;
    }
    try {
      await accion({ accion: 'estado', id: c.id, estado });
      mostrarNotificacion('exito', MENSAJES_ESTADO[estado]);
      await refrescar();
      if (buscarCita(c.id)) abrirDetalle(c.id); else cerrarCajon();
    } catch (e) { mostrarNotificacion('error', e.message); }
  }

  /** Acciones rápidas del pie según el estado actual. */
  function botonesEstado(c) {
    const b = (estado, texto, clase = 'boton--secundario') => `<button type="button" class="boton ${clase}" data-poner-estado="${estado}">${texto}</button>`;
    const cancelar = editable(c) ? '<button type="button" class="boton boton--fantasma cal-cajon__peligro" data-poner-estado="cancelada">Cancelar cita</button>' : '';
    // "No asistió" y "Atendida" solo tienen sentido cuando la hora de la cita ya llegó
    const llego = aFecha(c.inicio) <= new Date();
    const enCurso = llego ? b('no_asistio', 'No asistió', 'boton--fantasma') + b('atendida', 'Marcar atendida') : '';
    const rapidas = {
      programada: llego ? enCurso : b('confirmada', 'Confirmar'),
      confirmada: enCurso,
      atendida: b('confirmada', 'Deshacer atendida', 'boton--fantasma'),
      no_asistio: b('programada', 'Volver a programada'),
      cancelada: b('programada', 'Restaurar cita'),
    }[c.estado] || '';
    return `${cancelar}<span class="cal-cajon__espacio"></span>${rapidas}`;
  }

  function abrirDetalle(id) {
    const c = buscarCita(id);
    if (!c) return;
    prepararCajon();
    detalleId = id;
    marcarActiva();
    const ini = aFecha(c.inicio);
    const fin = aFecha(c.fin);
    const prof = profesional(c.profesional_id);
    const vacio = (t) => `<span class="cal-props__vacio">${t}</span>`;
    cajon.setAttribute('aria-label', `Cita: ${tituloCita(c)}`);
    cajon.innerHTML = `
      <header class="cal-cajon__cabecera">
        <span class="cal-cajon__tipo"><i class="cal-cajon__color cal-color--${colorDe(c.profesional_id)}" aria-hidden="true"></i>Cita</span>
        ${editable(c) ? `<button type="button" class="boton-icono" data-editar title="Editar (E)" aria-label="Editar">${ico('editar')}</button>` : ''}
        <button type="button" class="boton-icono" data-mas-acciones title="Más acciones" aria-label="Más acciones" aria-haspopup="menu">${ico('mas')}</button>
        <button type="button" class="boton-icono" data-cerrar title="Cerrar (Esc)" aria-label="Cerrar">${ico('cerrar')}</button>
      </header>
      <div class="cal-cajon__cuerpo">
        <h2 class="cal-cajon__titulo${c.estado === 'cancelada' ? ' es-cancelada' : ''}">${escaparHtml(tituloCita(c))}</h2>
        <p class="cal-cajon__cuando">${capital(fechaTitulo(ini))}${ini.getFullYear() !== new Date().getFullYear() ? ` de ${ini.getFullYear()}` : ''}<br>
          ${hm(ini)} – ${hm(fin)}<span> · ${textoDuracion(minutosEntre(ini, fin))}</span></p>
        <dl class="cal-props">
          <div><dt>Estado</dt><dd><button type="button" class="cal-control cal-control--estado" data-estado-menu aria-haspopup="menu"><i class="cal-punto cal-punto--${c.estado}" aria-hidden="true"></i><span>${escaparHtml(datos.estados[c.estado])}</span>${ico('flecha_abajo')}</button></dd></div>
          <div><dt>Profesional</dt><dd class="cal-props__persona">${avatarHtml(c.profesional_id, c.profesional, '', c.profesional_foto)}<span>${escaparHtml(c.profesional)}</span>${prof ? `<small>${escaparHtml(prof.rol)}</small>` : ''}</dd></div>
          <div><dt>Paciente</dt><dd>${c.paciente ? `<a href="${BASE}/pacientes/ver.php?id=${c.paciente_id}">${escaparHtml(c.paciente)}</a>${c.folio ? `<small>${escaparHtml(c.folio)}</small>` : ''}` : vacio('Sin paciente')}</dd></div>
          ${c.paciente ? `<div><dt>Motivo</dt><dd>${c.motivo ? escaparHtml(c.motivo) : vacio('Sin motivo')}</dd></div>` : ''}
          <div><dt>Recordatorio</dt><dd>${escaparHtml(datos.recordatorios[c.recordatorio] || 'Sin recordatorio')}${c.avisar_paciente ? '<small>También al paciente</small>' : ''}</dd></div>
          <div><dt>Notas</dt><dd>${c.notas ? `<span class="cal-props__notas">${escaparHtml(c.notas)}</span>` : vacio('Sin notas')}</dd></div>
        </dl>
      </div>
      <footer class="cal-cajon__pie">${botonesEstado(c)}</footer>`;
    cajon.querySelector('[data-cerrar]').addEventListener('click', () => cerrarCajon());
    cajon.querySelector('[data-editar]')?.addEventListener('click', () => abrirFormulario(c));
    cajon.querySelector('[data-estado-menu]').addEventListener('click', (ev) => {
      const boton = ev.currentTarget;
      abrirMenu(boton, Object.entries(datos.estados).map(([e, t]) => ({
        texto: t, marcado: c.estado === e, peligro: e === 'cancelada', accion: () => cambiarEstado(c, e, boton),
      })));
    });
    cajon.querySelectorAll('[data-poner-estado]').forEach((b) => b.addEventListener('click', () => cambiarEstado(c, b.dataset.ponerEstado, b)));
    cajon.querySelector('[data-mas-acciones]').addEventListener('click', (ev) => {
      const boton = ev.currentTarget;
      const seguimiento = sumarDias(ini, 7);
      abrirMenu(boton, [
        ...(editable(c) ? [{ icono: 'editar', texto: 'Editar', detalle: 'E', accion: () => abrirFormulario(c) }] : []),
        { icono: 'agregar', texto: 'Agendar seguimiento', detalle: 'en 1 semana', accion: () => abrirFormulario({
          paciente_id: c.paciente_id, paciente: c.paciente, folio: c.folio, profesional_id: c.profesional_id, motivo: c.motivo,
          duracion: minutosEntre(ini, fin), recordatorio: c.recordatorio, inicio: `${ymd(seguimiento)}T${hm(ini)}`,
        }) },
        { icono: 'calendario', texto: 'Ver en el calendario', accion: () => { limpiarBusqueda(false); irA(ini); if (vista === 'lista') vista = 'semana'; cargar(); } },
        ...(c.paciente_id ? [{ icono: 'documento', texto: 'Abrir expediente', accion: () => { location.href = `${BASE}/pacientes/ver.php?id=${c.paciente_id}`; } }] : []),
        { icono: 'enlace', texto: 'Copiar enlace', accion: () => copiarEnlace(`${location.origin}${BASE}/agenda/?fecha=${c.inicio.slice(0, 10)}&cita=${c.id}`) },
        ...(editable(c) ? ['separador', { icono: 'cerrar', texto: 'Cancelar cita', peligro: true, accion: () => cambiarEstado(c, 'cancelada', boton) }] : []),
      ], { alinear: 'derecha' });
    });
  }

  /* ---------- Formulario (nueva cita o edición) ---------- */
  /** "9", "930", "9:30", "09.30", "9h" → minutos del día; null si no se entiende. */
  const leerHora = (texto) => {
    const m = String(texto).trim().replace(/\s/g, '').match(/^(\d{1,2})(?:[:.h]?(\d{2}))?$/);
    if (!m) return null;
    const h = Number(m[1]);
    const min = Number(m[2] || 0);
    return h > 23 || min > 59 ? null : h * 60 + min;
  };

  function abrirFormulario(c = {}, alCerrar = null) {
    prepararCajon();
    alCerrarCajon = alCerrar;
    detalleId = null;
    marcarActiva();
    const editando = !!c.id;
    const ini = c.inicio ? aFecha(c.inicio) : new Date();
    const f = {
      pacienteId: c.paciente_id || null,
      pacienteNombre: c.paciente || '',
      pacienteFolio: c.folio || '',
      profesionalId: c.profesional_id || (datos.profesionales.some((p) => p.id === datos.yo) ? datos.yo : datos.profesionales[0]?.id),
      dia: inicioDia(ini),
      minutos: minutosDelDia(ini),
      duracion: c.duracion || (c.fin ? minutosEntre(aFecha(c.inicio), aFecha(c.fin)) : 30),
      recordatorio: c.recordatorio ?? (editando ? 0 : 30),
      avisar: !!c.avisar_paciente,
    };
    const DURACIONES = [15, 20, 30, 45, 60, 90, 120];
    cajon.setAttribute('aria-label', editando ? 'Editar cita' : 'Nueva cita');
    cajon.innerHTML = `
      <header class="cal-cajon__cabecera">
        <span class="cal-cajon__tipo">${editando ? 'Editar cita' : 'Nueva cita'}</span>
        <button type="button" class="boton-icono" data-cerrar title="Cerrar (Esc)" aria-label="Cerrar">${ico('cerrar')}</button>
      </header>
      <form class="cal-form" novalidate>
        <div class="cal-cajon__cuerpo">
          <div class="cal-form__paciente">
            <div class="cal-elegido" data-paciente-elegido hidden>
              ${icono('usuario')}
              <span class="cal-elegido__texto"><b data-paciente-nombre></b><small data-paciente-folio></small></span>
              <button type="button" class="boton-icono" data-quitar-paciente title="Quitar paciente" aria-label="Quitar paciente">${ico('cerrar')}</button>
            </div>
            <div class="cal-buscador" data-paciente-buscador>
              ${ico('buscar')}
              <input type="text" data-buscar-paciente placeholder="Buscar paciente por nombre, folio o CURP" autocomplete="off" spellcheck="false"
                     role="combobox" aria-label="Paciente" aria-expanded="false" aria-autocomplete="list" aria-controls="cal-sugerencias">
            </div>
            <ul class="cal-sugerencias" id="cal-sugerencias" data-sugerencias role="listbox" hidden></ul>
          </div>
          <input class="cal-form__motivo" name="motivo" maxlength="200" placeholder="Motivo de la cita" value="${escaparHtml(c.motivo || '')}" autocomplete="off" aria-label="Motivo">

          <div class="cal-form__filas">
            <div class="cal-form__fila">
              <span class="cal-form__etiqueta">${ico('calendario')}Fecha</span>
              <button type="button" class="cal-control" data-elegir-fecha aria-haspopup="dialog"></button>
            </div>
            <div class="cal-form__fila">
              <span class="cal-form__etiqueta">${ico('reloj')}Hora</span>
              <div class="cal-form__horario">
                <div class="cal-hora">
                  <input type="text" class="cal-control cal-hora__entrada" data-hora inputmode="numeric" maxlength="5" autocomplete="off" aria-label="Hora de inicio"
                         role="combobox" aria-expanded="false" aria-controls="cal-horas">
                  <ul class="cal-hora__lista" id="cal-horas" data-horas role="listbox" hidden></ul>
                </div>
                <span class="cal-form__fin" data-fin></span>
              </div>
            </div>
            <div class="cal-form__fila cal-form__fila--arriba">
              <span class="cal-form__etiqueta">Duración</span>
              <div class="cal-chips" data-duraciones role="radiogroup" aria-label="Duración">
                ${DURACIONES.map((d) => `<button type="button" role="radio" data-min="${d}">${textoDuracion(d)}</button>`).join('')}
                <input type="text" class="cal-chips__otra" data-otra-duracion inputmode="numeric" maxlength="3" placeholder="Otra" aria-label="Otra duración en minutos" autocomplete="off"><span class="cal-chips__unidad">min</span>
              </div>
            </div>
            <div class="cal-form__fila">
              <span class="cal-form__etiqueta">${icono('usuario')}Profesional</span>
              <button type="button" class="cal-control" data-elegir-profesional aria-haspopup="menu"></button>
            </div>
            <div class="cal-form__fila cal-form__fila--arriba">
              <span class="cal-form__etiqueta">Recordatorio</span>
              <div class="cal-chips" data-recordatorios role="radiogroup" aria-label="Recordatorio">
                ${Object.entries(datos.recordatorios).map(([m, t]) => `<button type="button" role="radio" data-rec="${m}">${escaparHtml(Number(m) ? t.replace(' antes', '') : 'Ninguno')}</button>`).join('')}
              </div>
            </div>
            <div class="cal-form__fila" data-avisar-fila hidden>
              <span class="cal-form__etiqueta">${icono('correo')}Paciente</span>
              <button type="button" class="cal-interruptor" role="switch" aria-checked="false" data-avisar><i class="interruptor" aria-hidden="true"></i><span>Enviarle el recordatorio por correo</span></button>
            </div>
          </div>
          <label class="cal-form__notas"><span class="cal-form__etiqueta">Notas</span>
            <textarea name="notas" rows="3" maxlength="2000" placeholder="Indicaciones, preparación o datos para recepción">${escaparHtml(c.notas || '')}</textarea>
          </label>
          <p class="cal-form__aviso" data-aviso hidden></p>
        </div>
        <footer class="cal-cajon__pie">
          <span class="cal-cajon__pista"><kbd>Ctrl</kbd><kbd>Enter</kbd> para guardar</span>
          <span class="cal-cajon__espacio"></span>
          <button type="button" class="boton boton--fantasma" data-cerrar>Cancelar</button>
          <button type="submit" class="boton boton--primario">${editando ? 'Guardar cambios' : 'Agendar'}</button>
        </footer>
      </form>`;
    const form = cajon.querySelector('form');
    const $ = (sel) => cajon.querySelector(sel);
    const botonFecha = $('[data-elegir-fecha]');
    const entradaHora = $('[data-hora]');
    const listaHoras = $('[data-horas]');
    const otraDuracion = $('[data-otra-duracion]');
    cajon.querySelectorAll('[data-cerrar]').forEach((b) => b.addEventListener('click', () => cerrarCajon()));

    const inicioElegido = () => { const d = new Date(f.dia); d.setHours(0, f.minutos, 0, 0); return d; };
    /** Repinta los controles con el estado actual del formulario. */
    function pintarControles() {
      const d = f.dia;
      botonFecha.innerHTML = `<span>${capital(DIAS_CORTOS[indiceDia(d)])} ${d.getDate()} ${MESES_CORTOS[d.getMonth()]} ${d.getFullYear()}</span>${etiquetaDia(d) ? `<small>${etiquetaDia(d)}</small>` : ''}`;
      if (document.activeElement !== entradaHora) entradaHora.value = textoMin(f.minutos);
      const fin = f.minutos + f.duracion;
      $('[data-fin]').textContent = `hasta ${textoMin(fin % 1440)}${fin >= 1440 ? ' del día siguiente' : ''}`;
      const enLista = DURACIONES.includes(f.duracion);
      cajon.querySelectorAll('[data-min]').forEach((b) => b.setAttribute('aria-checked', String(Number(b.dataset.min) === f.duracion)));
      if (document.activeElement !== otraDuracion) otraDuracion.value = enLista ? '' : String(f.duracion);
      otraDuracion.classList.toggle('es-activa', !enLista);
      cajon.querySelectorAll('[data-rec]').forEach((b) => b.setAttribute('aria-checked', String(Number(b.dataset.rec) === f.recordatorio)));
      const p = profesional(f.profesionalId);
      $('[data-elegir-profesional]').innerHTML = p
        ? `${avatarHtml(p.id, p.nombre, '', p.foto)}<span>${escaparHtml(p.nombre)}</span><small>${escaparHtml(p.rol)}</small>${ico('flecha_abajo')}`
        : `<span class="cal-props__vacio">Elegir profesional</span>${ico('flecha_abajo')}`;
      $('[data-paciente-elegido]').hidden = !f.pacienteId;
      $('[data-paciente-buscador]').hidden = !!f.pacienteId;
      $('[data-paciente-nombre]').textContent = f.pacienteNombre;
      $('[data-paciente-folio]').textContent = f.pacienteFolio || '';
      $('[data-avisar-fila]').hidden = !f.pacienteId;
      $('[data-avisar]').setAttribute('aria-checked', String(f.avisar));
      revisarAviso();
    }
    /** Aviso de empalme con las citas cargadas (el servidor vuelve a revisarlo al guardar). */
    function revisarAviso() {
      const ini = inicioElegido();
      const fin = new Date(ini.getTime() + f.duracion * 60000);
      const choque = citas.find((o) => o.id !== c.id && o.profesional_id === f.profesionalId && !LIBERAN.includes(o.estado) && aFecha(o.inicio) < fin && aFecha(o.fin) > ini);
      const avisos = [];
      if (choque) avisos.push(`Se empalma con ${escaparHtml(tituloCita(choque))} (${hm(aFecha(choque.inicio))} – ${hm(aFecha(choque.fin))}).`);
      if (!editando && fin < new Date()) avisos.push('La fecha y hora ya pasaron.');
      const aviso = $('[data-aviso]');
      aviso.hidden = !avisos.length;
      aviso.innerHTML = avisos.length ? `${icono('alerta')}<span>${avisos.join(' ')}</span>` : '';
    }

    // Fecha: mini calendario propio en un popover
    let mesSelector = new Date(f.dia.getFullYear(), f.dia.getMonth(), 1);
    const pintarSelectorFecha = (el) => {
      el.innerHTML = `<div class="cal-mini cal-mini--selector">${htmlMiniMes(mesSelector, { seleccion: f.dia })}</div>
        <div class="cal-selector__atajos"><button type="button" data-rapida="0">Hoy</button><button type="button" data-rapida="1">Mañana</button><button type="button" data-rapida="7">En 7 días</button></div>`;
    };
    botonFecha.addEventListener('click', () => {
      const el = abrirPopover(botonFecha, '', { clase: 'cal-popover cal-popover--fecha' });
      pintarSelectorFecha(el);
      colocarPopover(el, botonFecha);
      el.addEventListener('click', (ev) => {
        const moverMes = ev.target.closest('[data-mini-mover]');
        const dia = ev.target.closest('[data-mini-dia]');
        const rapida = ev.target.closest('[data-rapida]');
        if (moverMes) { mesSelector = new Date(mesSelector.getFullYear(), mesSelector.getMonth() + Number(moverMes.dataset.miniMover), 1); pintarSelectorFecha(el); return; }
        if (dia || rapida) {
          f.dia = dia ? new Date(`${dia.dataset.miniDia}T00:00`) : sumarDias(inicioDia(new Date()), Number(rapida.dataset.rapida));
          mesSelector = new Date(f.dia.getFullYear(), f.dia.getMonth(), 1);
          cerrarPopover();
          pintarControles();
          botonFecha.focus();
        }
      });
      el.querySelector('.es-seleccion')?.focus();
    });

    // Hora: campo de texto con lista propia de horarios cada 15 minutos
    const opcionesHora = [];
    for (let m = 0; m < 1440; m += PASO) opcionesHora.push(m);
    listaHoras.innerHTML = opcionesHora.map((m) => `<li role="option" data-hora-opcion="${m}">${textoMin(m)}</li>`).join('');
    let horaResaltada = null;
    const resaltarHora = (m, desplazarLista = true) => {
      horaResaltada = m;
      listaHoras.querySelectorAll('[data-hora-opcion]').forEach((li) => li.setAttribute('aria-selected', String(Number(li.dataset.horaOpcion) === m)));
      const li = listaHoras.querySelector(`[data-hora-opcion="${m}"]`);
      if (li && desplazarLista) listaHoras.scrollTop = li.offsetTop - listaHoras.clientHeight / 2 + li.offsetHeight / 2;
    };
    const abrirHoras = () => {
      listaHoras.hidden = false;
      entradaHora.setAttribute('aria-expanded', 'true');
      resaltarHora(Math.round(f.minutos / PASO) * PASO % 1440);
    };
    const cerrarHoras = () => { listaHoras.hidden = true; entradaHora.setAttribute('aria-expanded', 'false'); };
    const fijarHora = (m) => { f.minutos = m; entradaHora.value = textoMin(m); cerrarHoras(); pintarControles(); };
    entradaHora.addEventListener('focus', () => { entradaHora.select(); abrirHoras(); });
    entradaHora.addEventListener('click', abrirHoras);
    entradaHora.addEventListener('input', () => {
      const m = leerHora(entradaHora.value);
      if (m !== null) resaltarHora(Math.round(m / PASO) * PASO % 1440);
    });
    entradaHora.addEventListener('blur', () => {
      const m = leerHora(entradaHora.value);
      if (m !== null) f.minutos = m;
      entradaHora.value = textoMin(f.minutos);
      cerrarHoras();
      pintarControles();
    });
    entradaHora.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        if (listaHoras.hidden) abrirHoras();
        const base = horaResaltada ?? f.minutos;
        resaltarHora((base + (ev.key === 'ArrowDown' ? PASO : -PASO) + 1440) % 1440);
        entradaHora.value = textoMin(horaResaltada);
      } else if (ev.key === 'Enter') {
        ev.preventDefault();
        const m = leerHora(entradaHora.value);
        fijarHora(m ?? horaResaltada ?? f.minutos);
      } else if (ev.key === 'Escape' && !listaHoras.hidden) {
        ev.preventDefault();
        entradaHora.value = textoMin(f.minutos);
        cerrarHoras();
      }
    });
    listaHoras.addEventListener('pointerdown', (ev) => {
      const li = ev.target.closest('[data-hora-opcion]');
      ev.preventDefault();   // conserva el foco en el campo
      if (li) fijarHora(Number(li.dataset.horaOpcion));
    });

    // Duración y recordatorio
    $('[data-duraciones]').addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-min]');
      if (b) { f.duracion = Number(b.dataset.min); pintarControles(); }
    });
    otraDuracion.addEventListener('input', () => {
      otraDuracion.value = otraDuracion.value.replace(/\D/g, '');
      const m = Number(otraDuracion.value);
      if (m >= 5 && m <= datos.duracionMaxima) { f.duracion = m; pintarControles(); }
    });
    otraDuracion.addEventListener('blur', pintarControles);
    $('[data-recordatorios]').addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-rec]');
      if (b) { f.recordatorio = Number(b.dataset.rec); pintarControles(); }
    });
    $('[data-avisar]').addEventListener('click', () => { f.avisar = !f.avisar; pintarControles(); });

    // Profesional: menú propio
    $('[data-elegir-profesional]').addEventListener('click', (ev) => {
      abrirMenu(ev.currentTarget, datos.profesionales.map((p) => ({
        texto: p.nombre, detalle: p.rol, marcado: p.id === f.profesionalId,
        accion: () => { f.profesionalId = p.id; pintarControles(); },
      })));
    });

    // Paciente: búsqueda con sugerencias navegables con el teclado
    const buscar = $('[data-buscar-paciente]');
    const sugerencias = $('[data-sugerencias]');
    let indiceSugerencia = -1;
    const cerrarSugerencias = () => { sugerencias.hidden = true; buscar.setAttribute('aria-expanded', 'false'); indiceSugerencia = -1; };
    const fijarPaciente = (id, nombre = '', folio = '') => {
      Object.assign(f, { pacienteId: id, pacienteNombre: nombre, pacienteFolio: folio });
      if (!id) f.avisar = false;
      buscar.value = '';
      cerrarSugerencias();
      pintarControles();
      if (id) form.motivo.focus(); else buscar.focus();
    };
    const resaltarSugerencia = (i) => {
      const opciones = [...sugerencias.querySelectorAll('[data-pac]')];
      if (!opciones.length) return;
      indiceSugerencia = (i + opciones.length) % opciones.length;
      opciones.forEach((o, k) => o.setAttribute('aria-selected', String(k === indiceSugerencia)));
      opciones[indiceSugerencia].scrollIntoView({ block: 'nearest' });
    };
    $('[data-quitar-paciente]').addEventListener('click', () => fijarPaciente(null));
    let temporizador = null;
    buscar.addEventListener('input', () => {
      clearTimeout(temporizador);
      temporizador = setTimeout(async () => {
        const texto = buscar.value.trim();
        if (!texto) { cerrarSugerencias(); return; }
        try {
          const r = await api(`agenda/citas.php?pacientes=${encodeURIComponent(texto)}`);
          if (buscar.value.trim() !== texto) return;
          sugerencias.innerHTML = r.pacientes.length
            ? r.pacientes.map((p) => `<li role="option" aria-selected="false" data-pac="${p.id}" data-nombre="${escaparHtml(p.nombre)}" data-folio="${escaparHtml(p.folio || '')}"><span>${escaparHtml(p.nombre)}</span><small>${escaparHtml(p.folio || '')}</small></li>`).join('')
            : '<li class="cal-sugerencias__sin">Ningún paciente coincide.</li>';
          sugerencias.hidden = false;
          buscar.setAttribute('aria-expanded', 'true');
          resaltarSugerencia(0);
        } catch (e) { mostrarNotificacion('error', e.message); }
      }, 200);
    });
    buscar.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowDown') { ev.preventDefault(); resaltarSugerencia(indiceSugerencia + 1); }
      if (ev.key === 'ArrowUp') { ev.preventDefault(); resaltarSugerencia(indiceSugerencia - 1); }
      if (ev.key === 'Enter') {
        ev.preventDefault();
        const o = sugerencias.querySelectorAll('[data-pac]')[indiceSugerencia];
        if (o && !sugerencias.hidden) fijarPaciente(Number(o.dataset.pac), o.dataset.nombre, o.dataset.folio);
      }
      if (ev.key === 'Escape' && !sugerencias.hidden) { ev.preventDefault(); cerrarSugerencias(); }
    });
    buscar.addEventListener('blur', () => setTimeout(cerrarSugerencias, 120));
    sugerencias.addEventListener('pointerdown', (ev) => {
      const o = ev.target.closest('[data-pac]');
      ev.preventDefault();
      if (o) fijarPaciente(Number(o.dataset.pac), o.dataset.nombre, o.dataset.folio);
    });

    // Guardar
    form.motivo.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') ev.preventDefault(); });
    form.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); form.requestSubmit(); }
    });
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const boton = form.querySelector('[type="submit"]');
      if (boton.disabled) return;
      const m = leerHora(entradaHora.value);
      if (m !== null) f.minutos = m;
      const inicio = inicioElegido();
      boton.disabled = true;
      try {
        const r = await accion({
          accion: editando ? 'actualizar' : 'crear', id: c.id, paciente_id: f.pacienteId, profesional_id: f.profesionalId,
          inicio: `${ymd(inicio)}T${hm(inicio)}`, duracion: f.duracion, motivo: form.motivo.value, notas: form.notas.value,
          recordatorio: f.recordatorio, avisar_paciente: f.avisar,
        });
        alCerrarCajon = null;
        if (!resultados) irA(aFecha(r.cita.inicio));
        await refrescar();
        abrirDetalle(r.cita.id);
        mostrarNotificacion('exito', editando ? 'Cita actualizada.' : 'Cita agendada.');
        if (typeof Arbol !== 'undefined') Arbol.recargar?.();
      } catch (e) {
        mostrarNotificacion('error', e.message);
        boton.disabled = false;
      }
    });

    pintarControles();
    (f.pacienteId ? form.motivo : buscar).focus({ preventScroll: true });
  }

  /* ---------- Ventana ---------- */
  let temporizadorVentana = null;
  window.addEventListener('resize', () => {
    clearTimeout(temporizadorVentana);
    temporizadorVentana = setTimeout(() => {
      if (!cajon.hidden) colocarCajon();
      if (vista === 'mes' && !resultados) recortarMes();
    }, 120);
  });
  // Al volver a la pestaña, la agenda se pone al día (otras personas pudieron agendar)
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && !arrastrando && !cajon.querySelector('form')) refrescar();
  });

  /* ---------- Inicio ---------- */
  (async () => {
    pintarProfesionales();
    await cargar();
    if (datos.cita) {
      if (buscarCita(datos.cita)) abrirDetalle(datos.cita);
    }
    if (datos.nueva) {
      let paciente = {};
      if (datos.paciente) {
        try {
          const r = await api(`agenda/citas.php?paciente_id=${datos.paciente}`);
          if (r.paciente) paciente = { paciente_id: r.paciente.id, paciente: r.paciente.nombre, folio: r.paciente.folio };
        } catch (e) { /* sin paciente */ }
      }
      nuevaCita(paciente);
    }
  })();
})();
