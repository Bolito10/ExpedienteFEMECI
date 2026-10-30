<?php
/**
 * Agenda de citas: vistas Día, Semana, Mes y Lista; mini calendario y filtro de profesionales a la
 * izquierda; crear con clic en un horario, arrastrar para mover, estirar para cambiar la duración,
 * búsqueda por paciente y panel lateral con el detalle y el formulario de cada cita.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/agenda.php';
exigirPermiso('agenda');

$profesionales = db()->prepare("SELECT id, nombre, foto, rol FROM usuarios WHERE institucion_id = ? AND activo = 1 AND rol <> 'paciente' ORDER BY FIELD(rol, 'medico', 'enfermeria', 'administrador', 'recepcion'), nombre");
$profesionales->execute([institucionId()]);
$profesionales = $profesionales->fetchAll();
$fechaInicial = DateTime::createFromFormat('!Y-m-d', (string) ($_GET['fecha'] ?? '')) ?: new DateTime('today');

$tituloPagina   = textoEditable('agenda_titulo', 'Agenda');
$seccionActiva  = 'agenda';
$clavePresencia = 'agenda:calendario';
$migas          = [['texto' => $tituloPagina]];
$claseContenido = 'pagina--ancha pagina--agenda';
$notificacion   = tomarFlash();
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

// Iconos que agenda.js necesita y que no vienen en el juego común de #iconos-js
$iconosAgenda = ['usuario', 'atras', 'adelante', 'correo', 'panel', 'alerta', 'casilla', 'documento', 'enlace', 'reabrir'];
?>

<div class="pagina__cabecera">
  <h1 class="pagina__titulo"<?= atributosEditable('agenda_titulo', 'Agenda') ?>><?= e($tituloPagina) ?></h1>
</div>

<script type="application/json" id="agenda-datos"><?= json_encode([
    'fecha'         => $fechaInicial->format('Y-m-d'),
    'cita'          => isset($_GET['cita']) ? (int) $_GET['cita'] : null,
    'nueva'         => isset($_GET['nueva']),
    'paciente'      => isset($_GET['paciente']) ? (int) $_GET['paciente'] : null,
    'yo'            => (int) usuarioActual()['id'],
    'profesionales' => array_map(fn ($p) => ['id' => (int) $p['id'], 'nombre' => $p['nombre'], 'rol' => ROLES[$p['rol']]['nombre'],
                                             'foto' => $p['foto'] ? URL_BASE . '/' . $p['foto'] : null], $profesionales),
    'estados'       => ESTADOS_CITA,
    'recordatorios' => RECORDATORIOS_CITA,
    'duracionMaxima'=> DURACION_MAXIMA_CITA,
    'iconos'        => array_combine($iconosAgenda, array_map('icono', $iconosAgenda)),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<section class="calendario" data-agenda>
  <!-- Columna izquierda: mini calendario, profesionales y opciones de vista -->
  <aside class="calendario__rail" data-agenda-rail aria-label="Navegación de la agenda">
    <div class="cal-mini" data-agenda-mini></div>

    <div class="calendario__seccion">
      <div class="calendario__seccion-titulo">
        <span>Profesionales</span>
        <button type="button" class="calendario__seccion-accion" data-profesionales-todos>Todos</button>
      </div>
      <div class="cal-profesionales" data-agenda-profesionales role="group" aria-label="Profesionales visibles"></div>
    </div>

    <div class="calendario__seccion">
      <div class="calendario__seccion-titulo"><span>Mostrar</span></div>
      <button type="button" class="cal-opcion" data-ver-canceladas role="switch" aria-checked="false">
        <span>Citas canceladas</span><i class="interruptor" aria-hidden="true"></i>
      </button>
    </div>

    <button type="button" class="calendario__atajos" data-agenda-atajos aria-haspopup="dialog">Atajos de teclado <kbd>?</kbd></button>
  </aside>

  <div class="calendario__principal">
    <div class="calendario__barra">
      <div class="calendario__navegar">
        <button type="button" class="boton-icono" data-agenda-rail-alternar title="Mostrar u ocultar el panel" aria-label="Mostrar u ocultar el panel" aria-pressed="true"><?= icono('panel') ?></button>
        <button type="button" class="calendario__hoy" data-agenda-hoy title="Ir a hoy (T)">Hoy</button>
        <button type="button" class="boton-icono" data-agenda-anterior title="Anterior (←)" aria-label="Periodo anterior"><?= icono('atras') ?></button>
        <button type="button" class="boton-icono" data-agenda-siguiente title="Siguiente (→)" aria-label="Periodo siguiente"><?= icono('adelante') ?></button>
        <h2 class="calendario__periodo" data-agenda-periodo aria-live="polite"></h2>
      </div>
      <div class="calendario__herramientas">
        <label class="cal-buscar">
          <?= icono('buscar') ?>
          <input type="text" data-agenda-buscar placeholder="Buscar paciente" autocomplete="off" spellcheck="false" aria-label="Buscar citas por paciente, folio o motivo">
          <button type="button" class="cal-buscar__limpiar" data-agenda-buscar-limpiar hidden title="Limpiar búsqueda" aria-label="Limpiar búsqueda"><?= icono('cerrar') ?></button>
        </label>
        <div class="segmentado calendario__vistas" role="group" aria-label="Vista">
          <button type="button" data-vista="dia" aria-pressed="false" title="Día (D)">Día</button>
          <button type="button" data-vista="semana" aria-pressed="true" title="Semana (W)">Semana</button>
          <button type="button" data-vista="mes" aria-pressed="false" title="Mes (M)">Mes</button>
          <button type="button" data-vista="lista" aria-pressed="false" title="Lista (L)">Lista</button>
        </div>
        <button type="button" class="boton boton--primario calendario__nueva" data-agenda-nueva title="Nueva cita (N)"><?= icono('agregar') ?> Nueva cita</button>
      </div>
    </div>
    <div class="calendario__cuerpo" data-agenda-cuerpo></div>
  </div>
</section>

<?php require_once __DIR__ . '/../app/vistas/componentes/notas_seccion.php'; notasDeSeccion('agenda'); ?>

<!-- Panel lateral: detalle, nueva cita o edición -->
<aside class="cal-cajon" data-agenda-cajon hidden aria-label="Cita"></aside>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
