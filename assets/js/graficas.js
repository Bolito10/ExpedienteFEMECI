/* ==========================================================================
   Expedientes Clínicos — gráficas del perfil del paciente (SVG, sin librerías)
   Barras (agrupadas o apiladas, verticales u horizontales) y línea / área,
   con cuadrícula, valores en monoespaciada, resaltado por categoría, globo
   con los valores y leyenda que oculta o muestra cada serie.
   Los datos vienen del servidor (#graficas-datos); nunca hay datos de ejemplo.
   ========================================================================== */

const Graficas = (() => {
  const NS = 'http://www.w3.org/2000/svg';
  const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const unidad = (n, [uno, varios]) => `${n} ${n === 1 ? uno : varios}`;

  /** Escala con divisiones "redondas" (1, 2, 5 × 10ⁿ); los conteos siempre son enteros. */
  function escala(maximo, divisiones = 4) {
    const max = Math.max(1, maximo);
    const bruto = max / divisiones;
    const potencia = 10 ** Math.floor(Math.log10(bruto));
    const paso = Math.max(1, [1, 2, 5, 10].map((m) => m * potencia).find((p) => p >= bruto));
    const tope = Math.ceil(max / paso) * paso;
    const marcas = [];
    for (let v = 0; v <= tope; v += paso) marcas.push(v);
    return { tope, marcas };
  }

  const el = (nombre, atributos = {}, padre = null) => {
    const nodo = document.createElementNS(NS, nombre);
    Object.entries(atributos).forEach(([k, v]) => nodo.setAttribute(k, v));
    if (padre) padre.appendChild(nodo);
    return nodo;
  };

  /** Rectángulo con las esquinas del extremo redondeadas (arriba o a la derecha). */
  function barra(x, y, ancho, alto, radio, horizontal) {
    const r = Math.max(0, Math.min(radio, (horizontal ? alto : ancho) / 2, horizontal ? ancho : alto));
    if (horizontal) {
      return `M${x},${y}H${x + ancho - r}Q${x + ancho},${y} ${x + ancho},${y + r}V${y + alto - r}Q${x + ancho},${y + alto} ${x + ancho - r},${y + alto}H${x}Z`;
    }
    return `M${x},${y + alto}V${y + r}Q${x},${y} ${x + r},${y}H${x + ancho - r}Q${x + ancho},${y} ${x + ancho},${y + r}V${y + alto}Z`;
  }

  /** Curva suave que no se pasa de los puntos (monótona, Fritsch–Carlson). */
  function curva(puntos) {
    if (puntos.length < 2) return puntos.length ? `M${puntos[0][0]},${puntos[0][1]}` : '';
    const n = puntos.length;
    const dx = [], dy = [], m = [];
    for (let i = 0; i < n - 1; i++) { dx[i] = puntos[i + 1][0] - puntos[i][0]; dy[i] = puntos[i + 1][1] - puntos[i][1]; m[i] = dy[i] / dx[i]; }
    const t = [m[0]];
    for (let i = 1; i < n - 1; i++) t[i] = m[i - 1] * m[i] <= 0 ? 0 : (m[i - 1] + m[i]) / 2;
    t[n - 1] = m[n - 2];
    for (let i = 0; i < n - 1; i++) {
      if (m[i] === 0) { t[i] = 0; t[i + 1] = 0; continue; }
      const a = t[i] / m[i], b = t[i + 1] / m[i], s = a * a + b * b;
      if (s > 9) { const k = 3 / Math.sqrt(s); t[i] = k * a * m[i]; t[i + 1] = k * b * m[i]; }
    }
    let d = `M${puntos[0][0]},${puntos[0][1]}`;
    for (let i = 0; i < n - 1; i++) {
      const h = dx[i] / 3;
      d += `C${puntos[i][0] + h},${puntos[i][1] + t[i] * h} ${puntos[i + 1][0] - h},${puntos[i + 1][1] - t[i + 1] * h} ${puntos[i + 1][0]},${puntos[i + 1][1]}`;
    }
    return d;
  }

  /* ---------- Base común: lienzo, globo, leyenda y redibujo al cambiar el ancho ---------- */
  class Grafica {
    constructor(seccion, datos) {
      this.seccion = seccion;
      this.datos = datos;
      this.lienzo = seccion.querySelector('[data-grafica-lienzo]');
      this.leyenda = seccion.querySelector('[data-grafica-leyenda]');
      this.ocultas = new Set();
      this.animar = true;
      this.globo = document.createElement('div');
      this.globo.className = 'grafica__globo';
      this.globo.hidden = true;
      this.lienzo.appendChild(this.globo);
      this.pintarLeyenda();
      // Se dibuja de inmediato y otra vez cuando cambia el ancho (panel lateral, ventana)
      let ancho = 0;
      queueMicrotask(() => { ancho = this.lienzo.clientWidth; if (ancho) this.dibujar(); });
      new ResizeObserver(([e]) => {
        const nuevo = Math.round(e.contentRect.width);
        if (nuevo && nuevo !== ancho) { ancho = nuevo; this.dibujar(); }
      }).observe(this.lienzo);
      this.lienzo.addEventListener('mouseleave', () => this.soltar());
    }

    series() { return this.datos.series.filter((s) => !this.ocultas.has(s.id)); }

    pintarLeyenda() {
      this.leyenda.innerHTML = this.datos.series.map((s) => `
        <li><button type="button" aria-pressed="true" data-serie="${esc(s.id)}"><span class="grafica__punto" style="background:${s.color}"></span>${esc(s.nombre)}</button></li>`).join('');
      this.leyenda.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-serie]');
        if (!b) return;
        const id = b.dataset.serie;
        if (!this.ocultas.has(id) && this.ocultas.size === this.datos.series.length - 1) return; // siempre queda una visible
        if (this.ocultas.has(id)) this.ocultas.delete(id); else this.ocultas.add(id);
        b.setAttribute('aria-pressed', String(!this.ocultas.has(id)));
        this.animar = true;
        this.dibujar();
      });
    }

    nuevoSvg(ancho, alto) {
      this.lienzo.querySelector('svg')?.remove();
      const svg = el('svg', { viewBox: `0 0 ${ancho} ${alto}`, width: ancho, height: alto, class: this.animar ? 'animar' : '', role: 'img' });
      this.lienzo.insertBefore(svg, this.globo);
      this.animar = false;
      return svg;
    }

    mostrarGlobo(indice, x, y) {
      const cat = this.datos.categorias[indice];
      const filas = this.series().map((s) => ({ s, v: s.valores[indice] })).filter(({ v }) => v !== null && v !== undefined);
      this.globo.innerHTML = `<b>${esc(cat.titulo)}</b>${filas.map(({ s, v }) => `
        <span class="grafica__globo-fila"><i style="background:${s.color}"></i><span>${esc(s.nombre)}</span><b>${v}</b></span>`).join('')}`;
      this.globo.hidden = false;
      const caja = this.lienzo.getBoundingClientRect();
      const ancho = this.globo.offsetWidth;
      const izquierda = Math.max(0, Math.min(x + 14, caja.width - ancho));
      this.globo.style.transform = `translate(${x + 14 + ancho > caja.width ? Math.max(0, x - ancho - 14) : izquierda}px, ${Math.max(0, y)}px)`;
    }

    soltar() {
      this.globo.hidden = true;
      this.lienzo.querySelector('.grafica__banda')?.setAttribute('opacity', '0');
      this.lienzo.querySelectorAll('.grafica__guia, .grafica__marcador').forEach((n) => n.setAttribute('opacity', '0'));
    }

    /** Texto para lectores de pantalla con todos los valores. */
    resumen() {
      return `${this.seccion.querySelector('.grafica__titulo').textContent}. ` + this.series().map((s) => `${s.nombre}: ${
        s.valores.map((v, i) => (v ? `${this.datos.categorias[i].titulo} ${unidad(v, this.datos.unidad)}` : null)).filter(Boolean).join(', ') || 'sin registros'}`).join('. ');
    }
  }

  /* ---------- Barras ---------- */
  class Barras extends Grafica {
    constructor(seccion, datos) {
      super(seccion, datos);
      this.modo = 'agrupado';
      this.orientacion = 'vertical';
      seccion.querySelectorAll('[data-modo], [data-orientacion]').forEach((b) => b.addEventListener('click', () => {
        const clave = b.dataset.modo ? 'modo' : 'orientacion';
        this[clave] = b.dataset[clave];
        b.parentElement.querySelectorAll('button').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
        this.animar = true;
        this.dibujar();
      }));
    }

    dibujar() {
      const series = this.series();
      const cats = this.datos.categorias;
      const n = cats.length;
      const apilado = this.modo === 'apilado';
      const horizontal = this.orientacion === 'horizontal';
      const totales = cats.map((_, i) => series.reduce((t, s) => t + (s.valores[i] || 0), 0));
      const maximo = apilado ? Math.max(...totales) : Math.max(...series.flatMap((s) => s.valores));
      const { tope, marcas } = escala(maximo);
      const ancho = this.lienzo.clientWidth;
      const izq = horizontal ? 44 : 10 + String(tope).length * 8;
      const m = { arriba: 10, derecha: 10, abajo: horizontal ? 26 : 30, izquierda: izq };
      const alto = horizontal ? m.arriba + m.abajo + n * 22 : 190;
      const ancho_ = ancho - m.izquierda - m.derecha;
      const alto_ = alto - m.arriba - m.abajo;
      const svg = this.nuevoSvg(ancho, alto);
      svg.setAttribute('aria-label', this.resumen());

      const banda = el('rect', { class: 'grafica__banda', opacity: 0, rx: 6 }, svg);
      // Cuadrícula y marcas del eje de valores
      marcas.forEach((v) => {
        const p = (v / tope);
        if (horizontal) {
          const x = m.izquierda + p * ancho_;
          el('line', { x1: x, x2: x, y1: m.arriba, y2: alto - m.abajo, class: v === 0 ? 'grafica__base' : 'grafica__rejilla' }, svg);
          el('text', { x, y: alto - 8, class: 'grafica__marca', 'text-anchor': 'middle' }, svg).textContent = v;
        } else {
          const y = m.arriba + alto_ - p * alto_;
          el('line', { x1: m.izquierda, x2: ancho - m.derecha, y1: y, y2: y, class: v === 0 ? 'grafica__base' : 'grafica__rejilla' }, svg);
          el('text', { x: m.izquierda - 10, y: y + 4, class: 'grafica__marca', 'text-anchor': 'end' }, svg).textContent = v;
        }
      });

      const paso = (horizontal ? alto_ : ancho_) / n;
      const grupo = Math.min(paso * (apilado ? 0.5 : 0.72), apilado ? 22 : 12 * series.length + 3 * (series.length - 1));
      const separacion = series.length > 1 ? 3 : 0;
      const grueso = apilado ? grupo : (grupo - separacion * (series.length - 1)) / series.length;
      let orden = 0;
      cats.forEach((cat, i) => {
        const inicio = (horizontal ? m.arriba : m.izquierda) + i * paso + (paso - grupo) / 2;
        let acumulado = 0;
        series.forEach((s, k) => {
          const v = s.valores[i] || 0;
          if (!v) return;
          const largo = (v / tope) * (horizontal ? ancho_ : alto_);
          const desplazado = (acumulado / tope) * (horizontal ? ancho_ : alto_);
          const esUltima = !apilado || series.slice(k + 1).every((o) => !(o.valores[i] || 0));
          const pos = apilado ? inicio : inicio + k * (grueso + separacion);
          const d = horizontal
            ? barra(m.izquierda + desplazado, pos, largo, grueso, esUltima ? 4 : 0, true)
            : barra(pos, m.arriba + alto_ - desplazado - largo, grueso, largo, esUltima ? 4 : 0, false);
          el('path', { d, fill: s.color, class: `grafica__barra${horizontal ? ' grafica__barra--h' : ''}`, style: `animation-delay:${Math.min(orden++ * 14, 420)}ms` }, svg);
          if (apilado) acumulado += v;
        });
        // Etiqueta de la categoría
        if (horizontal) {
          el('text', { x: m.izquierda - 10, y: inicio + grupo / 2 + 4, class: 'grafica__categoria', 'text-anchor': 'end' }, svg).textContent = cat.etiqueta;
        } else if (paso >= 30 || (n - 1 - i) % 2 === 0) {   // con poco espacio, uno sí y uno no, siempre con el mes actual
          el('text', { x: inicio + grupo / 2, y: alto - 8, class: 'grafica__categoria', 'text-anchor': 'middle' }, svg).textContent = cat.etiqueta;
        }
        // Zona sensible de toda la categoría: resalta y muestra el globo
        const zona = horizontal
          ? el('rect', { x: m.izquierda, y: m.arriba + i * paso, width: ancho_, height: paso, fill: 'transparent' }, svg)
          : el('rect', { x: m.izquierda + i * paso, y: m.arriba, width: paso, height: alto_, fill: 'transparent' }, svg);
        zona.addEventListener('mousemove', (ev) => {
          const atr = horizontal
            ? { x: m.izquierda - 4, y: m.arriba + i * paso + 2, width: ancho_ + 8, height: paso - 4 }
            : { x: m.izquierda + i * paso + 2, y: m.arriba, width: paso - 4, height: alto_ };
          Object.entries(atr).forEach(([k, v]) => banda.setAttribute(k, v));
          banda.setAttribute('opacity', '1');
          const caja = this.lienzo.getBoundingClientRect();
          this.mostrarGlobo(i, ev.clientX - caja.left, horizontal ? m.arriba + i * paso - 4 : 4);
        });
      });
    }
  }

  /* ---------- Línea / área ---------- */
  class Lineas extends Grafica {
    constructor(seccion, datos) {
      super(seccion, datos);
      this.tipo = 'area';
      seccion.querySelectorAll('[data-tipo]').forEach((b) => b.addEventListener('click', () => {
        this.tipo = b.dataset.tipo;
        b.parentElement.querySelectorAll('button').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
        this.animar = true;
        this.dibujar();
      }));
    }

    dibujar() {
      const series = this.series();
      const cats = this.datos.categorias;
      const n = cats.length;
      const maximo = Math.max(...series.flatMap((s) => s.valores.filter((v) => v !== null)));
      const { tope, marcas } = escala(maximo);
      const ancho = this.lienzo.clientWidth;
      const m = { arriba: 12, derecha: 14, abajo: 30, izquierda: 10 + String(tope).length * 8 };
      const alto = 190;
      const ancho_ = ancho - m.izquierda - m.derecha;
      const alto_ = alto - m.arriba - m.abajo;
      const x = (i) => m.izquierda + (i / (n - 1)) * ancho_;
      const y = (v) => m.arriba + alto_ - (v / tope) * alto_;
      const svg = this.nuevoSvg(ancho, alto);
      svg.setAttribute('aria-label', this.resumen());
      const uid = Math.random().toString(36).slice(2, 7);

      // Degradados: relleno que se desvanece hacia abajo y hacia los bordes
      const defs = el('defs', {}, svg);
      const borde = el('linearGradient', { id: `borde-${uid}`, x1: 0, x2: 1, y1: 0, y2: 0 }, defs);
      [[0, 0], [0.06, 1], [0.94, 1], [1, 0]].forEach(([o, a]) => el('stop', { offset: o, 'stop-color': '#fff', 'stop-opacity': a }, borde));
      const mascara = el('mask', { id: `mascara-${uid}` }, defs);
      el('rect', { x: 0, y: 0, width: ancho, height: alto, fill: `url(#borde-${uid})` }, mascara);

      marcas.forEach((v) => {
        el('line', { x1: m.izquierda, x2: ancho - m.derecha, y1: y(v), y2: y(v), class: v === 0 ? 'grafica__base' : 'grafica__rejilla' }, svg);
        el('text', { x: m.izquierda - 10, y: y(v) + 4, class: 'grafica__marca', 'text-anchor': 'end' }, svg).textContent = v;
      });
      cats.forEach((c, i) => {
        if (ancho_ / n >= 44 || i % 2 === 1) el('text', { x: x(i), y: alto - 8, class: 'grafica__categoria', 'text-anchor': i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle' }, svg).textContent = c.etiqueta;
      });

      const areas = el('g', { mask: `url(#mascara-${uid})` }, svg);
      const lineas = el('g', {}, svg);
      series.forEach((s, k) => {
        const puntos = s.valores.map((v, i) => (v === null ? null : [x(i), y(v)])).filter(Boolean);
        const d = curva(puntos);
        if (this.tipo === 'area' && puntos.length > 1) {
          const grad = el('linearGradient', { id: `area-${uid}-${k}`, x1: 0, x2: 0, y1: 0, y2: 1 }, defs);
          el('stop', { offset: 0, 'stop-color': s.color, 'stop-opacity': 0.34 }, grad);
          el('stop', { offset: 1, 'stop-color': s.color, 'stop-opacity': 0 }, grad);
          const ultimo = puntos[puntos.length - 1];
          el('path', { d: `${d}L${ultimo[0]},${y(0)}L${puntos[0][0]},${y(0)}Z`, fill: `url(#area-${uid}-${k})`, class: 'grafica__area' }, areas);
        }
        const linea = el('path', { d, fill: 'none', stroke: s.color, 'stroke-width': 2, 'stroke-linecap': 'round', class: 'grafica__linea' }, lineas);
        const largo = linea.getTotalLength?.() || 0;
        linea.style.setProperty('--largo', largo);
      });

      // Guía vertical y marcadores al pasar el ratón
      const bandaAncho = ancho_ / (n - 1);
      const banda = el('rect', { class: 'grafica__banda', y: m.arriba, height: alto_, width: bandaAncho, rx: 6, opacity: 0 }, svg);
      const guia = el('line', { y1: m.arriba, y2: m.arriba + alto_, class: 'grafica__guia', opacity: 0 }, svg);
      const marcadores = series.map((s) => el('circle', { r: 4, fill: s.color, class: 'grafica__marcador', opacity: 0 }, svg));
      const zona = el('rect', { x: m.izquierda, y: m.arriba, width: ancho_, height: alto_, fill: 'transparent' }, svg);
      zona.addEventListener('mousemove', (ev) => {
        const caja = svg.getBoundingClientRect();
        const i = Math.max(0, Math.min(n - 1, Math.round(((ev.clientX - caja.left - m.izquierda) / ancho_) * (n - 1))));
        guia.setAttribute('x1', x(i));
        guia.setAttribute('x2', x(i));
        guia.setAttribute('opacity', '1');
        banda.setAttribute('x', x(i) - bandaAncho / 2);
        banda.setAttribute('opacity', '1');
        series.forEach((s, k) => {
          const v = s.valores[i];
          marcadores[k].setAttribute('opacity', v === null ? '0' : '1');
          if (v !== null) { marcadores[k].setAttribute('cx', x(i)); marcadores[k].setAttribute('cy', y(v)); }
        });
        this.mostrarGlobo(i, x(i), 4);
      });
    }
  }

  /* ---------- Dona (proporciones de una serie) ---------- */
  class Dona extends Grafica {
    series() { return this.datos.series; }

    pintarLeyenda() {
      const s = this.datos.series[0];
      const total = s.valores.reduce((t, v) => t + (v || 0), 0) || 1;
      this.leyenda.innerHTML = this.datos.categorias.map((c, i) => `
        <li><button type="button" aria-pressed="true" data-indice="${i}"><span class="grafica__punto" style="background:${this.datos.colores[i]}"></span>${esc(c.titulo)}<b class="grafica__porcentaje">${Math.round(((s.valores[i] || 0) / total) * 100)}%</b></button></li>`).join('');
      this.leyenda.addEventListener('mouseover', (ev) => { const b = ev.target.closest('[data-indice]'); if (b) this.resaltar(Number(b.dataset.indice)); });
      this.leyenda.addEventListener('mouseleave', () => this.resaltar(null));
    }

    resaltar(indice) {
      this.lienzo.querySelectorAll('.grafica__sector').forEach((p, i) => p.setAttribute('opacity', indice === null || indice === i ? '1' : '.3'));
    }

    dibujar() {
      const s = this.datos.series[0];
      const valores = s.valores.map((v) => Math.max(0, v || 0));
      const total = valores.reduce((t, v) => t + v, 0);
      const ancho = this.lienzo.clientWidth;
      const alto = 200;
      const svg = this.nuevoSvg(ancho, alto);
      svg.setAttribute('aria-label', this.datos.categorias.map((c, i) => `${c.titulo}: ${valores[i]}`).join(', '));
      const cx = ancho / 2, cy = alto / 2, r = 86, grosor = 22;
      if (!total) return;
      let angulo = -Math.PI / 2;
      valores.forEach((v, i) => {
        if (!v) return;
        const fin = angulo + (v / total) * Math.PI * 2 - (valores.filter(Boolean).length > 1 ? 0.025 : 0);
        const grande = fin - angulo > Math.PI ? 1 : 0;
        const p = (a, radio) => `${cx + radio * Math.cos(a)},${cy + radio * Math.sin(a)}`;
        const d = fin - angulo >= Math.PI * 2 - 0.001
          ? `M${p(angulo, r)}A${r},${r} 0 1 1 ${p(angulo + Math.PI, r)}A${r},${r} 0 1 1 ${p(angulo, r)}M${p(angulo, r - grosor)}A${r - grosor},${r - grosor} 0 1 0 ${p(angulo + Math.PI, r - grosor)}A${r - grosor},${r - grosor} 0 1 0 ${p(angulo, r - grosor)}Z`
          : `M${p(angulo, r)}A${r},${r} 0 ${grande} 1 ${p(fin, r)}L${p(fin, r - grosor)}A${r - grosor},${r - grosor} 0 ${grande} 0 ${p(angulo, r - grosor)}Z`;
        const sector = el('path', { d, fill: this.datos.colores[i], class: 'grafica__sector', 'fill-rule': 'evenodd', style: `animation-delay:${i * 50}ms` }, svg);
        sector.addEventListener('mousemove', (ev) => {
          this.resaltar(i);
          const caja = this.lienzo.getBoundingClientRect();
          this.globo.innerHTML = `<b>${esc(this.datos.categorias[i].titulo)}</b><span class="grafica__globo-fila"><i style="background:${this.datos.colores[i]}"></i><span>${esc(s.nombre)}</span><b>${valores[i]}</b></span>`;
          this.globo.hidden = false;
          this.globo.style.transform = `translate(${Math.min(ev.clientX - caja.left + 14, caja.width - this.globo.offsetWidth)}px, 4px)`;
        });
        sector.addEventListener('mouseleave', () => { this.resaltar(null); this.globo.hidden = true; });
        angulo = fin + (valores.filter(Boolean).length > 1 ? 0.025 : 0);
      });
      el('text', { x: cx, y: cy - 2, class: 'grafica__total', 'text-anchor': 'middle' }, svg).textContent = Number.isInteger(total) ? total : total.toFixed(1);
      el('text', { x: cx, y: cy + 18, class: 'grafica__categoria', 'text-anchor': 'middle' }, svg).textContent = s.nombre;
    }
  }

  /* ---------- Gráficas libres (chat del asistente y bloques de notas) ---------- */
  const PALETA = ['#2563eb', '#a1a1aa', '#22c55e', '#f59e0b', '#ec4899', '#8b5cf6', '#14b8a6', '#ef4444', '#eab308', '#64748b'];
  const TIPOS_LIBRES = ['barras', 'barras_apiladas', 'barras_horizontales', 'lineas', 'area', 'dona'];

  /** Valida y normaliza {tipo, titulo, subtitulo, categorias:[…], series:[{nombre, valores:[…]}], unidad}. */
  function normalizar(def) {
    if (!def || typeof def !== 'object') return null;
    // Nombres alternativos que usan los modelos (estilo Chart.js o en inglés)
    def = { ...def };
    def.categorias ??= def.etiquetas || def.labels || def.categories || def.data?.labels;
    def.titulo ??= def.title || def.options?.plugins?.title?.text;
    def.tipo = ({ bar: 'barras', line: 'lineas', pie: 'dona', doughnut: 'dona', area: 'area' })[def.tipo || def.type] || def.tipo || def.type;
    const conjuntos = def.series || def.datasets || def.data?.datasets;
    if (Array.isArray(conjuntos)) {
      def.series = conjuntos.map((s) => ({ nombre: s.nombre ?? s.label ?? s.name, valores: s.valores ?? s.data ?? s.values }));
    } else if (Array.isArray(def.valores || def.values)) {
      def.series = [{ nombre: def.nombre || 'Valores', valores: def.valores || def.values }];
    }
    const categorias = (Array.isArray(def.categorias) ? def.categorias : []).slice(0, 60).map((c) => String(c ?? '').slice(0, 40));
    const series = (Array.isArray(def.series) ? def.series : []).slice(0, 10).map((s, k) => ({
      id: `s${k}`, nombre: String(s?.nombre ?? `Serie ${k + 1}`).slice(0, 60), color: PALETA[k % PALETA.length],
      valores: categorias.map((_, i) => { const v = Number((s?.valores || [])[i]); return Number.isFinite(v) ? v : null; }),
    })).filter((s) => s.valores.some((v) => v !== null));
    if (!categorias.length || !series.length) return null;
    let tipo = TIPOS_LIBRES.includes(def.tipo) ? def.tipo : 'barras';
    if ((tipo === 'lineas' || tipo === 'area') && categorias.length < 2) tipo = 'barras';
    const unidad = Array.isArray(def.unidad) ? def.unidad.map(String) : ['', ''];
    return {
      tipo, titulo: String(def.titulo || '').slice(0, 120), subtitulo: String(def.subtitulo || '').slice(0, 200), unidad,
      categorias: categorias.map((c) => ({ titulo: c, etiqueta: c.length > 12 ? `${c.slice(0, 11)}…` : c })),
      series: tipo === 'dona' ? series.slice(0, 1) : series,
      colores: categorias.map((_, i) => PALETA[i % PALETA.length]),
    };
  }

  /** Dibuja una gráfica dentro de un contenedor. Devuelve la sección creada o null si los datos no sirven. */
  function montar(contenedor, def) {
    const d = normalizar(def);
    if (!d) return null;
    const seccion = document.createElement('section');
    seccion.className = 'grafica grafica--libre';
    seccion.innerHTML = `<div class="grafica__cabecera"><div>${d.titulo ? `<h3 class="grafica__titulo">${esc(d.titulo)}</h3>` : '<h3 class="grafica__titulo" hidden></h3>'}${d.subtitulo ? `<p class="grafica__subtitulo">${esc(d.subtitulo)}</p>` : ''}</div>`
      + '</div>'
      + '<div class="grafica__lienzo" data-grafica-lienzo></div><ul class="grafica__leyenda" data-grafica-leyenda></ul>';
    contenedor.appendChild(seccion);
    if (d.tipo === 'dona') {
      new Dona(seccion, d);
    } else if (d.tipo.startsWith('barras')) {
      const g = new Barras(seccion, d);
      if (d.tipo === 'barras_apiladas') g.modo = 'apilado';
      if (d.tipo === 'barras_horizontales') g.orientacion = 'horizontal';
    } else {
      const g = new Lineas(seccion, d);
      g.tipo = d.tipo === 'area' ? 'area' : 'linea';
    }
    return seccion;
  }

  return {
    montar, normalizar, TIPOS: TIPOS_LIBRES,
    iniciar() {
      const fuente = document.getElementById('graficas-datos');
      if (!fuente) return;
      const datos = JSON.parse(fuente.textContent);
      document.querySelectorAll('[data-grafica]').forEach((seccion) => {
        if (!seccion.querySelector('[data-grafica-lienzo]')) return;
        const tipo = seccion.dataset.grafica;
        if (tipo === 'barras' && datos.barras.series.length) new Barras(seccion, datos.barras);
        if (tipo === 'lineas' && datos.lineas.series.length) new Lineas(seccion, datos.lineas);
      });
    },
  };
})();

document.addEventListener('DOMContentLoaded', () => Graficas.iniciar());
