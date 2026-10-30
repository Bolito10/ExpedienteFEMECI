/*
  Herramienta de pensamiento del asistente: estilo lineal vertical con la bolita "aurora".
  Basado en el componente "razonamiento-aurora-thinking" del usuario (port de "Chain of Thought UI",
  Apache-2.0) y el motor WebGL de Paper Shaders (assets/vendor/aurora, Apache-2.0).

  Uso:
    const r = Razonamiento.crear(contenedor, marco);   // marco: elemento sin scroll que recorta las líneas
    r.agregar('fragmento de razonamiento…');            // conforme llega del modelo
    r.terminar();                                       // al empezar la respuesta (se colapsa)

  Ajustes respecto al original:
    - Las líneas entre pasos son una línea fija; el paso en curso lleva un brillo que la recorre.
    - Cada paso lleva un icono según su tema (buscar, leer, analizar, escribir…).
    - Al terminar, la bolita congela su último cuadro (misma imagen) y libera su contexto WebGL.
    - Los pasos se arman con el texto real del razonamiento, párrafo por párrafo.
*/
(function () {
  'use strict';

  function svg(trazos) {
    return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + trazos + '</svg>';
  }
  var ICONOS = {
    buscar: svg('<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>'),
    leer: svg('<path d="M3 5.5A1.5 1.5 0 0 1 4.5 4H9a3 3 0 0 1 3 3v13a2.5 2.5 0 0 0-2.5-2.5H4.5A1.5 1.5 0 0 1 3 16z"/><path d="M21 5.5A1.5 1.5 0 0 0 19.5 4H15a3 3 0 0 0-3 3v13a2.5 2.5 0 0 1 2.5-2.5h5a1.5 1.5 0 0 0 1.5-1.5z"/>'),
    analizar: svg('<path d="M12 3.5 13.8 9a1 1 0 0 0 .6.6l5.6 1.9-5.6 1.9a1 1 0 0 0-.6.6L12 19.5 10.2 14a1 1 0 0 0-.6-.6L4 11.5l5.6-1.9a1 1 0 0 0 .6-.6z"/><path d="M19 3v3M20.5 4.5h-3"/>'),
    planear: svg('<path d="M9 6h11M9 12h11M9 18h11"/><path d="m3.5 6 1 1 2-2M3.5 12l1 1 2-2M3.5 18l1 1 2-2"/>'),
    escribir: svg('<path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17z"/><path d="m14.5 7.5 3 3"/>'),
    datos: svg('<ellipse cx="12" cy="5.5" rx="7.5" ry="2.5"/><path d="M4.5 5.5v13c0 1.4 3.4 2.5 7.5 2.5s7.5-1.1 7.5-2.5v-13"/><path d="M4.5 12c0 1.4 3.4 2.5 7.5 2.5s7.5-1.1 7.5-2.5"/>'),
    paciente: svg('<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>'),
    clinica: svg('<path d="M12 21s-7.5-4.6-7.5-10.4A4.4 4.4 0 0 1 12 8a4.4 4.4 0 0 1 7.5 2.6C19.5 16.4 12 21 12 21z"/><path d="M8 13h2.2l1.3-2.2 1.8 4 1.2-1.8H16"/>'),
    norma: svg('<path d="M12 3 4.5 6v5.5c0 4.5 3.2 8.2 7.5 9.5 4.3-1.3 7.5-5 7.5-9.5V6z"/><path d="m9 12 2 2 4-4"/>'),
    calcular: svg('<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8.5 7h7M8.5 11h.01M12 11h.01M15.5 11h.01M8.5 14.5h.01M12 14.5h.01M15.5 14.5v3M8.5 17.5h.01M12 17.5h.01"/>'),
    web: svg('<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.3 2.4 3.5 5.2 3.5 8.5s-1.2 6.1-3.5 8.5c-2.3-2.4-3.5-5.2-3.5-8.5S9.7 5.9 12 3.5z"/>'),
    agenda: svg('<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>'),
    idea: svg('<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.6 10.8c.6.5 1 1.2 1.1 2V16h5v-.2c.1-.8.5-1.5 1.1-2A6 6 0 0 0 12 3z"/>'),
    chevron: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>'
  };
  // El icono de cada paso se elige por lo que dice su título
  var TEMAS = [
    ['buscar', /busc|encontr|localiz|search|find|explor/i],
    ['web', /internet|web|sitio|fuente|investig|consult|referenc|art[ií]culo/i],
    ['leer', /le(er|yendo|o)\b|revis|lectura|read|review|examin/i],
    ['norma', /nom[- ]|norma|lfpdppp|legal|ley|regla|cumpl|seguridad|privacidad/i],
    ['paciente', /paciente|persona|usuario|perfil|expediente/i],
    ['clinica', /cl[ií]nic|diagn|s[ií]ntom|tratamiento|m[eé]dic|signos|alergi|receta|dosis/i],
    ['agenda', /cita|agenda|fecha|horario|calendario|recordatorio/i],
    ['datos', /base de datos|tabla|fila|columna|propiedad|registro|datos/i],
    ['calcular', /calcul|cuent|suma|promedio|total|n[uú]mero|imc/i],
    ['escribir', /escrib|redact|nota|document|respuesta|formul|resum|draft|writ/i],
    ['planear', /plan|organiz|pasos|estructur|orden|list|estrateg/i],
    ['analizar', /analiz|evalu|consider|pens|razon|compar|entend|determin|identific/i]
  ];
  function iconoDe(titulo) {
    for (var i = 0; i < TEMAS.length; i++) if (TEMAS[i][1].test(titulo)) return TEMAS[i][0];
    return 'idea';
  }
  var motorDisponible = typeof g3e === 'function' && typeof A3e !== 'undefined' && typeof k2 === 'function'
    && typeof _3e === 'function' && typeof f3e !== 'undefined' && typeof N3e === 'function';

  /* ---------- Bolita aurora (WebGL mientras piensa; CSS al terminar) ---------- */
  function crearBolita() {
    var caja = document.createElement('span');
    caja.className = 'cot-bolita';
    caja.setAttribute('aria-hidden', 'true');
    var montaje = null;
    var d = (window.DISENOS || []).filter(function (x) { return x.id === 'aurora'; })[0];

    function usarCss() { caja.classList.add('cot-bolita-aurora-css'); }

    if (motorDisponible && d) {
      try {
        var textura = N3e();
        var montar = function () {
          try {
            montaje = new g3e(caja, A3e, {
              u_colorBack: k2('#ffffff00'), u_colors: d.paleta.map(k2), u_colorsCount: d.paleta.length,
              u_softness: d.softness, u_intensity: d.intensity, u_noise: d.noise,
              u_shape: (window.R3e && R3e[d.forma]) || 8, u_noiseTexture: textura,
              u_fit: 0, u_scale: 1, u_rotation: 0, u_offsetX: 0, u_offsetY: 0,
              u_originX: .5, u_originY: .5, u_worldWidth: 0, u_worldHeight: 0
            }, { preserveDrawingBuffer: true }, d.speed, Math.round(Math.random() * 1e5));
            if (!caja.querySelector('canvas')) usarCss();
          } catch (e) { usarCss(); }
        };
        if (textura.complete && textura.naturalWidth > 0) montar(); else textura.onload = montar;
        textura.onerror = usarCss;
      } catch (e) { usarCss(); }
    } else {
      usarCss();
    }

    return {
      el: caja,
      // Libera el contexto WebGL: los navegadores pierden contextos si una página acumula muchos
      // Al terminar se congela el último cuadro de la misma bolita (una imagen) y se libera WebGL
      apagar: function () {
        var lienzo = caja.querySelector('canvas');
        var congelada = false;
        if (montaje && lienzo) {
          try { montaje.setSpeed(0); } catch (e) { /* sin pausa */ }
          try {
            var foto = document.createElement('img');
            foto.className = 'cot-bolita__foto';
            foto.alt = '';
            foto.src = lienzo.toDataURL('image/png');
            caja.appendChild(foto);
            congelada = true;
          } catch (e) { /* sin captura */ }
        }
        if (montaje) { try { montaje.dispose(); } catch (e) { /* ya liberado */ } montaje = null; }
        caja.querySelectorAll('canvas').forEach(function (c) { c.remove(); });
        if (!congelada && !caja.classList.contains('cot-bolita-aurora-css')) caja.remove();
        caja.classList.add('quieta');
      }
    };
  }

  /* ---------- Panel de razonamiento ---------- */
  function crear(contenedor, marco) {
    var inicio = Date.now();
    var raiz = document.createElement('div'); raiz.className = 'cot';
    var boton = document.createElement('button'); boton.type = 'button'; boton.className = 'cot-cabecera';
    boton.setAttribute('aria-expanded', 'true');
    var bolita = crearBolita();
    var etiqueta = document.createElement('span'); etiqueta.className = 'cot-label';
    etiqueta.innerHTML = '<span class="trShimmer">Pensando…</span>';
    var chevron = document.createElement('span'); chevron.className = 'cot-chevron'; chevron.innerHTML = ICONOS.chevron;
    boton.appendChild(bolita.el); boton.appendChild(etiqueta); boton.appendChild(chevron);

    var cuerpo = document.createElement('div'); cuerpo.className = 'cot-cuerpo';
    var pasos = document.createElement('div'); pasos.className = 'cot-pasos';
    cuerpo.appendChild(pasos);
    raiz.appendChild(boton); raiz.appendChild(cuerpo);
    contenedor.appendChild(raiz);

    boton.addEventListener('click', function () {
      var abierto = cuerpo.classList.toggle('cerrado') === false;
      boton.setAttribute('aria-expanded', String(abierto));
    });

    var texto = '';           // razonamiento acumulado
    var nodos = [];           // un nodo por párrafo
    var terminado = false;

    function separar(parrafo) {
      // Los resúmenes suelen empezar con un título en negritas: **Revisando el expediente**
      var t = parrafo.trim();
      var m = t.match(/^\*\*(.+?)\*\*\s*([\s\S]*)$/);
      return m ? { titulo: m[1].trim(), detalle: m[2].trim() } : { titulo: t, detalle: '' };
    }

    function nodoPaso(i) {
      if (nodos[i]) return nodos[i];
      var paso = document.createElement('div'); paso.className = 'cot-paso';
      var hito = document.createElement('div'); hito.className = 'cot-hito';
      var icono = document.createElement('div'); icono.className = 'cot-icono';
      hito.appendChild(icono);
      var c = document.createElement('div'); c.className = 'cot-cuerpo-paso';
      var et = document.createElement('div'); et.className = 'cot-etiqueta';
      var de = document.createElement('div'); de.className = 'cot-detalle';
      c.appendChild(et); c.appendChild(de);
      paso.appendChild(hito); paso.appendChild(c);
      pasos.appendChild(paso);

      nodos[i] = { paso: paso, hito: hito, icono: icono, et: et, de: de };
      return nodos[i];
    }

    // Agrupa el texto en pasos: un título en negritas abre un paso y los párrafos siguientes son su detalle
    function agrupar() {
      var grupos = [];
      texto.split(/\n\s*\n/).forEach(function (p) {
        var t = p.trim();
        if (!t) return;
        var soloTitulo = /^\*\*[^*]+\*\*$/.test(t);
        var ultimo = grupos[grupos.length - 1];
        if (!soloTitulo && ultimo && ultimo.abierto) {
          ultimo.detalle += (ultimo.detalle ? '\n\n' : '') + t;
        } else {
          var partes = separar(t);
          grupos.push({ titulo: partes.titulo, detalle: partes.detalle, abierto: soloTitulo });
        }
      });
      return grupos;
    }

    function pintar() {
      var grupos = agrupar();
      grupos.forEach(function (partes, i) {
        var n = nodoPaso(i);
        var activo = !terminado && i === grupos.length - 1;
        n.et.textContent = partes.titulo;
        n.de.textContent = partes.detalle;
        n.de.hidden = !partes.detalle;
        n.paso.classList.toggle('activo', activo);
        n.paso.classList.toggle('completo', !activo);
        n.et.classList.toggle('trActiveSentence', activo);
        var tema = iconoDe(partes.titulo);
        if (n.tema !== tema) { n.icono.innerHTML = ICONOS[tema]; n.tema = tema; }
      });
      if (contenedor.closest('[data-asistente-conversacion]')) {
        var scroll = contenedor.closest('[data-asistente-conversacion]');
        if (scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 120) scroll.scrollTop = scroll.scrollHeight;
      }
    }

    return {
      agregar: function (fragmento) {
        if (terminado) return;
        texto += fragmento;
        pintar();
      },
      terminar: function () {
        if (terminado) return;
        terminado = true;
        pintar();
        var segundos = Math.max(1, Math.round((Date.now() - inicio) / 1000));
        etiqueta.innerHTML = '<span>Razonó durante ' + segundos + ' s</span>';
        bolita.apagar();
        cuerpo.classList.add('cerrado');         // el razonamiento queda oculto; se abre con un clic
        boton.setAttribute('aria-expanded', 'false');
        if (!texto.trim()) raiz.remove();        // el proveedor no envió razonamiento
      },
      tieneTexto: function () { return !!texto.trim(); }
    };
  }

  window.Razonamiento = { crear: crear, icono: function (tema) { return ICONOS[tema] || ICONOS.idea; } };
})();
