/* Registros clínicos (pacientes/nuevo_registro.php): renglones de medicamentos y diagnósticos,
   campos que dependen de otro, IMC, validación antes de enviar y aviso al salir sin guardar.
   La validación definitiva la hace pacientes/historial.php. */
(() => {
  const form = document.querySelector('[data-registro-clinico]');
  if (!form) return;

  const mejorar = (raiz) => {
    if (typeof mejorarSelect === 'function') raiz.querySelectorAll('select').forEach(mejorarSelect);
  };
  const error = (campo, mensaje) => {
    if (typeof marcarError === 'function') { marcarError(campo, mensaje); return; }
    campo.closest('.campo')?.classList.toggle('campo--error', Boolean(mensaje));
  };
  const vacio = (el) => (el.type === 'checkbox' ? !el.checked : !String(el.value).trim());

  /* ---------- Renglones repetibles ---------- */
  const numerar = (caja) => caja.querySelectorAll(':scope > [data-fila]').forEach((fila, i) => {
    const n = fila.querySelector('[data-fila-numero]');
    if (n) n.textContent = String(i + 1);
  });

  form.addEventListener('click', (ev) => {
    const agregar = ev.target.closest('[data-fila-agregar]');
    if (agregar) {
      const nombre = agregar.dataset.filaAgregar;
      const caja = form.querySelector(`[data-filas="${nombre}"]`);
      const plantilla = form.querySelector(`template[data-plantilla="${nombre}"]`);
      const fila = plantilla.content.firstElementChild.cloneNode(true);
      caja.appendChild(fila);
      mejorar(fila);
      numerar(caja);
      fila.querySelector('input')?.focus();
      return;
    }
    const quitar = ev.target.closest('[data-fila-quitar]');
    if (quitar) {
      const fila = quitar.closest('[data-fila]');
      const caja = fila.parentElement;
      if (caja.querySelectorAll(':scope > [data-fila]').length > 1) {
        const siguiente = fila.nextElementSibling || fila.previousElementSibling;
        fila.remove();
        numerar(caja);
        siguiente?.querySelector('input')?.focus();
      }
    }
  });

  /* ---------- Campos que dependen de otro: data-mostrar-si="campo:valor" o "campo:!valor" ---------- */
  const dependientes = [...form.querySelectorAll('[data-mostrar-si]')];
  const aplicarDependientes = () => dependientes.forEach((caja) => {
    const [nombre, condicion] = caja.dataset.mostrarSi.split(':');
    const actual = form.elements[nombre]?.value || '';
    const negado = condicion.startsWith('!');
    const valor = negado ? condicion.slice(1) : condicion;
    const visible = negado ? actual !== '' && actual !== valor : actual === valor;
    caja.hidden = !visible;
    caja.querySelectorAll('input, textarea, select').forEach((el) => { el.disabled = !visible; });
  });
  aplicarDependientes();
  form.addEventListener('change', (ev) => { if (ev.target.matches('select')) aplicarDependientes(); });

  // Consentimiento: si lo otorga el paciente, su nombre; si es otra persona, se escribe el suyo
  const otorga = form.elements.otorga;
  const otorgante = form.elements.otorgante;
  if (otorga && otorgante) {
    const nombrePaciente = form.dataset.pacienteNombre || '';
    otorga.addEventListener('change', () => {
      if (otorga.value === 'Paciente' && !otorgante.value.trim()) otorgante.value = nombrePaciente;
      else if (otorga.value !== 'Paciente' && otorgante.value === nombrePaciente) otorgante.value = '';
    });
  }

  /* ---------- IMC a partir de peso y talla ---------- */
  const imc = form.querySelector('[data-imc]');
  const numero = (el) => parseFloat(String(el?.value || '').replace(',', '.'));
  if (imc) {
    const peso = form.querySelector('[data-signo="peso"]');
    const talla = form.querySelector('[data-signo="talla"]');
    const calcular = () => {
      const p = numero(peso);
      const t = numero(talla) / 100;
      imc.value = p > 0 && t > 0.3 ? (p / (t * t)).toFixed(1) : '';
    };
    peso.addEventListener('input', calcular);
    talla.addEventListener('input', calcular);
  }

  /* ---------- Formato mientras se escribe ---------- */
  form.addEventListener('input', (ev) => {
    const el = ev.target;
    if (el.matches('[data-cie]')) el.value = el.value.toUpperCase().replace(/[^A-Z0-9.]/g, '');
    if (el.matches('[data-solo-digitos]')) el.value = el.value.replace(/\D/g, '');
    if (el.matches('[data-signo]')) el.value = el.value.replace(/[^0-9.,]/g, '');
    if (el.value) error(el, '');
  });
  form.addEventListener('change', (ev) => {
    if (ev.target.matches('select, input[type="date"], input[type="time"]')) error(ev.target, '');
    if (ev.target.matches('input[type="checkbox"]')) {
      ev.target.closest('.casilla')?.classList.remove('casilla--error');
    }
  });

  /* ---------- Validación antes de enviar (la del servidor es la definitiva) ---------- */
  let enviando = false;
  form.addEventListener('submit', (ev) => {
    if (enviando) { ev.preventDefault(); return; }
    let primero = null;
    const marcar = (el, mensaje) => { error(el, mensaje); primero = primero || el; };

    form.querySelectorAll('[required]:not([disabled])').forEach((el) => {
      if (!vacio(el)) return;
      if (el.type === 'checkbox') {
        el.closest('.casilla')?.classList.add('casilla--error');
        primero = primero || el;
      } else {
        marcar(el, 'Campo obligatorio');
      }
    });

    // Renglones: solo se validan los que tienen algo escrito; las listas obligatorias necesitan uno
    form.querySelectorAll('[data-filas]').forEach((caja) => {
      const filas = [...caja.querySelectorAll(':scope > [data-fila]')];
      const conDatos = filas.filter((f) => [...f.querySelectorAll('input:not([readonly])')].some((i) => i.value.trim()));
      conDatos.forEach((f) => f.querySelectorAll('[data-requerido-fila]').forEach((el) => {
        if (vacio(el)) marcar(el, 'Campo obligatorio');
      }));
      if (!conDatos.length && caja.hasAttribute('data-filas-requeridas')) {
        const el = filas[0]?.querySelector('[data-requerido-fila]');
        if (el) marcar(el, 'Agrega al menos un renglón');
      }
    });

    form.querySelectorAll('[data-cie]').forEach((el) => {
      if (el.value && !/^[A-Z]\d{2}(\.?[0-9A-Z]{1,4})?$/.test(el.value)) marcar(el, 'Código CIE-10 no válido');
    });
    form.querySelectorAll('[data-signo]').forEach((el) => {
      if (el.value && Number.isNaN(numero(el))) marcar(el, 'Escribe un número');
    });
    const cedula = form.querySelector('[data-solo-digitos]');
    if (cedula && cedula.value && !/^\d{6,10}$/.test(cedula.value)) marcar(cedula, 'De 6 a 10 dígitos');

    if (primero) {
      ev.preventDefault();
      const visible = primero.classList.contains('select-oculto') ? primero.nextElementSibling : primero;
      visible?.scrollIntoView({ block: 'center', behavior: 'smooth' });
      visible?.focus({ preventScroll: true });
      if (typeof mostrarNotificacion === 'function') mostrarNotificacion('error', 'Revisa los campos marcados en rojo.');
      return;
    }
    enviando = true;
    sinGuardar = false;
    form.querySelector('[type="submit"]')?.setAttribute('disabled', '');
  });

  /* ---------- Aviso al salir con cambios sin guardar ---------- */
  let sinGuardar = false;
  form.addEventListener('input', (ev) => { if (ev.isTrusted) sinGuardar = true; });
  window.addEventListener('beforeunload', (ev) => {
    if (!sinGuardar) return;
    ev.preventDefault();
    ev.returnValue = '';
  });

  // Los errores que devolvió el servidor: el primero queda a la vista (tras crear los selectores de la app)
  document.addEventListener('DOMContentLoaded', () => {
    const conError = form.querySelector('.campo--error input, .campo--error textarea, .campo--error select, .casilla--error input');
    if (!conError) return;
    const visible = conError.classList.contains('select-oculto') ? conError.nextElementSibling : conError;
    visible?.scrollIntoView({ block: 'center' });
  });
})();
