<?php
/**
 * Portal del paciente: sus citas (próximas y anteriores), en solo lectura.
 * No muestra las notas internas que el personal escribe en cada cita.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/portal_paciente.php';

if (!esPaciente()) {
    redirigir('/agenda/');
}
$p = expedienteDelPortal();
$proximas = $anteriores = [];
if ($p) {
    registrarBitacora('pacientes', (int) $p['id'], 'acceso', 'portal_citas');
    $proximas   = citasDelPaciente((int) $p['id']);
    $anteriores = citasDelPaciente((int) $p['id'], true, 30);
}

/** Horario de la cita: "Hoy 10:00 – 10:30". */
$horario = fn (array $c): string => cuandoCita($c['inicio']) . ' – ' . substr($c['fin'], 11, 5);

$tituloPagina   = 'Mis citas';
$seccionPortal  = 'citas';
$migas          = [['texto' => 'Mi expediente', 'url' => '/portal/'], ['texto' => 'Mis citas']];
$mostrarBanner  = false;
require __DIR__ . '/../app/vistas/plantilla/portal_encabezado.php';
?>

<h1 class="hoja-paciente__nombre portal__titulo">Mis citas</h1>

<?php if (!$p): ?>
  <p class="ficha__vacio">Tu cuenta todavía no está ligada a un expediente. Pide en recepción o a tu médico que te den acceso al portal.</p>
<?php else: ?>

<section class="ficha__seccion">
  <div class="ficha__titulo-fila">
    <h2 class="ficha__titulo">Próximas</h2>
    <span class="ficha__fuente">Para cambiar o cancelar una cita, llama al establecimiento</span>
  </div>
  <?php if ($proximas): ?>
    <ul class="hoja-paciente__citas portal-citas">
      <?php foreach ($proximas as $c): ?>
        <li id="cita-<?= (int) $c['id'] ?>"><div>
          <span class="hoja-paciente__cita-fecha"><?= e($horario($c)) ?></span>
          <span class="hoja-paciente__cita-texto"><?= e($c['motivo'] ?: 'Cita') ?><small><?= e($c['profesional']) ?> · <?= e(ESTADOS_CITA[$c['estado']] ?? $c['estado']) ?></small></span>
        </div></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin citas programadas.</p>
  <?php endif; ?>
</section>

<section class="ficha__seccion">
  <h2 class="ficha__titulo">Anteriores</h2>
  <?php if ($anteriores): ?>
    <ul class="hoja-paciente__citas portal-citas portal-citas--pasadas">
      <?php foreach ($anteriores as $c): ?>
        <li id="cita-<?= (int) $c['id'] ?>"><div>
          <span class="hoja-paciente__cita-fecha"><?= e(fechaCorta($c['inicio'])) ?> <?= e(substr($c['inicio'], 11, 5)) ?></span>
          <span class="hoja-paciente__cita-texto"><?= e($c['motivo'] ?: 'Cita') ?><small><?= e($c['profesional']) ?> · <?= e(ESTADOS_CITA[$c['estado']] ?? $c['estado']) ?></small></span>
        </div></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin citas anteriores.</p>
  <?php endif; ?>
</section>

<!-- Recordatorios de sus citas en el teléfono o la computadora (assets/js/modulos/push.js; permiso solo al activarlo) -->
<section class="ficha__seccion">
  <h2 class="ficha__titulo">Recordatorios</h2>
  <div data-push-ajustes-pagina></div>
</section>

<?php endif; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/portal_pie.php'; ?>
