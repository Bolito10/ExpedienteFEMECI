/* ==========================================================================
   Expedientes Clínicos — renderizador de Markdown para las respuestas del asistente
   Seguro: todo el texto se escapa antes de convertirlo; solo se generan etiquetas
   conocidas y enlaces http(s)/mailto. Tolera texto a medio llegar (streaming):
   cierra bloques de código abiertos y oculta marcadores sueltos (**, __, ~~),
   para que nunca se vean asteriscos ni símbolos sin formato.
   ========================================================================== */

const Markdown = (() => {
  const escapar = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const urlInterna = (u) => /^\/(?!\/)/.test(u);   // rutas del propio sistema: /ExpedienteFEMECI/paginas/ver.php?id=…
  const urlSegura = (u) => /^(https?:\/\/|mailto:)/i.test(u) || urlInterna(u);
  // Junto a cada enlace se muestra su dominio: así se ve a dónde lleva antes de abrirlo
  const dominio = (u) => {
    try { return u.startsWith('mailto:') ? '' : `<span class="md__dominio">${escapar(new URL(u).hostname)}</span>`; } catch (e) { return ''; }
  };

  const base = () => (typeof BASE !== 'undefined' ? BASE : '');
  const rutaImagen = (u) => {
    const nota = u.match(/^assets\/subidas\/(nota-[0-9a-f]{16}\.(?:jpg|png|webp|gif))$/i);
    if (nota) return `${base()}/api/paginas/imagen.php?f=${nota[1]}`;
    if (/^https?:\/\//i.test(u)) return `${base()}/api/asistente/imagen.php?u=${encodeURIComponent(u)}`;
    return null;
  };

  /** JSON que escriben los modelos: tolera comas sobrantes, comillas simples, claves sin comillas y texto alrededor. */
  function leerJsonTolerante(texto) {
    try { return JSON.parse(texto); } catch (e) { /* se intenta reparar */ }
    const m = String(texto).match(/\{[\s\S]*\}/);
    if (!m) return null;
    const reparado = m[0]
      .replace(/\/\/[^\n]*/g, '')
      .replace(/'([^'\\]*(?:\\.[^'\\]*)*)'/g, (_, s) => JSON.stringify(s))
      .replace(/([{,]\s*)([A-Za-z_áéíóúñ][\wáéíóúñ]*)\s*:/g, '$1"$2":')
      .replace(/,\s*([}\]])/g, '$1');
    try { return JSON.parse(reparado); } catch (e) { return null; }
  }

  /* ---------- En línea ---------- */
  function enLinea(texto) {
    const guardados = [];
    const guardar = (html) => `\u0001${guardados.push(html) - 1}\u0002`;
    let t = String(texto);

    // Código en línea (se respeta tal cual)
    t = t.replace(/(`+)([^`]|[^`][\s\S]*?[^`])\1(?!`)/g, (_, __, codigo) => guardar(`<code>${escapar(codigo.trim())}</code>`));
    // Escapes con barra invertida: \* \_ \# …
    t = t.replace(/\\([\\`*_{}[\]()#+\-.!|~>])/g, (_, c) => guardar(escapar(c)));
    // Imágenes ![pie](url): las de internet pasan por el servidor (la política de seguridad solo admite las propias)
    t = t.replace(/!\[([^\]]*)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/g, (todo, pie, url) => {
      const src = rutaImagen(url);
      if (!src) return pie;
      return guardar(`<span class="md__imagen"><a href="${escapar(/^https?:/i.test(url) ? url : src)}" target="_blank" rel="noopener noreferrer"><img src="${escapar(src)}" alt="${escapar(pie)}" loading="lazy"></a>${pie ? `<span class="md__pie">${escapar(pie)}</span>` : ''}</span>`);
    });
    // Enlaces [texto](url) y direcciones sueltas
    t = t.replace(/\[([^\]]+)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/g, (todo, txt, url) => (urlSegura(url)
      ? guardar(urlInterna(url)
        ? `<a href="${escapar(url)}" class="md__interno">${enLinea(txt)}</a>`
        : `<a href="${escapar(url)}" target="_blank" rel="noopener noreferrer">${enLinea(txt)}</a>${dominio(url)}`)
      : txt));
    t = t.replace(/(^|[\s(])((?:https?:\/\/)[^\s<>()]+[^\s<>().,;:!?'"])/g, (_, antes, url) => antes + guardar(`<a href="${escapar(url)}" target="_blank" rel="noopener noreferrer">${escapar(url)}</a>`));

    t = escapar(t);

    // Énfasis (de más a menos específico; se repite para permitir anidación)
    for (let i = 0; i < 3; i++) {
      t = t
        .replace(/\*\*\*(?=\S)([\s\S]*?\S)\*\*\*/g, '<strong><em>$1</em></strong>')
        .replace(/\*\*(?=\S)([\s\S]*?\S)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^\w])__(?=\S)([\s\S]*?\S)__(?!\w)/g, '$1<strong>$2</strong>')
        .replace(/(^|[^*\w])\*(?=[^\s*])([^*\n]*?[^\s*])\*(?!\*)/g, '$1<em>$2</em>')
        .replace(/(^|[^\w])_(?=[^\s_])([^_\n]*?[^\s_])_(?!\w)/g, '$1<em>$2</em>')
        .replace(/~~(?=\S)([\s\S]*?\S)~~/g, '<del>$1</del>');
    }
    // Marcadores que quedaron sin pareja (p. ej. a mitad del streaming): no se muestran
    t = t.replace(/\*{2,}|_{2,}(?=\s|$)|(^|\s)_{2,}|~~/g, (m, espacio) => (typeof espacio === 'string' ? espacio : ''));
    t = t.replace(/(^|\s)\*(?=\S)(?![^*]*\*)/g, '$1');

    return t.replace(/\u0001(\d+)\u0002/g, (_, i) => guardados[Number(i)]);
  }

  /* ---------- Bloques ---------- */
  const reCerca = /^ {0,3}(`{3,}|~{3,})\s*([\w+#.á-]*)(.*)$/u;
  const reTitulo = /^ {0,3}(#{1,6})\s+(.*?)\s*#*\s*$/;
  const reLinea = /^ {0,3}([-*_])(\s*\1){2,}\s*$/;
  const reCita = /^ {0,3}>\s?/;
  const reItem = /^(\s*)([-*+•]|\d{1,3}[.)])\s+(.*)$/;
  const reSeparadorTabla = /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/;
  const sangria = (l) => l.match(/^\s*/)[0].replace(/\t/g, '    ').length;

  function celdas(linea) {
    let l = linea.trim();
    if (l.startsWith('|')) l = l.slice(1);
    if (l.endsWith('|') && !l.endsWith('\\|')) l = l.slice(0, -1);
    return l.split(/(?<!\\)\|/).map((c) => c.trim());
  }

  function bloques(lineas) {
    const html = [];
    let parrafo = [];
    const cerrarParrafo = () => {
      if (parrafo.length) html.push(`<p>${parrafo.map(enLinea).join('<br>')}</p>`);
      parrafo = [];
    };

    for (let i = 0; i < lineas.length; i++) {
      const linea = lineas[i];
      let m;

      if ((m = linea.match(reCerca))) {
        cerrarParrafo();
        const cerca = m[1];
        const codigo = [];
        if (/^\s*[{[]/.test(m[3] || '')) codigo.push(m[3]);   // ```grafica { … en la misma línea
        for (i++; i < lineas.length && !new RegExp(`^ {0,3}${cerca[0]}{${cerca.length},}\\s*$`).test(lineas[i]); i++) codigo.push(lineas[i]);
        if (/^(grafica|gráfica|chart|json)$/i.test(m[2])) {
          const cerrado = i < lineas.length;
          const definicion = leerJsonTolerante(codigo.join('\n'));
          if (definicion && (m[2].toLowerCase() !== 'json' || definicion.series || definicion.datasets)) {
            html.push(`<div class="md__grafica" data-md-grafica="${escapar(JSON.stringify(definicion))}"></div>`);
            continue;
          }
          if (!cerrado && m[2].toLowerCase() !== 'json') { html.push('<div class="md__grafica md__grafica--cargando">Preparando la gráfica…</div>'); continue; }
        }
        html.push(`<pre>${m[2] ? `<span class="md__lenguaje">${escapar(m[2])}</span>` : ''}<code>${escapar(codigo.join('\n'))}</code></pre>`);
        continue;
      }
      if (!linea.trim()) { cerrarParrafo(); continue; }
      if ((m = linea.match(reTitulo))) {
        cerrarParrafo();
        const nivel = Math.min(m[1].length, 4);
        html.push(`<h${nivel}>${enLinea(m[2])}</h${nivel}>`);
        continue;
      }
      if (reLinea.test(linea) && !(parrafo.length && /^ {0,3}-+\s*$/.test(linea))) { cerrarParrafo(); html.push('<hr>'); continue; }
      if (reLinea.test(linea)) { // texto subrayado con --- : encabezado
        const texto = parrafo.join(' ');
        parrafo = [];
        html.push(`<h3>${enLinea(texto)}</h3>`);
        continue;
      }
      // Tabla (encabezado + separador)
      if (linea.includes('|') && i + 1 < lineas.length && reSeparadorTabla.test(lineas[i + 1])) {
        cerrarParrafo();
        const cabecera = celdas(linea);
        const alineacion = celdas(lineas[i + 1]).map((c) => (c.startsWith(':') && c.endsWith(':') ? 'center' : c.endsWith(':') ? 'right' : ''));
        const filas = [];
        for (i += 2; i < lineas.length && lineas[i].includes('|') && lineas[i].trim(); i++) filas.push(celdas(lineas[i]));
        i--;
        const estilo = (k) => (alineacion[k] ? ` style="text-align:${alineacion[k]}"` : '');
        html.push(`<div class="md__tabla"><table><thead><tr>${cabecera.map((c, k) => `<th${estilo(k)}>${enLinea(c)}</th>`).join('')}</tr></thead><tbody>${
          filas.map((f) => `<tr>${cabecera.map((_, k) => `<td${estilo(k)}>${enLinea(f[k] ?? '')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`);
        continue;
      }
      // Cita
      if (reCita.test(linea)) {
        cerrarParrafo();
        const cita = [];
        for (; i < lineas.length && (reCita.test(lineas[i]) || (lineas[i].trim() && cita.length && !reItem.test(lineas[i]) && !reTitulo.test(lineas[i]))); i++) {
          cita.push(lineas[i].replace(reCita, ''));
        }
        i--;
        html.push(`<blockquote>${bloques(cita)}</blockquote>`);
        continue;
      }
      // Lista (con anidación por sangría)
      if ((m = linea.match(reItem))) {
        cerrarParrafo();
        const fin = finDeLista(lineas, i);
        html.push(lista(lineas.slice(i, fin)));
        i = fin - 1;
        continue;
      }
      parrafo.push(linea.trim());
    }
    cerrarParrafo();
    return html.join('');
  }

  function finDeLista(lineas, inicio) {
    const base = sangria(lineas[inicio]);
    let i = inicio + 1;
    for (; i < lineas.length; i++) {
      const l = lineas[i];
      if (!l.trim()) {
        const siguiente = lineas.slice(i + 1).find((x) => x.trim());
        if (!siguiente || (sangria(siguiente) <= base && !reItem.test(siguiente))) break;
        continue;
      }
      if (sangria(l) < base) break;
      if (sangria(l) === base && !reItem.test(l) && (reTitulo.test(l) || reCerca.test(l) || reLinea.test(l))) break;
    }
    return i;
  }

  function lista(lineas) {
    const base = sangria(lineas[0]);
    const ordenada = /\d/.test(lineas[0].match(reItem)[2]);
    const inicio = ordenada ? parseInt(lineas[0].match(reItem)[2], 10) : 1;
    const items = [];
    lineas.forEach((l) => {
      const m = l.match(reItem);
      if (m && sangria(l) === base) items.push([m[3]]);
      else if (items.length) items[items.length - 1].push(l.slice(Math.min(sangria(l), base + 2)));
    });
    const holgada = lineas.some((l) => !l.trim());
    const contenido = items.map((partes) => {
      let primera = partes[0];
      let tarea = '';
      const t = primera.match(/^\[([ xX])\]\s+(.*)$/);
      if (t) {
        tarea = `<span class="md__casilla${t[1] !== ' ' ? ' md__casilla--hecha' : ''}" aria-hidden="true"></span>`;
        primera = t[2];
      }
      const resto = partes.slice(1);
      const dentro = resto.some((r) => r.trim())
        ? (holgada ? bloques([primera, ...resto]) : `${enLinea(primera)}${bloques(resto)}`)
        : (holgada ? `<p>${enLinea(primera)}</p>` : enLinea(primera));
      return `<li${tarea ? ' class="md__tarea"' : ''}>${tarea}${dentro}</li>`;
    }).join('');
    return ordenada ? `<ol${inicio !== 1 ? ` start="${inicio}"` : ''}>${contenido}</ol>` : `<ul>${contenido}</ul>`;
  }

  return {
    render(texto) {
      const normalizado = String(texto || '').replace(/\r\n?/g, '\n').replace(/ /g, ' ');
      return bloques(normalizado.split('\n'));
    },
  };
})();
