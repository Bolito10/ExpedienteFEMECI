/* ==========================================================================
   Visor de imágenes médicas (pacientes/visor.php), sin librerías externas.
   - Lector DICOM: VR explícito e implícito, little y big endian; píxeles sin compresión de 8 y 16 bits
     (con signo o sin él), MONOCHROME1/2 y RGB, multicuadro; JPEG base (1.2.840.10008.1.2.4.50/51) lo
     descomprime el navegador. Otras compresiones se avisan con claridad.
   - Herramientas: ventana/nivel (arrastrar o botón derecho), mover (rueda presionada), zoom (Ctrl + rueda),
     medir en mm (PixelSpacing), invertir, rotar, ventanas predefinidas de TC, cortes con rueda/flechas/deslizador.
   ========================================================================== */
(() => {
  const raiz = document.querySelector('[data-visor]');
  const fuente = document.getElementById('visor-datos');
  if (!raiz || !fuente) return;
  const DATOS = JSON.parse(fuente.textContent);
  const $ = (s) => raiz.querySelector(s);
  const lienzo = $('[data-visor-lienzo]');
  const canvas = $('[data-visor-canvas]');
  const ctx = canvas.getContext('2d');
  const aviso = $('[data-visor-aviso]');
  const deslizador = $('[data-visor-deslizador]');
  const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------------------------------------------------------------- Lector DICOM */
  const VR_LARGOS = new Set(['OB', 'OW', 'OF', 'OD', 'OL', 'SQ', 'UT', 'UN', 'UC', 'UR', 'OV']);
  const TEXTO = (dv, ini, largo) => { let s = ''; for (let i = 0; i < largo; i++) { const c = dv.getUint8(ini + i); if (c) s += String.fromCharCode(c); } return s.trim(); };
  function leerDicom(buffer) {
    const dv = new DataView(buffer);
    if (buffer.byteLength < 132 || TEXTO(dv, 128, 4) !== 'DICM') throw new Error('El archivo no es DICOM.');
    const t = {};
    let pos = 132, explicito = true, little = true, sintaxis = '';
    while (pos + 8 <= buffer.byteLength) {
      const enMeta = dv.getUint16(pos, true) === 0x0002;
      if (!enMeta && sintaxis !== null) {
        explicito = sintaxis !== '1.2.840.10008.1.2';
        little = sintaxis !== '1.2.840.10008.1.2.2';
        t.sintaxis = sintaxis; sintaxis = null;
      }
      const le = enMeta ? true : little;
      const grupo = dv.getUint16(pos, le), elem = dv.getUint16(pos + 2, le);
      const tag = ((grupo << 16) | elem) >>> 0;
      let vr = '', largo;
      if (enMeta || explicito) {
        vr = TEXTO(dv, pos + 4, 2);
        if (VR_LARGOS.has(vr)) { largo = dv.getUint32(pos + 8, le); pos += 12; } else { largo = dv.getUint16(pos + 6, le); pos += 8; }
      } else { largo = dv.getUint32(pos + 4, le); pos += 8; }
      if (tag === 0x7FE00010) {
        if (largo === 0xFFFFFFFF) {   // encapsulado (comprimido): fragmentos después de la tabla de desplazamientos
          const fragmentos = [];
          let p = pos;
          while (p + 8 <= buffer.byteLength) {
            const g = dv.getUint16(p, true), e = dv.getUint16(p + 2, true), l = dv.getUint32(p + 4, true);
            p += 8;
            if (g === 0xFFFE && e === 0xE0DD) break;
            if (g === 0xFFFE && e === 0xE000) { fragmentos.push(new Uint8Array(buffer, p, l)); p += l; } else break;
          }
          t.fragmentos = fragmentos.slice(1);   // el primero es la tabla de desplazamientos
        } else {
          t.pixeles = { ini: pos, largo };
        }
        t.little = little;
        break;
      }
      if (largo === 0xFFFFFFFF) {   // secuencia de largo indefinido: se salta hasta su delimitador
        let p = pos, nivel = 1;
        while (p + 8 <= buffer.byteLength && nivel > 0) {
          const g = dv.getUint16(p, le), e = dv.getUint16(p + 2, le);
          if (g === 0xFFFE && e === 0xE0DD) { nivel--; p += 8; } else if (g === 0xFFFE) { const l = dv.getUint32(p + 4, le); p += 8; if (l !== 0xFFFFFFFF) p += l; } else p += 2;
        }
        pos = p;
        continue;
      }
      if (pos + largo > buffer.byteLength) break;
      const leer = {
        0x00020010: () => { sintaxis = TEXTO(dv, pos, largo).replace(/\0/g, ''); },
        0x00280010: () => { t.filas = dv.getUint16(pos, le); }, 0x00280011: () => { t.columnas = dv.getUint16(pos, le); },
        0x00280100: () => { t.bits = dv.getUint16(pos, le); }, 0x00280103: () => { t.signo = dv.getUint16(pos, le); },
        0x00280002: () => { t.muestras = dv.getUint16(pos, le); }, 0x00280006: () => { t.planar = dv.getUint16(pos, le); },
        0x00280004: () => { t.fotometria = TEXTO(dv, pos, largo); }, 0x00280008: () => { t.cuadros = parseInt(TEXTO(dv, pos, largo), 10) || 1; },
        0x00281052: () => { t.intercepto = parseFloat(TEXTO(dv, pos, largo)) || 0; }, 0x00281053: () => { t.pendiente = parseFloat(TEXTO(dv, pos, largo)) || 1; },
        0x00281050: () => { t.centro = parseFloat(TEXTO(dv, pos, largo).split('\\')[0]); }, 0x00281051: () => { t.ancho = parseFloat(TEXTO(dv, pos, largo).split('\\')[0]); },
        0x00280030: () => { t.espaciado = TEXTO(dv, pos, largo).split('\\').map(Number); },
        0x00100010: () => { t.paciente = TEXTO(dv, pos, largo).replace(/\^/g, ' '); }, 0x00080060: () => { t.modalidad = TEXTO(dv, pos, largo); },
        0x00080020: () => { t.fecha = TEXTO(dv, pos, largo); }, 0x0008103E: () => { t.serie = TEXTO(dv, pos, largo); },
        0x00081030: () => { t.estudio = TEXTO(dv, pos, largo); }, 0x00180050: () => { t.grosor = parseFloat(TEXTO(dv, pos, largo)); },
        0x00201041: () => { t.posicion = parseFloat(TEXTO(dv, pos, largo)); }, 0x00200013: () => { t.instancia = parseInt(TEXTO(dv, pos, largo), 10); },
        0x00080080: () => { t.institucion = TEXTO(dv, pos, largo); },
      }[tag];
      if (leer) leer();
      pos += largo;
    }
    t.muestras ||= 1; t.bits ||= 16; t.pendiente ??= 1; t.intercepto ??= 0; t.cuadros ||= 1;
    return t;
  }

  /** Valores de cada píxel (ya con pendiente e intercepto) de un cuadro monocromo, y su mínimo y máximo. */
  function valoresMonocromo(buffer, t, cuadro) {
    const n = t.filas * t.columnas;
    const bytes = t.bits / 8;
    const ini = t.pixeles.ini + cuadro * n * bytes;
    const dv = new DataView(buffer);
    const v = new Float32Array(n);
    let min = Infinity, max = -Infinity;
    for (let i = 0; i < n; i++) {
      let x;
      if (t.bits === 8) x = t.signo ? dv.getInt8(ini + i) : dv.getUint8(ini + i);
      else x = t.signo ? dv.getInt16(ini + i * 2, t.little) : dv.getUint16(ini + i * 2, t.little);
      x = x * t.pendiente + t.intercepto;
      v[i] = x;
      if (x < min) min = x;
      if (x > max) max = x;
    }
    return { v, min, max };
  }

  /* ---------------------------------------------------------------- Estado del visor */
  const series = DATOS.estudio.series;
  const cache = new Map();   // id → Promise<{tipo, ...}>
  const estado = { serie: 0, corte: 0, herramienta: 'ventana', zoom: 1, x: 0, y: 0, rot: 0, invertir: false, ww: null, wc: null, preajuste: null, medida: null };
  let actual = null;   // imagen dibujada: {tipo:'mono'|'rgb'|'img', ...}

  const PREAJUSTES = { pulmon: ['Pulmón', 1500, -600], mediastino: ['Mediastino', 350, 50], hueso: ['Hueso', 2000, 300], cerebro: ['Cerebro', 80, 40], abdomen: ['Abdomen', 400, 40], higado: ['Hígado', 150, 30] };

  function cargar(imagen) {
    if (cache.has(imagen.id)) return cache.get(imagen.id);
    const p = (async () => {
      if (imagen.formato === 'pdf') return { tipo: 'pdf', url: DATOS.base + imagen.id };
      if (imagen.formato === 'imagen') {
        const img = new Image();
        img.src = DATOS.base + imagen.id;
        await img.decode();
        return { tipo: 'img', img, ancho: img.naturalWidth, alto: img.naturalHeight };
      }
      const r = await fetch(DATOS.base + imagen.id, { credentials: 'same-origin' });
      if (!r.ok) throw new Error('No se pudo leer la imagen.');
      const buffer = await r.arrayBuffer();
      const t = leerDicom(buffer);
      if (t.fragmentos) {
        if (!/^1\.2\.840\.10008\.1\.2\.4\.5[01]$/.test(t.sintaxis)) throw new Error(`Este estudio viene comprimido (${t.sintaxis}). Exporta el DICOM sin compresión desde el equipo de imagen para verlo aquí.`);
        const img = new Image();
        img.src = URL.createObjectURL(new Blob(t.fragmentos, { type: 'image/jpeg' }));
        await img.decode();
        return { tipo: 'img', img, ancho: img.naturalWidth, alto: img.naturalHeight, t };
      }
      if (!t.pixeles || !t.filas || !t.columnas) throw new Error('El archivo DICOM no trae imagen.');
      if (t.muestras === 3) return { tipo: 'rgb', buffer, t, ancho: t.columnas, alto: t.filas };
      const { v, min, max } = valoresMonocromo(buffer, t, 0);
      return { tipo: 'mono', v, min, max, t, ancho: t.columnas, alto: t.filas };
    })();
    cache.set(imagen.id, p);
    p.catch(() => cache.delete(imagen.id));
    return p;
  }

  /* ---------------------------------------------------------------- Dibujo */
  const offscreen = document.createElement('canvas');
  const octx = offscreen.getContext('2d');
  function pintarOffscreen() {
    if (!actual || actual.tipo === 'pdf') return;
    offscreen.width = actual.ancho; offscreen.height = actual.alto;
    if (actual.tipo === 'img') {
      octx.filter = estado.invertir ? 'invert(1)' : 'none';
      octx.drawImage(actual.img, 0, 0);
      octx.filter = 'none';
      return;
    }
    const datos = octx.createImageData(actual.ancho, actual.alto);
    const d = datos.data;
    if (actual.tipo === 'rgb') {
      const u = new Uint8Array(actual.buffer, actual.t.pixeles.ini, actual.ancho * actual.alto * 3);
      const n = actual.ancho * actual.alto;
      for (let i = 0; i < n; i++) {
        const [r, g, b] = actual.t.planar ? [u[i], u[i + n], u[i + 2 * n]] : [u[i * 3], u[i * 3 + 1], u[i * 3 + 2]];
        d[i * 4] = estado.invertir ? 255 - r : r; d[i * 4 + 1] = estado.invertir ? 255 - g : g; d[i * 4 + 2] = estado.invertir ? 255 - b : b; d[i * 4 + 3] = 255;
      }
    } else {
      const ww = Math.max(1, estado.ww), wc = estado.wc;
      const bajo = wc - ww / 2;
      const invertir = estado.invertir !== (actual.t.fotometria === 'MONOCHROME1');
      const v = actual.v;
      for (let i = 0; i < v.length; i++) {
        let g = ((v[i] - bajo) / ww) * 255;
        g = g < 0 ? 0 : g > 255 ? 255 : g;
        if (invertir) g = 255 - g;
        d[i * 4] = d[i * 4 + 1] = d[i * 4 + 2] = g; d[i * 4 + 3] = 255;
      }
    }
    octx.putImageData(datos, 0, 0);
  }

  function dibujar() {
    const r = lienzo.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    canvas.width = Math.round(r.width * dpr); canvas.height = Math.round(r.height * dpr);
    canvas.style.width = r.width + 'px'; canvas.style.height = r.height + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.fillStyle = '#000'; ctx.fillRect(0, 0, r.width, r.height);
    if (!actual || actual.tipo === 'pdf') return;
    const girado = estado.rot % 180 !== 0;
    const ajuste = Math.min(r.width / (girado ? actual.alto : actual.ancho), r.height / (girado ? actual.ancho : actual.alto));
    const escala = ajuste * estado.zoom;
    ctx.save();
    ctx.translate(r.width / 2 + estado.x, r.height / 2 + estado.y);
    ctx.rotate((estado.rot * Math.PI) / 180);
    ctx.scale(escala, escala);
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(offscreen, -actual.ancho / 2, -actual.alto / 2);
    ctx.restore();
    if (estado.medida) {
      const { a, b } = estado.medida;
      const pa = aPantalla(a), pb = aPantalla(b);
      ctx.strokeStyle = '#2383e2'; ctx.lineWidth = 1.5; ctx.fillStyle = '#2383e2';
      ctx.beginPath(); ctx.moveTo(pa.x, pa.y); ctx.lineTo(pb.x, pb.y); ctx.stroke();
      [pa, pb].forEach((p) => { ctx.beginPath(); ctx.arc(p.x, p.y, 3, 0, Math.PI * 2); ctx.fill(); });
      const [sy, sx] = actual.t?.espaciado?.length === 2 ? actual.t.espaciado : [null, null];
      const dx = b.x - a.x, dy = b.y - a.y;
      const texto = sx ? `${Math.hypot(dx * sx, dy * sy).toFixed(1)} mm` : `${Math.hypot(dx, dy).toFixed(0)} px`;
      ctx.font = '600 13px system-ui, sans-serif';
      const ancho = ctx.measureText(texto).width + 12;
      ctx.fillStyle = 'rgba(15,15,15,.85)'; ctx.fillRect(pb.x + 8, pb.y - 10, ancho, 22);
      ctx.fillStyle = '#fff'; ctx.fillText(texto, pb.x + 14, pb.y + 5);
    }
  }
  // Conversión entre coordenadas de la imagen y de la pantalla (considera zoom, giro y desplazamiento)
  function escalaActual() {
    const r = lienzo.getBoundingClientRect();
    const girado = estado.rot % 180 !== 0;
    return { r, s: Math.min(r.width / (girado ? actual.alto : actual.ancho), r.height / (girado ? actual.ancho : actual.alto)) * estado.zoom };
  }
  function aImagen(px, py) {
    const { r, s } = escalaActual();
    const ang = (-estado.rot * Math.PI) / 180;
    const x = (px - r.width / 2 - estado.x) / s, y = (py - r.height / 2 - estado.y) / s;
    return { x: x * Math.cos(ang) - y * Math.sin(ang) + actual.ancho / 2, y: x * Math.sin(ang) + y * Math.cos(ang) + actual.alto / 2 };
  }
  function aPantalla(p) {
    const { r, s } = escalaActual();
    const ang = (estado.rot * Math.PI) / 180;
    const x = p.x - actual.ancho / 2, y = p.y - actual.alto / 2;
    return { x: (x * Math.cos(ang) - y * Math.sin(ang)) * s + r.width / 2 + estado.x, y: (x * Math.sin(ang) + y * Math.cos(ang)) * s + r.height / 2 + estado.y };
  }

  function textos() {
    const serie = series[estado.serie];
    const t = actual?.t || {};
    const p = DATOS.paciente;
    $('[data-visor-info-izq]').innerHTML = `${esc(p.nombre)}<br>${esc(p.folio)}${p.edad !== null ? ` · ${p.edad} años` : ''}${p.sexo ? ` · ${esc(p.sexo)}` : ''}`;
    $('[data-visor-info-der]').innerHTML = `${esc(DATOS.modalidad)}${t.institucion ? `<br>${esc(t.institucion)}` : ''}<br>${esc(serie.descripcion || t.serie || '')}`;
    $('[data-visor-corte]').textContent = `Imagen ${estado.corte + 1} de ${serie.imagenes.length}${t.grosor ? ` · grosor ${t.grosor} mm` : ''}${Number.isFinite(t.posicion) ? ` · posición ${t.posicion.toFixed(1)} mm` : ''}`;
    $('[data-visor-ventana]').textContent = actual?.tipo === 'mono' ? `V ${Math.round(estado.ww)} · N ${Math.round(estado.wc)} · ${Math.round(estado.zoom * 100)}%` : `${Math.round(estado.zoom * 100)}%`;
  }

  async function mostrar(serieIndice, corte, reiniciarVentana = false) {
    const serie = series[serieIndice];
    corte = Math.max(0, Math.min(serie.imagenes.length - 1, corte));
    estado.serie = serieIndice; estado.corte = corte;
    deslizador.max = serie.imagenes.length; deslizador.value = corte + 1;
    deslizador.style.setProperty('--progreso', serie.imagenes.length > 1 ? `${(corte / (serie.imagenes.length - 1)) * 100}%` : '0%');
    deslizador.hidden = serie.imagenes.length < 2;
    const imagen = serie.imagenes[corte];
    aviso.hidden = true;
    try {
      const dato = await cargar(imagen);
      if (estado.serie !== serieIndice || estado.corte !== corte) return;   // el usuario ya se movió
      if (dato.tipo === 'pdf') {
        actual = dato;
        aviso.hidden = false;
        aviso.innerHTML = `Este archivo es un reporte en PDF. <a href="${esc(dato.url)}" target="_blank" rel="noopener">Abrir el reporte</a>`;
        dibujar(); textos();
        return;
      }
      actual = dato;
      if (actual.tipo === 'mono' && (reiniciarVentana || estado.ww === null)) {
        const t = actual.t;
        estado.ww = estado.preajuste ? PREAJUSTES[estado.preajuste][1] : (t.ancho || actual.max - actual.min || 1);
        estado.wc = estado.preajuste ? PREAJUSTES[estado.preajuste][2] : (Number.isFinite(t.centro) ? t.centro : (actual.max + actual.min) / 2);
      }
      pintarOffscreen(); dibujar(); textos();
      // Precarga los cortes vecinos para que la rueda se sienta fluida
      [1, 2, -1, 3].forEach((d) => { const v = serie.imagenes[corte + d]; if (v) cargar(v).catch(() => {}); });
    } catch (e) {
      actual = null; dibujar();
      aviso.hidden = false; aviso.textContent = e.message;
    }
    raiz.querySelectorAll('[data-visor-serie]').forEach((b) => b.classList.toggle('activa', Number(b.dataset.visorSerie) === serieIndice));
  }

  /* ---------------------------------------------------------------- Series (miniaturas) */
  const listaSeries = $('[data-visor-series]');
  listaSeries.innerHTML = series.map((s, i) => `
    <button type="button" class="visor__serie" data-visor-serie="${i}">
      <canvas class="visor__miniatura" width="96" height="96"></canvas>
      <span><strong>${esc(s.descripcion || (s.imagenes[0].formato === 'dicom' ? `Serie ${i + 1}` : s.imagenes[0].nombre))}</strong>
      <small>${s.imagenes.length} ${s.imagenes.length === 1 ? 'imagen' : 'imágenes'}</small></span>
    </button>`).join('');
  listaSeries.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-visor-serie]');
    if (b) { estado.medida = null; mostrar(Number(b.dataset.visorSerie), 0, true); }
  });
  // Miniaturas: la imagen del centro de cada serie, dibujada con su propia ventana
  series.forEach(async (s, i) => {
    const mini = listaSeries.querySelectorAll('.visor__miniatura')[i];
    try {
      const d = await cargar(s.imagenes[Math.floor(s.imagenes.length / 2)]);
      const c = mini.getContext('2d');
      c.fillStyle = '#000'; c.fillRect(0, 0, 96, 96);
      if (d.tipo === 'pdf') { c.fillStyle = '#9b9b9b'; c.font = '600 14px system-ui'; c.fillText('PDF', 34, 54); return; }
      const tmp = document.createElement('canvas');
      tmp.width = d.ancho; tmp.height = d.alto;
      const tc = tmp.getContext('2d');
      if (d.tipo === 'img') tc.drawImage(d.img, 0, 0);
      else if (d.tipo === 'mono') {
        const img = tc.createImageData(d.ancho, d.alto);
        const ww = d.t.ancho || d.max - d.min || 1, wc = Number.isFinite(d.t.centro) ? d.t.centro : (d.max + d.min) / 2, bajo = wc - ww / 2;
        const inv = d.t.fotometria === 'MONOCHROME1';
        for (let k = 0; k < d.v.length; k++) { let g = ((d.v[k] - bajo) / ww) * 255; g = g < 0 ? 0 : g > 255 ? 255 : g; if (inv) g = 255 - g; img.data[k * 4] = img.data[k * 4 + 1] = img.data[k * 4 + 2] = g; img.data[k * 4 + 3] = 255; }
        tc.putImageData(img, 0, 0);
      }
      const s2 = Math.min(96 / d.ancho, 96 / d.alto);
      c.drawImage(tmp, (96 - d.ancho * s2) / 2, (96 - d.alto * s2) / 2, d.ancho * s2, d.alto * s2);
    } catch (e) { /* sin miniatura */ }
  });

  /* ---------------------------------------------------------------- Interacción */
  raiz.querySelectorAll('[data-herramienta]').forEach((b) => b.addEventListener('click', () => {
    estado.herramienta = b.dataset.herramienta;
    raiz.querySelectorAll('[data-herramienta]').forEach((x) => x.classList.toggle('activa', x === b));
    lienzo.dataset.herramienta = estado.herramienta;
  }));
  lienzo.dataset.herramienta = 'ventana';

  let arrastre = null;
  lienzo.addEventListener('contextmenu', (ev) => ev.preventDefault());
  lienzo.addEventListener('pointerdown', (ev) => {
    if (!actual || actual.tipo === 'pdf') return;
    lienzo.focus();
    lienzo.setPointerCapture(ev.pointerId);
    const modo = ev.button === 2 ? 'ventana' : ev.button === 1 ? 'mover' : estado.herramienta;
    const r = lienzo.getBoundingClientRect();
    arrastre = { modo, x: ev.clientX, y: ev.clientY, ww: estado.ww, wc: estado.wc, zoom: estado.zoom, px: estado.x, py: estado.y };
    if (modo === 'medir') {
      const p = aImagen(ev.clientX - r.left, ev.clientY - r.top);
      estado.medida = { a: p, b: p };
    }
    ev.preventDefault();
  });
  lienzo.addEventListener('pointermove', (ev) => {
    if (!arrastre) return;
    const dx = ev.clientX - arrastre.x, dy = ev.clientY - arrastre.y;
    if (arrastre.modo === 'ventana' && actual.tipo === 'mono') {
      const rango = Math.max(1, actual.max - actual.min);
      estado.ww = Math.max(1, arrastre.ww + dx * rango / 400);
      estado.wc = arrastre.wc + dy * rango / 400;
      estado.preajuste = null; $('[data-visor-preajuste-texto]').textContent = 'Personalizada';
      pintarOffscreen();
    } else if (arrastre.modo === 'mover') { estado.x = arrastre.px + dx; estado.y = arrastre.py + dy; }
    else if (arrastre.modo === 'zoom') { estado.zoom = Math.max(0.2, Math.min(12, arrastre.zoom * Math.exp(-dy / 200))); }
    else if (arrastre.modo === 'medir') {
      const r = lienzo.getBoundingClientRect();
      estado.medida.b = aImagen(ev.clientX - r.left, ev.clientY - r.top);
    }
    dibujar(); textos();
  });
  lienzo.addEventListener('pointerup', () => { arrastre = null; });
  lienzo.addEventListener('wheel', (ev) => {
    ev.preventDefault();
    if (ev.ctrlKey) { estado.zoom = Math.max(0.2, Math.min(12, estado.zoom * Math.exp(-ev.deltaY / 400))); dibujar(); textos(); return; }
    estado.medida = null;
    mostrar(estado.serie, estado.corte + (ev.deltaY > 0 ? 1 : -1));
  }, { passive: false });
  deslizador.addEventListener('input', () => { estado.medida = null; mostrar(estado.serie, Number(deslizador.value) - 1); });

  const restablecer = () => {
    Object.assign(estado, { zoom: 1, x: 0, y: 0, rot: 0, invertir: false, medida: null, preajuste: null });
    $('[data-visor-preajuste-texto]').textContent = 'Predeterminada';
    mostrar(estado.serie, estado.corte, true);
  };
  const acciones = {
    invertir: () => { estado.invertir = !estado.invertir; pintarOffscreen(); dibujar(); },
    rotar: () => { estado.rot = (estado.rot + 90) % 360; estado.medida = null; dibujar(); },
    restablecer,
    pantalla: () => (document.fullscreenElement ? document.exitFullscreen() : raiz.requestFullscreen?.()),
  };
  raiz.querySelectorAll('[data-visor-accion]').forEach((b) => b.addEventListener('click', () => acciones[b.dataset.visorAccion]()));
  $('[data-visor-preajuste]').addEventListener('click', (ev) => {
    const fijar = (clave) => {
      estado.preajuste = clave;
      $('[data-visor-preajuste-texto]').textContent = clave ? PREAJUSTES[clave][0] : 'Predeterminada';
      mostrar(estado.serie, estado.corte, true);
    };
    // Sin el menú de la app (portal del paciente): cada clic pasa a la siguiente ventana
    if (typeof abrirMenu !== 'function') {
      const claves = [null, ...Object.keys(PREAJUSTES)];
      fijar(claves[(claves.indexOf(estado.preajuste) + 1) % claves.length]);
      return;
    }
    abrirMenu(ev.currentTarget, [
      { titulo: 'Ventana (tomografía)' },
      { icono: 'ventana', texto: 'Predeterminada', detalle: 'La del estudio', accion: () => fijar(null) },
      ...Object.entries(PREAJUSTES).map(([k, [texto, ww, wc]]) => ({ icono: 'ventana', texto, detalle: `V ${ww} · N ${wc}`, accion: () => fijar(k) })),
    ]);
  });
  lienzo.addEventListener('keydown', (ev) => {
    const teclas = { ArrowDown: 1, ArrowRight: 1, PageDown: 10, ArrowUp: -1, ArrowLeft: -1, PageUp: -10 };
    if (teclas[ev.key]) { ev.preventDefault(); estado.medida = null; mostrar(estado.serie, estado.corte + teclas[ev.key]); }
    else if (ev.key === 'Home') mostrar(estado.serie, 0);
    else if (ev.key === 'End') mostrar(estado.serie, series[estado.serie].imagenes.length - 1);
    else if (ev.key.toLowerCase() === 'r') restablecer();
    else if (ev.key.toLowerCase() === 'i') acciones.invertir();
    else if (ev.key.toLowerCase() === 'f') acciones.pantalla();
  });
  new ResizeObserver(() => dibujar()).observe(lienzo);
  mostrar(0, 0, true);
  lienzo.focus();
})();
