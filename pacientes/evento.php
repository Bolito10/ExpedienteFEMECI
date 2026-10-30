<?php
/**
 * Vista completa de una entrada del historial (cualquier tipo de la NOM-004), con formato de documento
 * listo para imprimir: establecimiento, paciente, contenido (app/vistas/componentes/historial_detalle.php) y firma.
 * La receta lleva el bloque de quien prescribe y el consentimiento informado las firmas de paciente,
 * médico y dos testigos (NOM-004 5.10, 6.2.6 y 10.1).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/registros_clinicos.php';
// Modo paciente (portal): solo las entradas de su propio expediente, sin accesos al resto del sistema
$modoPaciente = esPaciente();
if (!$modoPaciente) {
    exigirPermiso('ver_expediente');
}

$c = db()->prepare('SELECT h.*, p.nombre, p.primer_apellido, p.segundo_apellido, p.folio, p.fecha_nacimiento, p.sexo, p.curp, p.alergias, p.foto
                      FROM historial_clinico h JOIN pacientes p ON p.id = h.paciente_id WHERE h.id = ? AND p.institucion_id = ? AND p.eliminado_en IS NULL'
                   . ($modoPaciente ? ' AND h.paciente_id = ?' : ''));
$c->execute($modoPaciente ? [(int) ($_GET['id'] ?? 0), institucionId(), (int) pacienteDeSesion()] : [(int) ($_GET['id'] ?? 0), institucionId()]);
$h = $c->fetch();
if (!$h) {
    if ($modoPaciente) {
        redirigir('/portal/');
    }
    mensajeFlash('error', 'La entrada no existe.');
    redirigir('/pacientes/');
}
registrarBitacora('pacientes', (int) $h['paciente_id'], 'acceso', $modoPaciente ? 'portal_historial' : 'consulta_historial', null, (string) $h['id']);

$d         = json_decode($h['detalle'], true) ?: [];
$tipo      = $h['tipo'];
$documento = nombreTipoRegistro($tipo, 'documento');
$paciente  = trim("{$h['nombre']} {$h['primer_apellido']} {$h['segundo_apellido']}");
$prescriptor = $tipo === 'receta' ? ($d['prescriptor'] ?? []) : [];
$establecimiento = $prescriptor['establecimiento'] ?? (config('nombre_establecimiento') ?: 'Expedientes Clínicos');
$cedula    = ($prescriptor['cedula'] ?? '') ?: $h['autor_cedula'];
$hora      = substr($h['fecha'], 11, 5);

$tituloPagina   = $documento . ' · ' . $paciente;
$seccionActiva  = 'pacientes';
$pacienteActual = (int) $h['paciente_id'];
$migas = [['texto' => 'Pacientes', 'url' => '/pacientes/'], ['texto' => $paciente, 'url' => '/pacientes/ver.php?id=' . $h['paciente_id']], ['texto' => $documento]];
$notificacion = tomarFlash();
if ($modoPaciente) {
    $migas = [['texto' => 'Mi expediente', 'url' => '/portal/'], ['texto' => $documento]];
    $mostrarBanner = false;
    $claseContenido = 'portal__documento';
    require __DIR__ . '/../app/vistas/plantilla/portal_encabezado.php';
} else {
    require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
}
?>

<article class="documento documento--<?= e($tipo) ?>">
  <header class="documento__cabecera">
    <div>
      <p class="documento__establecimiento"><?= e($establecimiento) ?></p>
      <?php if (!empty($prescriptor['domicilio'])): ?><p class="documento__domicilio"><?= e($prescriptor['domicilio']) ?></p><?php endif; ?>
      <h1 class="documento__titulo"><?= e($documento) ?></h1>
    </div>
    <p class="documento__fecha"><?= e(fechaCorta($h['fecha'])) ?><?php if ($hora !== '00:00'): ?><small><?= e($hora) ?> h</small><?php endif; ?></p>
  </header>

  <dl class="documento__paciente">
    <div><dt>Paciente</dt><dd><?= e($paciente) ?></dd></div>
    <div><dt>Expediente</dt><dd><?= e($h['folio']) ?></dd></div>
    <div><dt>Edad</dt><dd><?= edad($h['fecha_nacimiento']) ?> años · <?= e(SEXOS[$h['sexo']] ?? '') ?></dd></div>
    <?php if ($h['curp']): ?><div><dt>CURP</dt><dd><?= e($h['curp']) ?></dd></div><?php endif; ?>
    <div class="documento__alergias"><dt>Alergias</dt><dd><?= e($h['alergias'] ?: 'No registradas') ?></dd></div>
  </dl>

  <div class="documento__contenido">
    <?php $registro = $h; $modoDetalle = 'documento'; require __DIR__ . '/../app/vistas/componentes/historial_detalle.php'; ?>
  </div>

  <?php if ($tipo === 'consentimiento'): ?>
    <!-- NOM-004 10.1.1: nombre y firma de quien otorga, del médico y de dos testigos -->
    <footer class="documento__firmas">
      <div class="documento__firma-celda">
        <span class="documento__linea" aria-hidden="true"></span>
        <b><?= e($d['otorgante'] ?? $paciente) ?></b>
        <span><?= e(($d['otorga'] ?? 'Paciente') . (!empty($d['parentesco']) ? ' · ' . $d['parentesco'] : '')) ?></span>
      </div>
      <div class="documento__firma-celda">
        <span class="documento__linea" aria-hidden="true"></span>
        <b><?= e($h['autor_nombre']) ?></b>
        <span><?= $cedula ? 'Médico · cédula profesional ' . e($cedula) : 'Médico' ?></span>
      </div>
      <?php foreach (array_values($d['testigos'] ?? []) as $i => $testigo): ?>
        <div class="documento__firma-celda">
          <span class="documento__linea" aria-hidden="true"></span>
          <b><?= e($testigo) ?></b>
          <span>Testigo <?= $i + 1 ?></span>
        </div>
      <?php endforeach; ?>
      <small class="documento__registro">Registrado el <?= e(fechaCorta($h['creado_en'])) ?> · entrada inmutable (NOM-004 5.10)</small>
    </footer>

  <?php elseif ($tipo === 'receta'): ?>
    <!-- Quien prescribe: nombre, cédula profesional, institución que expidió el título y establecimiento -->
    <footer class="documento__prescriptor">
      <dl class="documento__prescriptor-datos">
        <div><dt>Médico</dt><dd><?= e($prescriptor['nombre'] ?? $h['autor_nombre']) ?></dd></div>
        <div><dt>Cédula profesional</dt><dd><?= $cedula ? e($cedula) : 'Sin cédula registrada' ?></dd></div>
        <?php if (!empty($prescriptor['institucion'])): ?><div><dt>Institución que expidió el título</dt><dd><?= e($prescriptor['institucion']) ?></dd></div><?php endif; ?>
        <div><dt>Fecha de expedición</dt><dd><?= e(fechaCorta($h['fecha'])) ?></dd></div>
      </dl>
      <div class="documento__firma">
        <span class="documento__linea" aria-hidden="true"></span>
        <span>Firma de quien prescribe</span>
        <small>Registrado el <?= e(fechaCorta($h['creado_en'])) ?> · entrada inmutable (NOM-004 5.10)</small>
      </div>
    </footer>

  <?php else: ?>
    <footer class="documento__firma">
      <span class="documento__linea" aria-hidden="true"></span>
      <b><?= e($h['autor_nombre']) ?></b>
      <span><?= $cedula ? 'Cédula profesional ' . e($cedula) : 'Sin cédula registrada' ?></span>
      <small>Registrado el <?= e(fechaCorta($h['creado_en'])) ?> · entrada inmutable (NOM-004 5.10)</small>
    </footer>
  <?php endif; ?>
</article>

<div class="documento__acciones">
  <a class="boton boton--fantasma" href="<?= URL_BASE ?><?= $modoPaciente ? '/portal/#historial' : '/pacientes/ver.php?id=' . (int) $h['paciente_id'] . '#historial' ?>"><?= icono('atras') ?> Volver al expediente</a>
  <?php if (puede('historial')): ?>
    <a class="boton boton--fantasma" href="<?= URL_BASE ?>/pacientes/nuevo_registro.php?paciente=<?= (int) $h['paciente_id'] ?>"><?= icono('agregar') ?> Nuevo registro</a>
  <?php endif; ?>
  <button type="button" class="boton boton--secundario" data-imprimir><?= icono('documento') ?> Imprimir</button>
</div>

<?php require __DIR__ . '/../app/vistas/plantilla/' . ($modoPaciente ? 'portal_pie.php' : 'pie.php'); ?>
