<?php
/**
 * Sección "Sincronización" de la configuración (⚙), solo para el administrador.
 * La maneja assets/js/modulos/sincronizacion.js: se guarda con el botón Guardar de la configuración
 * y "Sincronizar ahora" envía lo pendiente al servidor central de inmediato.
 */
if (!puede('configurar')) {
    return;
}
?>
<div data-sinc-ajustes>
  <h3 class="ajustes-ia__seccion">Sincronización</h3>
  <div class="ajustes-ia__fila ajustes-ia__fila--apilada">
    <div class="ajustes-ia__etiqueta"><b>Servidor central</b><small>Dirección de la instalación que recibe lo capturado aquí. Vacía, este equipo trabaja solo.</small></div>
    <div class="campo-ia">
      <?= icono('enlace') ?>
      <input type="url" spellcheck="false" autocomplete="off" data-sinc-url aria-label="Dirección del servidor central">
    </div>
  </div>
  <div class="ajustes-ia__fila ajustes-ia__fila--apilada">
    <div class="ajustes-ia__etiqueta"><b>Token compartido</b><small data-sinc-token-ayuda>El mismo aquí y en el servidor central. Se guarda fuera del alcance web.</small></div>
    <div class="campo-ia">
      <?= icono('candado') ?>
      <input type="password" spellcheck="false" autocomplete="new-password" data-sinc-token aria-label="Token compartido">
      <button type="button" class="campo-ia__boton" data-sinc-generar title="Generar un token nuevo"><?= icono('reabrir') ?></button>
      <button type="button" class="campo-ia__boton" data-sinc-ver title="Mostrar u ocultar el token"><?= icono('ojo') ?></button>
    </div>
  </div>
  <dl class="ajustes-ia__uso" data-sinc-estado></dl>
  <p class="ajustes-ia__destino" data-sinc-error hidden></p>
  <div class="ajustes-ia__fila">
    <div class="ajustes-ia__etiqueta"><b>Sincronizar ahora</b><small data-sinc-resumen>Se envía solo cuando hay conexión; aquí puedes forzarlo.</small></div>
    <button type="button" class="boton boton--sutil" data-sinc-ahora>Sincronizar ahora</button>
  </div>
</div>
