<?php
/**
 * Visor de imágenes médicas de un estudio: series a la izquierda, imagen al centro con sus herramientas
 * (ventana/nivel, mover, zoom, medir, invertir, rotar) y los datos del estudio. Dibuja DICOM sin compresión
 * y JPEG base directamente en el navegador (assets/js/visor_dicom.js); fotos e imágenes comunes también.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/imagenes.php';

$pacienteId = (int) ($_GET['paciente'] ?? 0);
$uid = (string) ($_GET['estudio'] ?? '');
if (!puedeVerExpediente($pacienteId)) {
    mensajeFlash('error', 'No tienes acceso a este expediente.');
    redirigir(rutaInicio());
}
$c = db()->prepare('SELECT id, folio, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, sexo FROM pacientes WHERE id = ? AND institucion_id <=> ? AND eliminado_en IS NULL');
$c->execute([$pacienteId, institucionId()]);
$p = $c->fetch();
$estudios = $p ? estudiosDelPaciente($pacienteId) : [];
$estudio = null;
foreach ($estudios as $e) {
    if ($e['uid'] === $uid) {
        $estudio = $e;
    }
}
if (!$p || !$estudio) {
    mensajeFlash('error', 'El estudio no existe.');
    redirigir(esPaciente() ? '/portal/' : '/pacientes/ver.php?id=' . $pacienteId);
}
$nombre = trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}");
$edad = $p['fecha_nacimiento'] ? (new DateTime($p['fecha_nacimiento']))->diff(new DateTime('today'))->y : null;

$tituloPagina   = ($estudio['descripcion'] ?: (MODALIDADES[$estudio['modalidad']] ?? 'Estudio')) . ' · ' . $nombre;
$seccionActiva  = 'pacientes';
$clavePresencia = 'paciente:' . $pacienteId;
$pacienteActual = $pacienteId;
$migas          = [['texto' => 'Pacientes', 'url' => '/pacientes/'], ['texto' => $nombre, 'url' => '/pacientes/ver.php?id=' . $pacienteId], ['texto' => MODALIDADES[$estudio['modalidad']] ?? 'Estudio']];
if (esPaciente()) {
    $migas = [['texto' => 'Mi expediente', 'url' => '/portal/'], ['texto' => MODALIDADES[$estudio['modalidad']] ?? 'Estudio']];
}
$mostrarBanner  = false;
$claseContenido = 'pagina--visor';
$guionesPagina  = ['js/visor_dicom.js'];
$seccionPortal = 'expediente';
require __DIR__ . '/../app/vistas/plantilla/' . (esPaciente() ? 'portal_encabezado.php' : 'encabezado.php');
?>

<script type="application/json" id="visor-datos"><?= json_encode([
    'base' => URL_BASE . '/api/pacientes/imagenes.php?archivo=',
    'estudio' => $estudio,
    'paciente' => ['nombre' => $nombre, 'folio' => $p['folio'], 'edad' => $edad, 'sexo' => SEXOS[$p['sexo']] ?? ''],
    'modalidad' => MODALIDADES[$estudio['modalidad']] ?? $estudio['modalidad'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<div class="visor" data-visor>
  <aside class="visor__series" aria-label="Series del estudio">
    <div class="visor__estudio">
      <strong><?= e($estudio['descripcion'] ?: (MODALIDADES[$estudio['modalidad']] ?? 'Estudio')) ?></strong>
      <span><?= e(MODALIDADES[$estudio['modalidad']] ?? $estudio['modalidad']) ?> · <?= e(fechaCorta($estudio['fecha'])) ?></span>
    </div>
    <div class="visor__lista-series" data-visor-series></div>
  </aside>

  <section class="visor__centro">
    <div class="visor__herramientas" role="toolbar" aria-label="Herramientas">
      <button type="button" class="visor__herramienta activa" data-herramienta="ventana" title="Ventana y nivel: arrastra (también con el botón derecho)"><?= icono('ventana') ?><span>Ventana</span></button>
      <button type="button" class="visor__herramienta" data-herramienta="mover" title="Mover: arrastra (también con la rueda presionada)"><?= icono('mano') ?><span>Mover</span></button>
      <button type="button" class="visor__herramienta" data-herramienta="zoom" title="Zoom: arrastra arriba o abajo (también Ctrl + rueda)"><?= icono('buscar') ?><span>Zoom</span></button>
      <button type="button" class="visor__herramienta" data-herramienta="medir" title="Medir distancia en milímetros"><?= icono('regla') ?><span>Medir</span></button>
      <span class="visor__separador"></span>
      <button type="button" class="visor__herramienta" data-visor-preajuste title="Ventanas predefinidas para tomografía"><?= icono('flecha_abajo') ?><span data-visor-preajuste-texto>Predeterminada</span></button>
      <button type="button" class="visor__herramienta" data-visor-accion="invertir" title="Invertir (I)"><?= icono('contraste') ?><span>Invertir</span></button>
      <button type="button" class="visor__herramienta" data-visor-accion="rotar" title="Rotar 90°"><?= icono('reabrir') ?><span>Rotar</span></button>
      <button type="button" class="visor__herramienta" data-visor-accion="restablecer" title="Restablecer (R)"><?= icono('cerrar') ?><span>Restablecer</span></button>
      <span class="visor__espacio"></span>
      <button type="button" class="visor__herramienta" data-visor-accion="pantalla" title="Pantalla completa (F)"><?= icono('expandir') ?><span>Pantalla completa</span></button>
    </div>
    <div class="visor__lienzo" data-visor-lienzo tabindex="0" aria-label="Imagen del estudio. Rueda o flechas para cambiar de corte.">
      <canvas data-visor-canvas></canvas>
      <div class="visor__texto visor__texto--sup-izq" data-visor-info-izq></div>
      <div class="visor__texto visor__texto--sup-der" data-visor-info-der></div>
      <div class="visor__texto visor__texto--inf-izq" data-visor-corte></div>
      <div class="visor__texto visor__texto--inf-der" data-visor-ventana></div>
      <div class="visor__aviso" data-visor-aviso hidden></div>
      <input type="range" class="visor__deslizador" data-visor-deslizador min="1" max="1" value="1" aria-label="Corte">
    </div>
  </section>
</div>

<?php if (esPaciente()): foreach ($guionesPagina as $guion): ?><script src="<?= recurso($guion) ?>"></script><?php endforeach; endif; ?>
<?php require __DIR__ . '/../app/vistas/plantilla/' . (esPaciente() ? 'portal_pie.php' : 'pie.php'); ?>
