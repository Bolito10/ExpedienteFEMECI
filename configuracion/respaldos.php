<?php
/**
 * Respaldos de la base de datos (quien administra el servidor), con el mismo aspecto que Personas y permisos:
 * vistas Respaldos · Integridad; respaldar ahora, descargar, verificar archivo, CHECK TABLE y restaurar.
 * La lógica vive en app/servicios/sistema/respaldos.php y api/configuracion/respaldos.php.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
exigirPermiso('configurar');
if (!puedeAdministrarRespaldos()) {
    mensajeFlash('error', 'Solo quien administra el servidor puede ver los respaldos.');
    redirigir('/configuracion/personas.php');
}

$respaldos = listarRespaldos();
$ultimo    = fechaUltimoRespaldo();

$tituloPagina   = textoEditable('respaldos_titulo', 'Respaldos');
$seccionActiva  = 'personas';
$clavePresencia = 'configuracion:respaldos';
$migas          = [['texto' => 'Configuración', 'url' => '/configuracion/'], ['texto' => $tituloPagina]];
$bannerAlto     = true;
$claseContenido = 'pagina--ancha';
$notificacion   = tomarFlash();
$mostrarBanner  = false;
$claseContenido = 'pagina--configuracion';
$bannerAlto     = false;
require_once __DIR__ . '/../app/vistas/componentes/configuracion_marco.php';
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
abrirMarcoConfiguracion('respaldos', true);
?>

<div class="pagina__cabecera">
  <h1 class="pagina__titulo"<?= atributosEditable('respaldos_titulo', 'Respaldos') ?>><?= e($tituloPagina) ?></h1>
  <p class="pagina__subtitulo"<?= atributosEditable('respaldos_subtitulo', 'Copias diarias de la base de datos') ?>><?= e(textoEditable('respaldos_subtitulo', 'Copias diarias de la base de datos')) ?></p>
</div>

<script type="application/json" id="respaldos-datos"><?= json_encode([
    'restaurar' => restaurarDisponible(), 'conservar' => RESPALDOS_CONSERVAR,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<section class="bd personas respaldos" data-respaldos>
  <div class="bd__cabecera">
    <div class="personas__vistas" role="tablist" aria-label="Vistas">
      <button type="button" role="tab" class="personas__vista activa" aria-selected="true" data-vista="respaldos"><?= iconoRelleno('carpeta') ?> Respaldos <span data-respaldos-cuenta><?= count($respaldos) ?></span></button>
      <button type="button" role="tab" class="personas__vista" aria-selected="false" data-vista="integridad"><?= iconoRelleno('escudo') ?> Integridad</button>
    </div>
    <div class="bd__herramientas">
      <button type="button" class="boton boton--sutil" data-respaldos-integridad><?= icono('escudo_ok') ?> Verificar integridad</button>
      <button type="button" class="boton boton--primario bd__nuevo" data-respaldos-ahora><?= icono('agregar') ?> Respaldar ahora</button>
    </div>
  </div>

  <!-- Respaldos guardados en privado/respaldos (el nombre lleva la fecha) -->
  <div class="bd__marco" data-vista-panel="respaldos">
    <table class="bd-tabla personas__tabla">
      <thead>
        <tr>
          <th scope="col"><button type="button"><?= icono('calendario') ?> Fecha</button></th>
          <th scope="col"><button type="button"><?= icono('seleccion') ?> Origen</button></th>
          <th scope="col"><button type="button"><?= icono('numero') ?> Tamaño</button></th>
          <th scope="col"><button type="button"><?= icono('huella') ?> SHA-256</button></th>
          <th scope="col" class="personas__acciones-col"><span class="sr-only">Acciones</span></th>
        </tr>
      </thead>
      <tbody data-respaldos-lista>
        <?php foreach ($respaldos as $r): ?>
          <tr data-archivo="<?= e($r['archivo']) ?>">
            <td><span class="personas__persona"><span class="personas__icono"><?= icono('carpeta') ?></span><span class="personas__nombre"><?= e(fechaCorta($r['fecha']) . ' · ' . substr($r['fecha'], 11, 5)) ?></span></span></td>
            <td class="personas__gris"><?= e(MOTIVOS_RESPALDO[$r['motivo']]) ?></td>
            <td class="personas__gris"><?= e(tamanoLegible($r['bytes'])) ?></td>
            <td class="personas__gris respaldos__suma" title="<?= e($r['sha256'] ?? '') ?>"><?= e($r['sha256'] ? substr($r['sha256'], 0, 16) . '…' : 'Sin suma') ?></td>
            <td class="personas__acciones-col"><button type="button" class="personas__mas" data-respaldo-menu title="Acciones"><?= icono('mas') ?></button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="bdp__vacio" data-respaldos-vacio<?= $respaldos ? ' hidden' : '' ?>>Aún no hay respaldos. El primero se hace solo hoy, o con Respaldar ahora.</p>
    <p class="personas__nota" data-respaldos-nota>
      <?= $ultimo === date('Y-m-d') ? 'El respaldo de hoy ya está hecho.' : 'El respaldo de hoy se hará en segundo plano con la próxima visita.' ?>
      Se conservan los <?= RESPALDOS_CONSERVAR ?> más recientes en privado/respaldos.
      <?= restaurarDisponible() ? '' : 'Para restaurar desde aquí falta configurar el usuario de respaldos de MySQL (docs/respaldos.md).' ?>
    </p>
  </div>

  <!-- CHECK TABLE de cada tabla: se llena al verificar -->
  <div class="bd__marco" data-vista-panel="integridad" hidden>
    <table class="bd-tabla personas__tabla">
      <thead>
        <tr>
          <th scope="col"><button type="button"><?= icono('tabla') ?> Tabla</button></th>
          <th scope="col"><button type="button"><?= icono('seleccion') ?> Estado</button></th>
          <th scope="col"><button type="button"><?= icono('info') ?> Detalle</button></th>
        </tr>
      </thead>
      <tbody data-integridad-lista></tbody>
    </table>
    <p class="bdp__vacio" data-integridad-vacio>Usa Verificar integridad para revisar todas las tablas con CHECK TABLE.</p>
  </div>
</section>

<?php cerrarMarcoConfiguracion(); ?>
<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
