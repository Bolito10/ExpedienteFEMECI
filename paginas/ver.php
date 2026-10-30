<?php
/**
 * Página del espacio de trabajo: nota (editor por bloques), carpeta (lo que contiene) o
 * base de datos (tabla editable). El acceso se calcula en app/servicios/espacio/paginas.php.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/espacio/paginas.php';

$usuarioId = (int) usuarioActual()['id'];
$pagina    = cargarPagina((int) ($_GET['id'] ?? 0), $usuarioId);
if (!$pagina) {
    mensajeFlash('error', 'La página ya no existe (pudo moverse a la papelera) o no tienes acceso.');
    redirigir('/');
}
$id = (int) $pagina['id'];
if ($pagina['seccion'] !== null) {   // las notas de sección se ven y editan en su sección
    require_once __DIR__ . '/../app/servicios/espacio/secciones.php';
    redirigir(rutaDeSeccion($pagina['seccion']));
}

$vista = db()->prepare(
    "SELECT 1 FROM bitacora WHERE tabla = 'paginas' AND registro_id = ? AND usuario_id = ? AND accion = 'acceso'
        AND campo = 'vista' AND creado_en > NOW() - INTERVAL 30 MINUTE LIMIT 1"
);
$vista->execute([$id, $usuarioId]);
if (!$vista->fetchColumn()) {
    registrarBitacora('paginas', $id, 'acceso', 'vista');
}

$favorita = db()->prepare('SELECT 1 FROM paginas_favoritas WHERE usuario_id = ? AND pagina_id = ?');
$favorita->execute([$usuarioId, $id]);
$pagina['favorita'] = (bool) $favorita->fetchColumn();
if ($pagina['eliminado_por']) {
    $quien = db()->prepare('SELECT nombre FROM usuarios WHERE id = ?');
    $quien->execute([$pagina['eliminado_por']]);
    $pagina['eliminado_por_nombre'] = $quien->fetchColumn() ?: null;
}

$editable  = nivelAlcanza($pagina, 'editar') && !$pagina['en_papelera'];
$sinTitulo = TIPOS_PAGINA[$pagina['tipo']]['nueva'];
$titulo    = $pagina['titulo'] !== '' ? $pagina['titulo'] : $sinTitulo;

$migas = [];
foreach (rutaPagina($id, $usuarioId) as $p) {
    $migas[] = $p['id'] === $id
        ? ['texto' => $titulo]
        : ['texto' => $p['titulo'] !== '' ? $p['titulo'] : TIPOS_PAGINA[$p['tipo']]['nueva'], 'url' => '/paginas/ver.php?id=' . $p['id']];
}

$hijos = hijosDePagina($id, $usuarioId);

if ($pagina['tipo'] === 'base_datos') {
    $filas = db()->prepare('SELECT id, valores FROM pagina_filas WHERE pagina_id = ? ORDER BY orden, id');
    $filas->execute([$id]);
    $datosBd = [
        'columnas' => columnasDe($pagina),
        'filas'    => array_map(fn ($f) => ['id' => (int) $f['id'], 'valores' => json_decode($f['valores'], true) ?: new stdClass()], $filas->fetchAll()),
        'tipos'    => TIPOS_COLUMNA,
    ];
}

$tituloPagina   = $titulo;
$paginaActual   = $pagina;
$clavePresencia = 'pagina:' . $id;
$mostrarBanner  = false;
$claseContenido = 'pagina--hoja' . ($pagina['tipo'] === 'base_datos' ? ' pagina--hoja-ancha' : '');
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$json = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>

<?php if ($pagina['portada']): ?>
  <div class="portada" style="--portada: url(<?= e(URL_BASE . '/' . $pagina['portada']) ?>);<?= $pagina['portada_alto'] ? ' --alto: ' . (int) $pagina['portada_alto'] . 'px;' : '' ?>" role="img" aria-label="Portada">
    <?php if ($editable): ?>
      <div class="portada__acciones">
        <button type="button" data-portada-cambiar data-destino="pagina" data-id="<?= $id ?>" data-tiene="1"><?= icono('imagen') ?> Cambiar portada</button>
        <?php if ($pagina['portada_alto']): ?>
          <button type="button" data-portada-alto-original data-destino="pagina" data-id="<?= $id ?>">Tamaño predeterminado</button>
        <?php endif; ?>
        <button type="button" data-portada-quitar data-destino="pagina" data-id="<?= $id ?>">Quitar</button>
      </div>
      <div class="portada__tirador" data-portada-tirador data-destino="pagina" data-id="<?= $id ?>" role="separator" aria-orientation="horizontal" aria-label="Arrastra para cambiar el alto de la portada" tabindex="0"></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<article class="hoja<?= $pagina['portada'] ? ' hoja--con-portada' : '' ?>" data-hoja data-editable="<?= $editable ? '1' : '0' ?>" data-version="<?= (int) $pagina['version'] ?>">
  <?php if ($editable && !$pagina['portada']): ?>
    <div class="hoja__opciones-titulo">
      <button type="button" data-portada-cambiar data-destino="pagina" data-id="<?= $id ?>"><?= icono('imagen') ?> Agregar portada</button>
    </div>
  <?php endif; ?>
  <h1 class="hoja__titulo" data-hoja-titulo data-placeholder="<?= e($sinTitulo) ?>"<?= $editable ? ' contenteditable="plaintext-only" spellcheck="true"' : '' ?>><?= e($pagina['titulo']) ?></h1>

  <?php if ($pagina['tipo'] === 'nota'): ?>
    <script type="application/json" id="editor-datos"><?= $json(json_decode((string) $pagina['contenido'], true) ?: []) ?></script>
    <div class="editor" data-editor-bloques aria-label="Contenido de la página"></div>

  <?php elseif ($pagina['tipo'] === 'base_datos'): ?>
    <script type="application/json" id="bdp-datos"><?= $json($datosBd) ?></script>
    <div class="bdp" data-bdp></div>
  <?php endif; ?>

  <?php if ($hijos || $pagina['tipo'] === 'carpeta'): ?>
    <!-- Lo que contiene esta página (carpetas, notas y bases de datos) -->
    <ul class="hoja__hijos" data-hoja-hijos>
      <?php foreach ($hijos as $h): ?>
        <li>
          <a href="<?= URL_BASE ?>/paginas/ver.php?id=<?= $h['id'] ?>">
            <?= iconoRelleno(TIPOS_PAGINA[$h['tipo']]['icono']) ?>
            <span class="hoja__hijo-titulo"><?= e($h['titulo'] !== '' ? $h['titulo'] : TIPOS_PAGINA[$h['tipo']]['nueva']) ?></span>
            <span class="hoja__hijo-fecha"><?= e(hace($h['actualizado_en'])) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($editable && $hijos): ?>
      <button type="button" class="hoja__agregar" data-pagina-menu-nueva data-padre="<?= $id ?>"><?= icono('agregar') ?> Agregar página</button>
    <?php endif; ?>
  <?php endif; ?>
</article>

<?php if ($editable && $pagina['tipo'] !== 'base_datos'): ?>
  <!-- Página vacía: "Elige una opción" (como en la referencia). Se oculta en cuanto hay contenido. -->
  <div class="opciones-vacia" data-opciones-vacia hidden>
    <span class="opciones-vacia__titulo">Elige una opción</span>
    <div class="opciones-vacia__lista">
      <?php if ($pagina['tipo'] === 'nota'): ?>
        <?php if (puede('asistente')): ?>
          <button type="button" data-opcion="ia"><?= iconoRelleno('chispa') ?> Preguntar a la IA</button>
        <?php endif; ?>
        <button type="button" data-opcion="anotador"><?= iconoRelleno('microfono') ?> Anotador con IA</button>
        <button type="button" data-opcion="base_datos"><?= iconoRelleno('tabla') ?> Base de datos</button>
        <div class="ancla-popover">
          <button type="button" data-opcion="plantillas" aria-haspopup="menu" aria-expanded="false"><?= iconoRelleno('plantilla') ?> Plantillas</button>
          <div class="menu-mas__lista menu-mas__lista--arriba" role="menu" data-plantillas hidden>
            <?php foreach (PLANTILLAS_NOTA as $clave => $plantilla): ?>
              <button type="button" role="menuitem" data-plantilla="<?= e($clave) ?>"><?= icono('documento') ?> <span><?= e($plantilla['titulo']) ?></span><small><?= e($plantilla['norma']) ?></small></button>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="ancla-popover">
          <button type="button" class="opciones-vacia__mas" data-opcion="mas" aria-haspopup="menu" aria-expanded="false" title="Más opciones"><?= icono('mas') ?></button>
          <div class="menu-mas__lista menu-mas__lista--arriba" role="menu" data-opciones-mas hidden>
            <button type="button" role="menuitem" data-importar><?= icono('pdf') ?> Importar archivo de texto</button>
            <button type="button" role="menuitem" data-pagina-nueva="nota" data-padre="<?= $id ?>"><?= icono('documento') ?> Página dentro</button>
            <button type="button" role="menuitem" data-pagina-nueva="carpeta" data-padre="<?= $id ?>"><?= icono('carpeta') ?> Carpeta dentro</button>
          </div>
        </div>
        <input type="file" accept=".txt,.md,text/plain,text/markdown" data-importar-archivo hidden>
      <?php else: ?>
        <button type="button" data-pagina-nueva="nota" data-padre="<?= $id ?>"><?= iconoRelleno('documento') ?> Página</button>
        <button type="button" data-pagina-nueva="carpeta" data-padre="<?= $id ?>"><?= iconoRelleno('carpeta') ?> Carpeta</button>
        <button type="button" data-pagina-nueva="base_datos" data-padre="<?= $id ?>"><?= iconoRelleno('tabla') ?> Base de datos</button>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
