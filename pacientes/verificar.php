<?php
/**
 * Verificar un documento impreso: se escribe la huella que aparece al pie de una nota o receta
 * ("Verificación 974a33a0fd9233b1") y se comprueba si corresponde exactamente a lo guardado.
 * Solo muestra datos de identificación (tipo, fecha, autor, expediente), nunca el contenido clínico.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/registros.php';
exigirPermiso('ver_expediente');

$huella = trim((string) ($_GET['huella'] ?? ''));
$resultado = null;
if ($huella !== '') {
    if (!dentroDelLimite('verificar_huella:usuario:' . (int) usuarioActual()['id'], 60, 600)) {
        $resultado = ['error' => 'Demasiadas verificaciones seguidas. Espera un momento.'];
    } else {
        $registro = verificarHuella($huella);
        $resultado = $registro ? ['registro' => $registro] : ['no' => true];
        registrarBitacora('historial_clinico', (int) ($registro['id'] ?? 0), 'acceso', 'verificacion_huella', null, $registro ? 'coincide' : 'no coincide');
    }
}

$tituloPagina   = 'Verificar documento';
$seccionActiva  = 'pacientes';
$clavePresencia = 'pacientes:verificar';
$migas          = [['texto' => 'Pacientes', 'url' => '/pacientes/'], ['texto' => 'Verificar documento']];
$mostrarBanner  = false;
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina__cabecera">
  <h1 class="pagina__titulo">Verificar documento</h1>
  <p class="pagina__subtitulo">Comprueba que una nota o receta impresa corresponde exactamente a lo guardado en el expediente</p>
</div>

<form method="get" class="seccion verificar" novalidate>
  <h2 class="seccion__titulo"><?= iconoRelleno('escudo') ?> Huella de verificación</h2>
  <div class="seccion__cuerpo rejilla">
    <div class="campo col-8">
      <label class="campo__etiqueta" for="huella">Escribe los 16 caracteres que aparecen al pie del documento, después de «Verificación»</label>
      <div class="campo__control"><input id="huella" name="huella" value="<?= e($huella) ?>" maxlength="24" autocomplete="off" spellcheck="false" autofocus inputmode="latin"></div>
    </div>
    <div class="col-4 verificar__accion"><button type="submit" class="boton boton--secundario">Verificar</button></div>
  </div>
</form>

<?php if ($resultado): ?>
  <section class="seccion verificar__resultado verificar__resultado--<?= isset($resultado['registro']) ? 'si' : 'no' ?>" aria-live="polite">
    <?php if (isset($resultado['registro'])): $r = $resultado['registro']; ?>
      <h2 class="seccion__titulo"><?= iconoRelleno('escudo') ?> El documento coincide</h2>
      <div class="seccion__cuerpo">
        <p>Lo impreso corresponde exactamente a lo guardado: no fue modificado.</p>
        <dl class="cuenta-datos">
          <dt>Documento</dt><dd><?= e(nombreTipoRegistro($r['tipo'], 'documento')) ?></dd>
          <dt>Fecha</dt><dd><?= e(date('d/m/Y H:i', strtotime($r['fecha']))) ?></dd>
          <dt>Firmó</dt><dd><?= e($r['autor_nombre']) ?><?= $r['autor_cedula'] ? ' · Cédula ' . e($r['autor_cedula']) : '' ?></dd>
          <dt>Expediente</dt><dd><a href="<?= URL_BASE ?>/pacientes/evento.php?id=<?= (int) $r['id'] ?>"><?= e($r['folio']) ?> · ver el documento guardado</a></dd>
        </dl>
      </div>
    <?php elseif (isset($resultado['no'])): ?>
      <h2 class="seccion__titulo"><?= iconoRelleno('escudo') ?> No coincide</h2>
      <div class="seccion__cuerpo"><p>Ningún documento de esta clínica tiene esa huella. Revisa que la escribiste completa; si es correcta, el documento pudo ser alterado o es de otra clínica.</p></div>
    <?php else: ?>
      <div class="seccion__cuerpo"><p><?= e($resultado['error']) ?></p></div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
