<?php
/**
 * Espacio para escribir debajo de una sección (Pacientes, Agenda, expediente), con el mismo editor por bloques
 * de las notas: «/» para comandos, Espacio o Ctrl+J para preguntar a la IA. La nota se crea al escribir por primera vez.
 */
require_once __DIR__ . '/../../servicios/espacio/secciones.php';

function notasDeSeccion(string $seccion): void
{
    if (!puedeUsarSeccion($seccion)) {
        return;
    }
    $pagina   = paginaDeSeccion($seccion);
    $bloques  = $pagina ? (json_decode((string) $pagina['contenido'], true) ?: []) : [];
    $editable = $pagina ? empty($pagina['eliminado_en']) : true;
    [$base]   = partesSeccion($seccion);
    $nombre   = $base === 'expediente' ? 'Notas del expediente' : SECCIONES_NOTAS[$base][0];
    $json     = json_encode($bloques, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    ?>
<section class="notas-seccion" id="notas-seccion" aria-label="<?= e($nombre) ?>">
  <div class="notas-seccion__hoja" data-hoja-seccion data-editable="<?= $editable ? '1' : '0' ?>" data-version="<?= (int) ($pagina['version'] ?? 0) ?>">
    <script type="application/json" id="editor-datos"><?= $json ?></script>
    <div class="editor" data-editor-bloques data-pagina="<?= (int) ($pagina['id'] ?? 0) ?>" data-seccion="<?= e($seccion) ?>" aria-label="<?= e($nombre) ?>"></div>
  </div>
</section>
    <?php
}
