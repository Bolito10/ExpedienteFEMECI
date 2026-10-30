<?php
/**
 * Transcriptor por voz del formulario de registro: barra flotante + panel con la transcripción.
 * Está oculto hasta que se pide con Alt+Shift+D o con el botón "Dictar" de la cabecera del formulario.
 * La voz se procesa en el servicio local de IA (Whisper + extractor); ningún dato sale del equipo.
 */
?>
<div class="dictado" data-dictado data-estado="inactivo" hidden>
  <section class="dictado__panel" data-dictado-panel hidden aria-label="Transcripción del dictado">
    <div class="dictado__hoja">
      <p class="dictado__texto" data-dictado-texto aria-live="polite"></p>
      <div class="dictado__estado">
        <span class="dictado__estado-texto"><?= icono('onda') ?><span data-dictado-estado>Escuchando</span></span>
        <span class="dictado__calificar" data-dictado-calificar hidden>
          <button type="button" class="boton-icono" data-calificacion="1" title="El dictado fue útil" aria-pressed="false"><?= icono('pulgar_arriba') ?></button>
          <button type="button" class="boton-icono" data-calificacion="0" title="El dictado no fue útil" aria-pressed="false"><?= icono('pulgar_abajo') ?></button>
        </span>
      </div>
    </div>
    <div class="dictado__pie-panel">
      <button type="button" class="boton-icono" data-dictado-minimizar title="Minimizar"><?= icono('contraer') ?></button>
      <button type="button" class="dictado__nuevo" data-dictado-nuevo>Nuevo dictado <kbd class="tecla">Alt ⇧ D</kbd></button>
      <button type="button" class="boton-icono" data-dictado-cerrar title="Cerrar"><?= icono('cerrar') ?></button>
    </div>
  </section>

  <div class="dictado__barra">
    <button type="button" class="boton-icono" data-dictado-expandir title="Ver transcripción"><?= icono('expandir') ?></button>
    <span class="dictado__puntos" data-dictado-puntos aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>
    <span class="dictado__accion">
      <span class="dictado__globo" data-dictado-globo>Dictar</span>
      <button type="button" class="dictado__boton" data-dictado-boton aria-label="Iniciar dictado">
        <?= icono('microfono', 'dictado__icono-mic') ?><span class="dictado__cuadro" aria-hidden="true"></span>
      </button>
    </span>
  </div>
</div>
